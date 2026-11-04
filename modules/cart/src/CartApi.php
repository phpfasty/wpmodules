<?php

/**
 * Service `cart.api` for dependent modules (orders): the only surface they use.
 */

declare(strict_types=1);

namespace PhpFasty\Module\Cart;

final class CartApi
{
    /** @return array{digital: bool, items: list<array<string, mixed>>, count: int, subtotal: int, shipping: int, total: int} */
    public function summary(): array
    {
        return Cart::summary();
    }

    /** Empty the cart and write the cookie. */
    public function reset(): void
    {
        Cart::clear();
        Cart::persist();
    }

    public function format(int $cents): string
    {
        return Money::format($cents);
    }

    public function currency(): string
    {
        return Money::currency();
    }

    public function stock(int $productId): ?int
    {
        return Catalog::stock($productId);
    }

    public function setStock(int $productId, int $stock): void
    {
        Catalog::setStock($productId, $stock);
    }

    public function pageUrl(string $slug): string
    {
        return Pages::url($slug);
    }

    /** Nonce (of the action or its deprecated alias), POST method and rate limit; dies otherwise. */
    public function verify(string $action, ?string $deprecated = null): void
    {
        Handlers::verify($action, $deprecated);
    }
}
