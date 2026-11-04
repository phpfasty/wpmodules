<?php

/**
 * ModulY module `types` — data side (post types, taxonomies, meta, metaboxes, admin columns). Same behaviour as
 * the wpfasty-types plugin it supersedes while enabled; data migrations are forward-only (Migrations/, ADR-002).
 * Metabox save reads the nonce-checked POST fields:
 *
 * @moduly-request-handler
 */

declare(strict_types=1);

namespace PhpFasty\Module\Types;

use PhpFasty\Module\Types\Model\Field;
use PhpFasty\Module\Types\Model\PostType;
use PhpFasty\Module\Types\Model\Registry;
use PhpFasty\Moduly\Wp\Support\Value;

/**
 * Data side of the module system; the (aliased) Registry hands itself over on `wpfasty_types_loaded`.
 */
final class Data
{
    private static ?Registry $current = null;

    public function __construct(private readonly Registry $types)
    {
    }

    /** The live Registry handed over on `wpfasty_types_loaded` (null before init or when the theme has none). */
    public static function registry(): ?Registry
    {
        return self::$current;
    }

    public static function attach(Registry $types): void
    {
        self::$current = $types;
        $plugin = new self($types);
        add_action('init', static function () use ($types): void {
            Module::migrate($types); // after register (init:10): forward-only, idempotent, locked
        }, 20);
        add_action('init', [$plugin, 'register']);
        add_action('init', [self::class, 'registerCurrency']);
        if (class_exists('WPFastyTypes\\Codex\\Rest')) {
            \WPFastyTypes\Codex\Rest::attach(); // Codex REST stays in the wpfasty-types plugin when it is installed
        }
        if (is_admin()) {
            add_action('admin_init', [self::class, 'currencyField']);
            add_action('add_meta_boxes', [$plugin, 'metaBoxes']);
            add_action('save_post', [$plugin, 'save'], 10, 2);
            add_action('admin_init', [$plugin, 'columns']);
        }
    }

    /**
     * Store currency (ISO 4217) for `money` fields, which hold integer minor units.
     * Settings → General; REST /wp/v2/settings.
     */
    public static function registerCurrency(): void
    {
        register_setting('general', 'wpfasty_currency', [
            'type' => 'string',
            'default' => 'USD',
            'show_in_rest' => ['schema' => ['pattern' => '^[A-Z]{3}$']],
            'sanitize_callback' => [self::class, 'sanitizeCurrency'],
            'description' => __('Currency of money fields (ISO 4217 code, e.g. USD, EUR).', 'moduly'),
        ]);
    }

    public static function sanitizeCurrency(mixed $value): string
    {
        $code = strtoupper(trim(is_scalar($value) ? (string) $value : ''));

        return 1 === preg_match('/^[A-Z]{3}$/', $code) ? $code : 'USD';
    }

    public static function currencyField(): void
    {
        add_settings_field('wpfasty_currency', __('Store currency', 'moduly'), static function (): void {
            printf(
                '<input name="wpfasty_currency" id="wpfasty_currency" type="text" maxlength="3"'
                    .' class="small-text" value="%s"> <span class="description">%s</span>',
                esc_attr(Value::str(get_option('wpfasty_currency', 'USD'))),
                esc_html__('ISO 4217 code, e.g. USD, EUR.', 'moduly')
            );
        }, 'general');
    }

    public function register(): void
    {
        foreach ($this->types->all() as $type) {
            /* @phpstan-ignore argument.type, argument.type (validated name; theme-defined args are passed through to WordPress) */
            register_post_type($type->name, $type->args());
            foreach ($type->taxonomies() as $taxonomy => $args) {
                /* @phpstan-ignore argument.type (theme-defined taxonomy args are passed through to WordPress) */
                register_taxonomy((string) $taxonomy, $type->name, $args);
            }
            if ([] !== $type->fields) {
                // REST exposes `meta` only for post types that support custom-fields
                // (keys are `_`-prefixed, so the core box hides them).
                add_post_type_support($type->name, 'custom-fields');
            }
            if ([] !== $type->fields) {
                add_filter('rest_prepare_'.$type->name, [$this, 'restNullMeta'], 10, 2);
            }
            foreach ($type->fields as $field) {
                register_post_meta($type->name, $field->metaKey, [
                    'type' => $field->metaType(),
                    'single' => true,
                    // Typed values in REST (money = integer minor units).
                    // Opt out per field with 'show_in_rest' => false.
                    'show_in_rest' => self::restSchema($field),
                    'sanitize_callback' => [$field, 'toStorage'],
                    'auth_callback' => static fn (): bool => current_user_can('edit_posts'),
                ]);
            }
        }
    }

    /**
     * REST exposure of a field: private fields never; typed schema where a kind has one.
     *
     * @return bool|array<string, mixed>
     */
    public static function restSchema(Field $field): bool|array
    {
        if ($field->isPrivate() || ($field->def['show_in_rest'] ?? true) === false) {
            return false;
        }

        return match (true) {
            'date' === $field->type => ['schema' => ['pattern' => Field::DATE_PATTERN]],
            'semver' === $field->type => ['schema' => ['pattern' => Field::SEMVER_PATTERN]],
            [] !== $field->options() => ['schema' => ['enum' => $field->options()]],
            default => true,
        };
    }

    /**
     * REST would show a missing integer/number/boolean meta as 0/false; send null instead, so "not set"
     * (e.g. stock = unlimited) differs from 0.
     */
    public function restNullMeta(\WP_REST_Response $response, \WP_Post $post): \WP_REST_Response
    {
        $data = $response->get_data();
        $type = $this->types->get($post->post_type);
        if (null === $type || !is_array($data) || !is_array($data['meta'] ?? null)) {
            return $response;
        }
        foreach ($type->fields as $field) {
            $unset = !metadata_exists('post', $post->ID, $field->metaKey);
            if (array_key_exists($field->metaKey, $data['meta']) && $unset) {
                $data['meta'][$field->metaKey] = null;
            }
        }
        $response->set_data($data);

        return $response;
    }

