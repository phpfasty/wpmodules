<?php

/**
 * Declarative field: type → sanitizer, input type, meta type.
 * Storage is typed: integer → int, number → float, boolean → bool, money → int minor units (cents),
 * everything else → string. The admin form shows money as a decimal (12.50) and stores cents (1250).
 * date → ISO calendar date string `YYYY-MM-DD` (no time, no timezone).
 * Digital-goods kinds (types 0.2): enum (closed `options`), semver (`1`, `1.2`, `1.2.3`, `1.2.3-beta.1`),
 * file (attachment id; private: never in REST nor in the public view-model), user (author id),
 * license_tiers (JSON list of {tier, label, price} with price in integer minor units; admin form: `tier|Label|12.50`).
 *
 * @package Latte
 */

declare(strict_types=1);

namespace PhpFasty\Module\Types\Model;

final class Field
{
    public const TYPES = ['string', 'text', 'url', 'email', 'integer', 'number', 'money', 'boolean', 'date', 'enum', 'semver', 'file', 'user', 'license_tiers'];

    /** REST schema pattern of a `semver` field value. */
    public const SEMVER_PATTERN = '^\\d+(\\.\\d+){0,2}(-[0-9A-Za-z.-]+)?$';

    /** At most this many license tiers per field. */
    public const MAX_TIERS = 5;

    /** REST schema pattern of a `date` field value. */
    public const DATE_PATTERN = '^\\d{4}-\\d{2}-\\d{2}$';

