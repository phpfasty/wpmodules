<?php

/**
 * Service `cart.api` for dependent modules (orders): the only surface they use.
 *
 * @package ModulY\Cart
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

    public function verify(string $action): void
    {
        Handlers::verify($action);
    }
}
