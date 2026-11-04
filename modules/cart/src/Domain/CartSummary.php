<?php

/**
 * Priced cart: lines, subtotal, shipping (ShippingPolicy) and total. Built by the WordPress adapter (Cart),
 * computed here without WordPress.
 */

declare(strict_types=1);

namespace PhpFasty\Module\Cart\Domain;

final class CartSummary
{
    /** @param list<CartLine> $lines */
    private function __construct(
        public readonly array $lines,
        public readonly int $subtotal,
        public readonly int $shipping,
    ) {
    }

    /** @param list<CartLine> $lines */
    public static function of(array $lines, ShippingPolicy $policy): self
    {
        $subtotal = array_sum(array_map(static fn (CartLine $l): int => $l->total(), $lines));
        $physical = [] !== array_filter($lines, static fn (CartLine $l): bool => !$l->digital);

        return new self($lines, $subtotal, $policy->cost($subtotal, $physical));
    }

    public function digital(): bool
    {
        return [] !== $this->lines && [] === array_filter($this->lines, static fn (CartLine $l): bool => !$l->digital);
    }

    public function count(): int
    {
        return array_sum(array_map(static fn (CartLine $l): int => $l->qty, $this->lines));
    }

    /**
     * @return array{digital: bool, items: list<array<string, mixed>>, count: int, subtotal: int, shipping: int, total: int}
     */
    public function toArray(): array
    {
        return [
            'digital' => $this->digital(),
            'items' => array_map(static fn (CartLine $l): array => $l->toArray(), $this->lines),
            'count' => $this->count(),
            'subtotal' => $this->subtotal,
            'shipping' => $this->shipping,
            'total' => $this->subtotal + $this->shipping,
        ];
    }
}