    /**
     * @param array<string, mixed> $def
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly string $metaKey,
        private readonly mixed $sanitize = null,
        public readonly array $def = [],
    ) {
    }

    /**
     * @param array<string, mixed> $def
     */
    public static function fromArray(string $name, array $def, string $prefix): self
    {
        $type = (string) ($def['type'] ?? 'string');
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown field type "%s" for "%s"', $type, $name));
        }
        if ($type === 'enum' && ($def['options'] ?? []) === []) {
            throw new \InvalidArgumentException(sprintf('Field "%s" of type enum needs options', $name));
        }
        return new self(
            $name,
            $type,
            (string) ($def['label'] ?? $name),
            (string) ($def['meta_key'] ?? '_' . $prefix . '_' . $name),
            $def['sanitize'] ?? null,
            $def,
        );
    }

    public function sanitize(mixed $raw): string
    {
        if (is_callable($this->sanitize)) {
            return (string) call_user_func($this->sanitize, $raw);
        }
        $raw = is_scalar($raw) ? trim((string) $raw) : '';
        return match ($this->type) {
            'text' => function_exists('sanitize_textarea_field') ? sanitize_textarea_field($raw) : wp_strip_all_tags($raw),
            'url' => self::url($raw),
            'email' => function_exists('sanitize_email')
                ? sanitize_email($raw)
                : (string) filter_var($raw, FILTER_SANITIZE_EMAIL),
            'integer' => $raw === '' || !is_numeric($raw) ? '' : (string) (int) $raw,
            'number' => self::number($raw),
            'money' => self::number($raw, true),
            'boolean' => in_array(strtolower($raw), ['1', 'on', 'yes', 'true'], true) ? '1' : '',
            'date' => self::date($raw),
            'semver' => self::semver($raw),
            'file' => self::attachment($raw),
            'user' => self::user($raw),
            'license_tiers' => self::tiersJson(self::tiers($raw, true)),
            default => self::option(
                function_exists('sanitize_text_field') ? sanitize_text_field($raw) : wp_strip_all_tags($raw),
                $this->options(),
            ),
        };
    }

    /**
     * Closed value set of the field (site manifest `options`; Codex type select); [] = any value.
     *
     * @return list<string>
     */
    public function options(): array
    {
        return array_values(array_map('strval', (array) ($this->def['options'] ?? [])));
    }

    /** @param list<string> $options */
    private static function option(string $value, array $options): string
    {
        return $options === [] || in_array($value, $options, true) ? $value : '';
    }

    /** register_post_meta() type for this field. */
    public function metaType(): string
    {
        return match ($this->type) {
            'integer', 'money', 'file', 'user' => 'integer',
            'number' => 'number',
            'boolean' => 'boolean',
            default => 'string',
        };
    }

    /** Admin form value (money as a decimal) → typed storage value; null when empty (meta is deleted). */
    public function fromInput(mixed $raw): int|float|bool|string|null
    {
        $clean = $this->sanitize($raw);
        if ($this->type === 'boolean') {
            return $clean === '1';
        }
        if ($clean === '') {
            return null;
        }
        return match ($this->type) {
            'money' => (int) round(((float) $clean) * 100),
            'integer', 'file', 'user' => (int) $clean,
            'number' => (float) $clean,
            default => $clean,
        };
    }

    /**
     * REST / programmatic value (money already in cents) → typed storage value. Used as the meta sanitize_callback.
     * A non-numeric value of a numeric field is "no value" (null), never 0: an empty sale price stays unset.
     * Money may be negative (refunds, discounts); integer and number stay non-negative.
     */
    public function toStorage(mixed $value): int|float|bool|string|null
    {
        $num = is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)));
        return match ($this->type) {
            'money' => $num ? (int) round((float) $value) : null,
            'integer' => $num ? max(0, (int) round((float) $value)) : null,
            'number' => $num ? max(0.0, round((float) $value, 2)) : null,
            'boolean' => in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true),
            'file' => ($v = self::attachment(is_scalar($value) ? (string) $value : '')) === '' ? null : (int) $v,
            'user' => ($v = self::user(is_scalar($value) ? (string) $value : '')) === '' ? null : (int) $v,
            'license_tiers' => ($v = self::tiersJson(self::tiers($value, false))) === '' ? null : $v,
            default => $this->sanitize($value),
        };
    }

    /** Stored value → admin form value (money cents → decimal string). */
    public function toInput(mixed $stored): string
    {
        if ($stored === '' || $stored === null) {
            return '';
        }
        return match ($this->type) {
            'money' => number_format(((int) $stored) / 100, 2, '.', ''),
            'boolean' => $stored ? '1' : '',
            'license_tiers' => implode("\n", array_map(
                static fn (array $t): string => $t['tier'] . '|' . $t['label'] . '|' . number_format($t['price'] / 100, 2, '.', ''),
                self::tiers($stored, false)
            )),
            default => (string) $stored,
        };
    }

    /** Private fields (default: every `file`) never reach REST or the public view-model. */
    public function isPrivate(): bool
    {
        return (bool) ($this->def['private'] ?? $this->type === 'file');
    }

    /** Version `1`, `1.2`, `1.2.3`, optional pre-release `-beta.1`; '' otherwise. */
    public static function semver(string $raw): string
    {
        $raw = ltrim(trim($raw), 'vV');
        return preg_match('/' . self::SEMVER_PATTERN . '/', $raw) === 1 && strlen($raw) <= 64 ? $raw : '';
    }

    /** Attachment id (an existing attachment when WordPress is loaded); '' otherwise. */
    private static function attachment(string $raw): string
    {
        $id = ctype_digit($raw) ? (int) $raw : 0;
        if ($id <= 0) {
            return '';
        }
        if (function_exists('get_post_type') && get_post_type($id) !== 'attachment') {
            return '';
        }
        return (string) $id;
    }

    /** User id (an existing user when WordPress is loaded); '' otherwise. */
    private static function user(string $raw): string
    {
        $id = ctype_digit($raw) ? (int) $raw : 0;
        if ($id <= 0) {
            return '';
        }
        if (function_exists('get_userdata') && get_userdata($id) === false) {
            return '';
        }
        return (string) $id;
    }

    /**
     * License tiers from a stored JSON string / array (prices in cents) or, when $decimal, from the admin textarea
     * (`tier|Label|12.50` per line, prices as decimals). Invalid rows are dropped; tier slugs are unique.
     *
     * @return list<array{tier: string, label: string, price: int}>
     */
    public static function tiers(mixed $raw, bool $decimal = false): array
    {
        $rows = [];
        if (is_string($raw) && $raw !== '' && $raw[0] === '[') {
            $decoded = json_decode($raw, true);
            $rows = is_array($decoded) ? $decoded : [];
            $decimal = false;
        } elseif (is_string($raw)) {
            foreach (preg_split('/\R/', $raw) ?: [] as $line) {
                $parts = array_map('trim', explode('|', $line));
                if (count($parts) === 3) {
                    $rows[] = ['tier' => $parts[0], 'label' => $parts[1], 'price' => $parts[2]];
                }
            }
        } elseif (is_array($raw)) {
            $rows = $raw;
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($out) >= self::MAX_TIERS) {
                continue;
            }
            $tier = strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', (string) ($row['tier'] ?? '')));
            $label = trim(function_exists('wp_strip_all_tags') ? wp_strip_all_tags((string) ($row['label'] ?? '')) : strip_tags((string) ($row['label'] ?? '')));
            $price = $row['price'] ?? '';
            $cents = $decimal
                ? (self::number(is_scalar($price) ? (string) $price : '') === '' ? -1 : (int) round((float) self::number((string) $price) * 100))
                : (is_numeric($price) ? (int) round((float) $price) : -1);
            if ($tier === '' || $cents <= 0 || isset($out[$tier])) {
                continue;
            }
            $out[$tier] = ['tier' => $tier, 'label' => $label === '' ? ucfirst($tier) : $label, 'price' => $cents];
        }
        return array_values($out);
    }

    /** @param list<array{tier: string, label: string, price: int}> $tiers */
    private static function tiersJson(array $tiers): string
    {
        return $tiers === [] ? '' : (string) json_encode($tiers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function inputType(): string
    {
        if ($this->options() !== []) {
            return 'select';
        }
        return match ($this->type) {
            'url' => 'url',
            'email' => 'email',
            'integer', 'number', 'money', 'file', 'user' => 'number',
            'license_tiers' => 'textarea',
            'boolean' => 'checkbox',
            'date' => 'date',
            default => 'text',
        };
    }

    /** Valid calendar date `YYYY-MM-DD` (a leading date of a longer ISO string is accepted); '' otherwise. */
    public static function date(string $raw): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|[T ])/', $raw, $m) !== 1) {
            return '';
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : '';
    }

    private static function url(string $raw): string
    {
        if (function_exists('esc_url_raw')) {
            return esc_url_raw($raw);
        }
        return filter_var($raw, FILTER_VALIDATE_URL) ? $raw : '';
    }

    /** Decimal with max 2 places, comma accepted; non-negative unless $signed (money); '' when empty/invalid. */
    public static function number(string $raw, bool $signed = false): string
    {
        $raw = str_replace(',', '.', $raw);
        if ($raw === '' || !is_numeric($raw)) {
            return '';
        }
        $n = round((float) $raw, 2);
        return (string) ($signed ? $n : max(0, $n));
    }
}
