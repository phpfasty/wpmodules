<?php

/**
 * One declarative post-type module (`types/<name>.php`).
 */

declare(strict_types=1);

namespace PhpFasty\Module\Types\Domain;

final class PostType
{
    /** @var array<string, Field> */
    public readonly array $fields;

    /**
     * @param array<string, mixed> $def module definition array
     */
    public function __construct(public readonly string $name, public readonly array $def)
    {
        $prefix = Scalar::str($def['meta_prefix'] ?? $name);
        $fields = [];
        foreach (Scalar::arr($def['fields'] ?? []) as $key => $field) {
            $fields[(string) $key] = Field::fromArray((string) $key, Scalar::map($field), $prefix);
        }
        $this->fields = $fields;
    }

    /**
     * @param array<string, mixed> $def
     */
    public static function fromArray(array $def): self
    {
        $name = Scalar::str($def['name'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]{1,19}$/', $name)) {
            throw new \InvalidArgumentException(sprintf('Invalid post type name "%s"', $name));
        }

        return new self($name, $def);
    }

    /** @return array<string, mixed> */
    public function args(): array
    {
        return Scalar::map($this->def['args'] ?? []);
    }

    /** @return array<string, array<string, mixed>> */
    public function taxonomies(): array
    {
        return array_map(Scalar::map(...), Scalar::map($this->def['taxonomies'] ?? []));
    }

    public function supports(string $capability): bool
    {
        $supports = (array) ($this->def['supports'] ?? []);

        return in_array($capability, $supports, true) || array_key_exists($capability, $supports);
    }

    /** @return array<string, mixed> */
    public function capability(string $capability): array
    {
        $value = ((array) ($this->def['supports'] ?? []))[$capability] ?? [];

        return Scalar::map($value);
    }

    public function view(string $kind): ?string
    {
        $view = ((array) ($this->def['views'] ?? []))[$kind] ?? null;

        return is_string($view) && '' !== $view ? $view : null;
    }

    /** @return array<string, mixed> */
    public function archive(): array
    {
        return Scalar::map($this->def['archive'] ?? []);
    }

    /** @return array<string, mixed>|false */
    public function metabox(): array|false
    {
        $box = $this->def['metabox'] ?? [];

        return false === $box ? false : Scalar::map($box);
    }

    public function textDomain(): string
    {
        return Scalar::str($this->def['text_domain'] ?? 'latte');
    }
}