    public function metaBoxes(string $postType): void
    {
        $type = $this->types->get($postType);
        $box = $type?->metabox();
        if (null === $type || false === $box || [] === $type->fields) {
            return;
        }
        add_meta_box(
            'wpfasty-'.$type->name.'-fields',
            Value::str($box['title'] ?? __('Details', 'moduly')),
            fn (\WP_Post $post) => $this->renderMetaBox($type, $post),
            $type->name,
            Value::str($box['context'] ?? 'side'),
            self::priority($box['priority'] ?? null)
        );
    }

    public function save(int $postId, \WP_Post $post): void
    {
        $type = $this->types->get($post->post_type);
        if (null === $type || [] === $type->fields || false === $type->metabox()) {
            return;
        }
        $key = 'wpfasty_'.$type->name.'_nonce';
        $nonce = isset($_POST[$key]) && is_string($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        if (!wp_verify_nonce($nonce, 'wpfasty_save_'.$type->name)
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || !current_user_can('edit_post', $postId)
        ) {
            return;
        }
        // Each value is sanitized per field type below.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $input = isset($_POST['wpfasty_fields']) && is_array($_POST['wpfasty_fields'])
            ? wp_unslash($_POST['wpfasty_fields'])
            : [];
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        foreach ($type->fields as $name => $field) {
            $value = $field->fromInput(Value::at($input, $name) ?? '');
            if (null === $value) {
                update_post_meta($postId, $field->metaKey, ''); // cleared by the editor; rows are never deleted here
            } else {
                update_post_meta($postId, $field->metaKey, $value);
            }
        }
    }

    /** Admin list columns: module `columns` => [key => ['label' => string, 'value' => callable(int): string]]. */
    public function columns(): void
    {
        foreach ($this->types->all() as $type) {
            $columns = (array) ($type->def['columns'] ?? []);
            if ([] === $columns) {
                continue;
            }
            add_filter('manage_'.$type->name.'_posts_columns', static function (array $c) use ($columns): array {
                $extra = [];
                foreach ($columns as $key => $col) {
                    $extra[Value::str($key)] = Value::str(Value::at($col, 'label') ?? $key);
                }

                return array_slice($c, 0, 2, true) + $extra + $c;
            });
            $hook = 'manage_'.$type->name.'_posts_custom_column';
            add_action($hook, static function (string $col, int $id) use ($columns): void {
                $render = Value::at($columns[$col] ?? null, 'value');
                if (is_callable($render)) {
                    echo esc_html(Value::str($render($id)));
                }
            }, 10, 2);
        }
    }

    private function renderMetaBox(PostType $type, \WP_Post $post): void
    {
        wp_nonce_field('wpfasty_save_'.$type->name, 'wpfasty_'.$type->name.'_nonce');
        foreach ($type->fields as $name => $field) {
            $value = $field->toInput(get_post_meta($post->ID, $field->metaKey, true));
            $id = 'wpfasty-'.$type->name.'-'.$name;
            $input = $field->inputType();
            if ('checkbox' === $input) {
                printf(
                    '<p><label for="%1$s"><input type="checkbox" id="%1$s"'
                        .' name="wpfasty_fields[%2$s]" value="1"%3$s> %4$s</label></p>',
                    esc_attr($id),
                    esc_attr($name),
                    checked($value, '1', false),
                    esc_html($field->label)
                );
                continue;
            }
            if ('select' === $input) {
                $opts = '<option value=""></option>';
                foreach ($field->options() as $option) {
                    $opts .= sprintf(
                        '<option value="%1$s"%2$s>%3$s</option>',
                        esc_attr($option),
                        selected($value, $option, false),
                        esc_html(Value::str(Value::at($field->def['option_labels'] ?? null, $option) ?? $option))
                    );
                }
                printf(
                    '<p><label for="%1$s"><strong>%2$s</strong></label><br>'
                        .'<select class="widefat" id="%1$s" name="wpfasty_fields[%3$s]">%4$s</select></p>',
                    esc_attr($id),
                    esc_html($field->label),
                    esc_attr($name),
                    $opts // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per option above.
                );
                continue;
            }
            if ('textarea' === $input) {
                printf(
                    '<p><label for="%1$s"><strong>%2$s</strong></label><br>'
                        .'<textarea class="widefat" rows="3" id="%1$s" name="wpfasty_fields[%3$s]" placeholder="regular|Regular|49.00">%4$s</textarea></p>',
                    esc_attr($id),
                    esc_html($field->label),
                    esc_attr($name),
                    esc_textarea($value)
                );
                continue;
            }
            $step = in_array($field->type, ['integer', 'file', 'user'], true) ? '1' : '0.01';
            // money may be negative (Q9); integer / number stay >= 0
            $min = 'money' === $field->type ? '' : ' min="0"';
            $extra = 'number' === $input ? $min.' step="'.esc_attr($step).'"' : '';
            printf(
                '<p><label for="%1$s"><strong>%2$s</strong></label><br>'
                    .'<input class="widefat" type="%3$s"%4$s id="%1$s" name="wpfasty_fields[%5$s]" value="%6$s"></p>',
                esc_attr($id),
                esc_html($field->label),
                esc_attr($input),
                $extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr above.
                esc_attr($name),
                esc_attr($value)
            );
        }
    }

    /**
     * @return 'core'|'default'|'high'|'low'
     */
    private static function priority(mixed $value): string
    {
        return match ($value) {
            'core', 'high', 'low' => $value,
            default => 'default',
        };
    }
}
