<?php

/**
 * One priced cart line (server-side prices, integer cents). View data per ui8kit/contract view/v1: `toArray()`
 * is the `wpfasty_shop_cart` item shape templates read.
 */

declare(strict_types=1);

namespace PhpFasty\Module\Cart\Domain;

final class CartLine
{
    public function __construct(
        public readonly int $id,
        public readonly string $tier,
        public readonly string $tierLabel,
        public readonly string $title,
        public readonly string $url,
        public readonly int $qty,
        public readonly int $unit,
        public readonly string $unitDisplay,
        public readonly string $lineDisplay,
        public readonly string $image,
        public readonly string $sku,
        public readonly bool $digital,
    ) {
    }

    public function key(): string
    {
        return LineCodec::key($this->id, $this->tier);
    }

    public function total(): int
    {
        return $this->unit * $this->qty;
    }

    /**
     * @return array{key: string, tier: string, tier_label: string, id: int, title: string, url: string, qty: int,
     *     unit: int, line: int, unit_display: string, line_display: string, image: string, sku: string, digital: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'tier' => $this->tier,
            'tier_label' => $this->tierLabel,
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'qty' => $this->qty,
            'unit' => $this->unit,
            'line' => $this->total(),
            'unit_display' => $this->unitDisplay,
            'line_display' => $this->lineDisplay,
            'image' => $this->image,
            'sku' => $this->sku,
            'digital' => $this->digital,
        ];
    }
}
