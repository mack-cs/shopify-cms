<?php

use App\Services\EngravedBeads\EngravedBeadConfiguration;
use App\Services\EngravedBeads\EngravedBeadDemand;

it('recognizes each engraved bead quantity tag and ignores the product title', function (string $tag, int $beads, int $additional): void {
    $configuration = EngravedBeadConfiguration::fromTags($tag.', shape-title');

    expect($configuration->valid())->toBeTrue()
        ->and($configuration->beads())->toBe($beads)
        ->and($configuration->additionalPerUnit())->toBe($additional)
        ->and($configuration->label())->toBe('Engraved beads required: '.$beads)
        ->and($configuration->physicalUnits(10))->toBe(10 * $beads)
        ->and($configuration->additionalUnits(2))->toBe(2 * $additional);
})->with([
    ['1-engraved-beads', 1, 0],
    ['2-engraved-beads', 2, 1],
    ['3-engraved-beads', 3, 2],
    ['4-engraved-beads', 4, 3],
]);

it('rejects multiple, missing, and malformed engraved bead quantity tags', function (string $tags): void {
    $configuration = EngravedBeadConfiguration::fromTags($tags);

    expect($configuration->participating())->toBeTrue()
        ->and($configuration->valid())->toBeFalse()
        ->and($configuration->beads())->toBeNull()
        ->and($configuration->additionalPerUnit())->toBe(0)
        ->and($configuration->label())->toBe(EngravedBeadConfiguration::INVALID_MESSAGE);
})->with([
    'two quantity tags' => '2-engraved-beads, 4-engraved-beads',
    'identifying tag only' => 'engraved_beads_unlisted',
    'misspelled identifying tag only' => 'engaved_unlisted_beads',
    'unsupported quantity' => '5-engraved-beads',
]);

it('leaves ordinary products on the normal path', function (): void {
    $configuration = EngravedBeadConfiguration::fromTags('bracelet, gold');

    expect($configuration->participating())->toBeFalse()
        ->and($configuration->state())->toBeNull()
        ->and($configuration->label())->toBe('');
});

it('does not let a product title override the quantity tag', function (): void {
    $configuration = EngravedBeadConfiguration::fromTags('1-engraved-beads');

    expect($configuration->beads())->toBe(1)
        ->and($configuration->additionalUnits(3))->toBe(0);
});

it('scales finished-product sales to physical beads without counting stack demand twice', function (): void {
    $scaled = (new EngravedBeadDemand)->scaleDirectSales([
        'direct_gross' => 10,
        'direct_refunded' => 1,
        'direct_net' => 9,
        'stack_gross' => 2,
        'stack_refunded' => 0,
        'stack_net' => 2,
        'gross' => 12,
        'refunded' => 1,
        'net' => 11,
    ], 4);

    expect($scaled['direct_net'])->toBe(36)
        ->and($scaled['net'])->toBe(38)
        ->and($scaled['stack_net'])->toBe(2);
});
