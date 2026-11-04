<?php

/**
 * Shipping of physical goods: flat fee below the free-shipping threshold, nothing for an empty or
 * downloads-only cart. Pure value object (amounts in integer cents).
 */

declare(strict_types=1);

namespace PhpFasty\Module\Cart\Domain;

final class ShippingPolicy
{
    public function __construct(
        public readonly int $flatCents,
        public readonly int $freeFromCents,
        public readonly bool $digitalOnly = false,
    ) {
    }

    public function cost(int $subtotal, bool $physical = true): int
    {
        if ($this->digitalOnly || !$physical || 0 === $subtotal || $subtotal >= $this->freeFromCents) {
            return 0;
        }

        return $this->flatCents;
    }
}
