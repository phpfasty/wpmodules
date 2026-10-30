<?php

/**
 * ModulY module `types`: the Registry of declarative post-type modules (ADR-001 §3), aliased to
 * WPFasty\Types\Registry while the module is enabled (the theme ships the same class as its fallback).
 * Differences to the theme copy: definition files are read by the host (`wp.theme_definitions`, modules never
 * include files) and field reads follow ADR-002 (new meta key, then `aliases`; invalid → default + log).
 *
 * Declarative post-type modules (ADR-001 §3).
 *
 * Child themes drop `types/<name>.php` returning an array. The theme side (this class)
 * only does presentation: view routing, main-query tuning, archive titles and post
 * view-models. Registration of post types/taxonomies/meta and the admin metabox lives in
 * the companion plugin `plugins/wpfasty-types` (wordpress.org: plugin territory, ADR-001 §3a),
 * which receives this registry via the `wpfasty_types_loaded` action.
 *
 * @package Latte
 */

declare(strict_types=1);

namespace PhpFasty\Module\Types\Model;

use WPFasty\Core\BootableServiceInterface;

final class Registry implements BootableServiceInterface
{
    /** @var array<string, PostType> */
    private array $types = [];

    private bool $loaded = false;

    /** @var (callable(list<string>): list<array<mixed>>)|null */
    private static $definitions = null;

    /** @param callable(list<string>): list<array<mixed>> $reader */
    public static function setDefinitions(callable $reader): void
    {
        self::$definitions = $reader;
    }

    /**
     * @param list<string> $dirs Directories scanned for `*.php` modules.
     */
    public function __construct(private array $dirs = [])
    {
    }

    public function boot(): void
    {
        // Load at init:0 so module labels can use __() without early textdomain notices.
        add_action('init', [$this, 'load'], 0);
        add_filter('wpfasty_post_data', [$this, 'postData'], 5, 2);
        add_filter('wpfasty_resolve_view', [$this, 'resolveView'], 5);
        add_action('pre_get_posts', [$this, 'mainQuery']);
        add_filter('post_type_archive_title', [$this, 'archiveTitle'], 10, 2);
        add_filter('get_the_archive_title_prefix', [$this, 'archiveTitlePrefix']);
    }

