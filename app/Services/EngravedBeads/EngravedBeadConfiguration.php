<?php

namespace App\Services\EngravedBeads;

use App\Models\Product;
use App\Services\TagNormalizer;

/**
 * Quantity tags are the only configuration that activates engraved-bead behaviour.
 * Product titles are ignored. Identifying tags mark a product as part of the workflow
 * but never supply a quantity and never grant procurement eligibility on their own.
 */
final class EngravedBeadConfiguration
{
    public const INVALID_MESSAGE = 'Invalid engraved bead configuration: exactly one quantity tag is required.';

    /** @var array<string, int> */
    public const QUANTITY_TAGS = [
        '1-engraved-beads' => 1,
        '2-engraved-beads' => 2,
        '3-engraved-beads' => 3,
        '4-engraved-beads' => 4,
    ];

    /** @var array<int, string> */
    public const IDENTIFYING_TAGS = [
        'engraved-beads-unlisted',
        'engaved-unlisted-beads',
    ];

    /**
     * @param  array<int, int>  $quantities
     */
    private function __construct(
        private readonly array $quantities,
        private readonly bool $identified,
        private readonly bool $malformedQuantity,
    ) {}

    public static function fromTags(?string $tags): self
    {
        $quantities = [];
        $identified = false;
        $malformed = false;

        foreach (TagNormalizer::parseTokens($tags) as $token) {
            if (isset(self::QUANTITY_TAGS[$token])) {
                $quantities[] = self::QUANTITY_TAGS[$token];

                continue;
            }
            if (in_array($token, self::IDENTIFYING_TAGS, true)) {
                $identified = true;

                continue;
            }
            if (preg_match('/^\d+-engraved-beads$/', $token) === 1) {
                $malformed = true;
            }
        }

        return new self(array_values(array_unique($quantities)), $identified, $malformed);
    }

    public static function fromProduct(?Product $product): self
    {
        return self::fromTags($product?->tags);
    }

    public function participating(): bool
    {
        return $this->quantities !== [] || $this->identified || $this->malformedQuantity;
    }

    public function valid(): bool
    {
        return count($this->quantities) === 1 && ! $this->malformedQuantity;
    }

    public function beads(): ?int
    {
        return $this->valid() ? $this->quantities[0] : null;
    }

    public function additionalPerUnit(): int
    {
        $beads = $this->beads();

        return $beads === null ? 0 : max(0, $beads - 1);
    }

    public function physicalUnits(int $finishedUnits): int
    {
        $beads = $this->beads();
        if ($beads === null || $finishedUnits < 0) {
            return max(0, $finishedUnits);
        }

        return $finishedUnits * $beads;
    }

    public function additionalUnits(int $finishedUnits): int
    {
        if (! $this->valid() || $finishedUnits < 0) {
            return 0;
        }

        return $finishedUnits * $this->additionalPerUnit();
    }

    public function state(): ?string
    {
        if (! $this->participating()) {
            return null;
        }

        return $this->valid() ? 'valid' : 'invalid';
    }

    public function quantity(): ?int
    {
        return $this->valid() ? $this->beads() : null;
    }

    public function label(): string
    {
        if ($this->valid()) {
            return 'Engraved beads required: '.$this->beads();
        }
        if ($this->participating()) {
            return self::INVALID_MESSAGE;
        }

        return '';
    }

    public function apply(Product $product): void
    {
        $product->engraved_bead_quantity = $this->quantity();
        $product->engraved_bead_configuration = $this->state();
    }
}
