<?php

/**
 * Checkout domain (no WordPress): form fields, required fields, validation, displayable error codes.
 */

declare(strict_types=1);

namespace PhpFasty\Module\Orders\Domain;

final class CheckoutPolicy
{
    public const FIELDS = ['name', 'email', 'phone', 'address', 'city', 'postcode', 'country', 'notes', 'payment'];
    public const REQUIRED = ['name', 'email', 'address', 'city', 'postcode', 'country'];
    public const DIGITAL_REQUIRED = ['name', 'email'];
    public const ERRORS = ['name', 'email', 'address', 'city', 'postcode', 'country', 'payment', 'server'];

    /** Error codes other modules may add, e.g. consent_offer. */
    private const EXTRA_ERROR = '/^[a-z][a-z0-9_]{1,31}$/';

    /**
     * Default required fields: a downloads-only cart needs no postal address.
     *
     * @return list<string>
     */
    public static function defaultRequired(bool $digital): array
    {
        return $digital ? self::DIGITAL_REQUIRED : self::REQUIRED;
    }

    /**
     * Known fields only, in the given order.
     *
     * @param list<string> $fields
     *
     * @return list<string>
     */
    public static function knownFields(array $fields): array
    {
        return array_values(array_intersect($fields, self::FIELDS));
    }

    /**
     * Error codes of a sanitized form.
     *
     * @param array<string, string> $form
     * @param list<string>          $required
     * @param list<string>          $paymentMethods
     *
     * @return list<string>
     */
    public static function validate(array $form, array $required, array $paymentMethods): array
    {
        $errors = [];
        foreach ($required as $field) {
            if ('' === ($form[$field] ?? '')) {
                $errors[] = $field;
            }
        }
        $email = $form['email'] ?? '';
        if ('' !== $email && false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'email';
        }
        if (!in_array($form['payment'] ?? '', $paymentMethods, true)) {
            $errors[] = 'payment';
        }

        return $errors;
    }

    /**
     * Error codes safe to display (known or well-formed module codes).
     *
     * @param list<string> $codes
     *
     * @return list<string>
     */
    public static function displayable(array $codes): array
    {
        return array_values(array_filter(
            $codes,
            static fn (string $e): bool => in_array($e, self::ERRORS, true) || 1 === preg_match(self::EXTRA_ERROR, $e)
        ));
    }
}