    /** Loads module files once (dirs filter: `wpfasty_type_dirs`, arrays: `wpfasty_types`). */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $dirs = function_exists('apply_filters')
            ? (array) apply_filters('wpfasty_type_dirs', $this->dirs)
            : $this->dirs;
        $defs = self::$definitions !== null
            ? (self::$definitions)(array_values(array_map('strval', array_unique($dirs))))
            : [];
        if (function_exists('apply_filters')) {
            $defs = (array) apply_filters('wpfasty_types', $defs);
        }
        foreach ($defs as $def) {
            $this->add(PostType::fromArray((array) $def));
        }
        /*
         * Data registration (post types, taxonomies, meta, metaboxes) is plugin territory
         * (wordpress.org rules): the companion plugin `wpfasty-types` listens here.
         */
        if (function_exists('do_action')) {
            do_action('wpfasty_types_loaded', $this);
        }
    }

    public function add(PostType $type): void
    {
        $this->types[$type->name] = $type;
    }

    public function get(string $name): ?PostType
    {
        $this->load();
        return $this->types[$name] ?? null;
    }

    /** @return array<string, PostType> */
    public function all(): array
    {
        $this->load();
        return $this->types;
    }

    public function supports(string $postType, string $capability): bool
    {
        return $this->get($postType)?->supports($capability) ?? false;
    }

    public function value(int $postId, string $field): string
    {
        $type = $this->get((string) get_post_type($postId));
        $def = $type?->fields[$field] ?? null;
        return $def ? self::read($postId, $def) : '';
    }

    /**
     * ADR-002 read: the field's meta key, then its `aliases` (old keys after a kind/key change); a stored value the
     * field type cannot hold reads as "" (default) and is logged — the stored value is never touched.
     */
    public static function read(int $postId, Field $field): string
    {
        // An existing new key wins even when empty (cleared by the editor): aliases are read only before backfill.
        $keys = metadata_exists('post', $postId, $field->metaKey)
            ? [$field->metaKey]
            : array_values(array_map('strval', (array) ($field->def['aliases'] ?? [])));
        $value = \PhpFasty\Moduly\Migration\FieldValue::read(
            static function (string $key) use ($postId): ?string {
                if (!metadata_exists('post', $postId, $key)) {
                    return null;
                }
                return (string) get_post_meta($postId, $key, true);
            },
            $keys,
            static fn (mixed $raw): bool => self::valid($field, (string) $raw),
            '',
            static function (string $message, array $ctx) use ($postId, $field): void {
                do_action('moduly_types_invalid_value', $postId, $field->name, $ctx['key'] ?? '', $message);
            },
        );
        return (string) $value;
    }

    /** Can the field type hold this stored string? (text kinds always can) */
    public static function valid(Field $field, string $raw): bool
    {
        return match ($field->type) {
            'integer', 'money' => preg_match('/^-?\d+$/', $raw) === 1,
            'number' => is_numeric($raw),
            'boolean' => in_array($raw, ['0', '1', ''], true),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1,
            'file', 'user' => preg_match('/^\d*$/', $raw) === 1,
            'semver' => $raw === '' || Field::semver($raw) === $raw,
            'enum' => $raw === '' || in_array($raw, $field->options(), true),
            'license_tiers' => $raw === '' || Field::tiers($raw) !== [],
            default => true,
        };
    }

    /**
     * Adds `fields` (name → raw string) and `terms` (taxonomy → list) to the post view-model,
     * then lets the module shape it via its `context` callable.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function postData(array $data, \WP_Post $post): array
    {
        $type = $this->get($post->post_type);
        if ($type === null) {
            return $data;
        }
        $data['fields'] = [];
        foreach ($type->fields as $name => $field) {
            if ($field->isPrivate()) {
                continue; // e.g. download_file: only the downloads module reads it, after payment
            }
            $data['fields'][$name] = self::read($post->ID, $field);
        }
        $data['terms'] = [];
        foreach (array_keys($type->taxonomies()) as $taxonomy) {
            $data['terms'][$taxonomy] = [];
            foreach (get_the_terms($post, (string) $taxonomy) ?: [] as $term) {
                $data['terms'][$taxonomy][] = [
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'url' => (string) get_term_link($term),
                ];
            }
        }
        $context = $type->def['context'] ?? null;
        return is_callable($context) ? (array) $context($data, $post, $type) : $data;
    }

    public function resolveView(string $view): string
    {
        foreach ($this->all() as $type) {
            $archive = $type->view('archive');
            if ($archive) {
                $taxonomies = array_keys($type->taxonomies());
                if (is_post_type_archive($type->name) || ($taxonomies !== [] && is_tax($taxonomies))) {
                    return $archive;
                }
            }
            $single = $type->view('single');
            if ($single && is_singular($type->name)) {
                return $single;
            }
        }
        return $view;
    }

    public function mainQuery(\WP_Query $query): void
    {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }
        foreach ($this->all() as $type) {
            $taxonomies = array_keys($type->taxonomies());
            if (!$query->is_post_type_archive($type->name) && ($taxonomies === [] || !$query->is_tax($taxonomies))) {
                continue;
            }
            $archive = $type->archive();
            if (isset($archive['per_page'])) {
                $query->set('posts_per_page', (int) $archive['per_page']);
            }
            if (isset($archive['orderby'])) {
                $query->set('orderby', $archive['orderby']);
            }
        }
    }

    /** Module `archive.title` overrides the post type archive title. */
    public function archiveTitle(string $title, string $postType): string
    {
        $custom = $this->get($postType)?->archive()['title'] ?? null;
        return is_string($custom) && $custom !== '' ? $custom : $title;
    }

    /** Module `archive.prefix` => false drops "Archives:"/"Category:" prefixes on its archives. */
    public function archiveTitlePrefix(string $prefix): string
    {
        foreach ($this->all() as $type) {
            if (($type->archive()['prefix'] ?? true) !== false) {
                continue;
            }
            $taxonomies = array_keys($type->taxonomies());
            if (is_post_type_archive($type->name) || ($taxonomies !== [] && is_tax($taxonomies))) {
                return '';
            }
        }
        return $prefix;
    }

    /**
     * Latest posts of a module type using its archive ordering (for front pages / related lists).
     *
     * @return list<\WP_Post>
     */
    public function latest(string $postType, int $count): array
    {
        $type = $this->get($postType);
        if ($type === null) {
            return [];
        }
        $posts = get_posts([
            'post_type' => $postType,
            'numberposts' => $count,
            'orderby' => $type->archive()['orderby'] ?? ['date' => 'DESC'],
        ]);
        return array_values($posts);
    }

    /**
     * Non-empty terms of a taxonomy plus the currently queried slug (archive filter bars).
     *
     * @return array{items: list<array{name: string, slug: string, url: string}>, current: string}
     */
    public static function termFilter(string $taxonomy): array
    {
        $items = [];
        $terms = taxonomy_exists($taxonomy) ? get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true]) : [];
        foreach (is_array($terms) ? $terms : [] as $term) {
            $items[] = ['name' => $term->name, 'slug' => $term->slug, 'url' => (string) get_term_link($term)];
        }
        $current = is_tax($taxonomy) ? get_queried_object() : null;
        return ['items' => $items, 'current' => $current instanceof \WP_Term ? $current->slug : ''];
    }
}
