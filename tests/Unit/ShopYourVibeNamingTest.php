<?php

use App\Services\ShopYourVibeWorkflow;

it('uppercases a vibe name without replacing and while typing', function () {
    expect(ShopYourVibeWorkflow::typingVibeName('gold and white'))->toBe('GOLD AND WHITE')
        ->and(ShopYourVibeWorkflow::typingVibeName('Sand and Sea'))->toBe('SAND AND SEA');
});

it('normalizes vibe names and shopify collection titles', function (string $parent, string $typed, string $name, string $title, string $handle, bool $removed) {
    $normalized = ShopYourVibeWorkflow::normalizeVibeName($typed, $parent);

    expect($normalized['name'])->toBe($name)
        ->and($normalized['removed_parent'])->toBe($removed)
        ->and(ShopYourVibeWorkflow::shopifyCollectionTitle($parent, $normalized['name']))->toBe($title)
        ->and(ShopYourVibeWorkflow::contextualHandle(ShopYourVibeWorkflow::parentHandle([
            'title' => $parent,
            'handle' => str($parent)->slug()->toString(),
        ]), $normalized['name']))->toBe($handle);
})->with([
    ['Elevated Basics Bracelets', 'Gold and White', 'GOLD & WHITE', 'ELEVATED BASICS BRACELETS GOLD & WHITE', 'elevated-basics-bracelets-gold-and-white', false],
    ['Elevated Basics Bracelets', 'gold and white', 'GOLD & WHITE', 'ELEVATED BASICS BRACELETS GOLD & WHITE', 'elevated-basics-bracelets-gold-and-white', false],
    ['Elevated Basics Bracelets', 'GOLD AND WHITE', 'GOLD & WHITE', 'ELEVATED BASICS BRACELETS GOLD & WHITE', 'elevated-basics-bracelets-gold-and-white', false],
    ['Elevated Basics Bracelets', 'Gold & White', 'GOLD & WHITE', 'ELEVATED BASICS BRACELETS GOLD & WHITE', 'elevated-basics-bracelets-gold-and-white', false],
    ['Elevated Basics Necklaces', 'Pearl', 'PEARL', 'ELEVATED BASICS NECKLACES PEARL', 'elevated-basics-necklaces-pearl', false],
    ['Livi Road Earrings', 'Silver and Gold', 'SILVER & GOLD', 'LIVI ROAD EARRINGS SILVER & GOLD', 'livi-road-earrings-silver-and-gold', false],
    ['Pata Pata Bracelets', 'Black and Gold', 'BLACK & GOLD', 'PATA PATA BRACELETS BLACK & GOLD', 'pata-pata-bracelets-black-and-gold', false],
    ['Elevated Basics Bracelets', 'Sand and Sea', 'SAND & SEA', 'ELEVATED BASICS BRACELETS SAND & SEA', 'elevated-basics-bracelets-sand-and-sea', false],
    ['Elevated Basics Bracelets', 'Elevated Basics Bracelets Gold and White', 'GOLD & WHITE', 'ELEVATED BASICS BRACELETS GOLD & WHITE', 'elevated-basics-bracelets-gold-and-white', true],
    ['Elevated Basics Bracelets', 'ELEVATED BASICS BRACELETS - PEARL', 'PEARL', 'ELEVATED BASICS BRACELETS PEARL', 'elevated-basics-bracelets-pearl', true],
    ['Elevated Basics Bracelets', 'Elevated Basics Bracelets: Silver', 'SILVER', 'ELEVATED BASICS BRACELETS SILVER', 'elevated-basics-bracelets-silver', true],
    ['Elevated Basics Bracelets', 'elevated   basics   bracelets    gold   and   white', 'GOLD & WHITE', 'ELEVATED BASICS BRACELETS GOLD & WHITE', 'elevated-basics-bracelets-gold-and-white', true],
]);

it('does not remove a partial parent name or a match that is not the prefix', function () {
    $parent = 'Elevated Basics Bracelets';

    expect(ShopYourVibeWorkflow::normalizeVibeName('Bracelets Gold', $parent)['name'])->toBe('BRACELETS GOLD')
        ->and(ShopYourVibeWorkflow::normalizeVibeName('Basics', $parent)['removed_parent'])->toBeFalse()
        ->and(ShopYourVibeWorkflow::normalizeVibeName('Sea Elevated Basics Bracelets', $parent)['name'])->toBe('SEA ELEVATED BASICS BRACELETS')
        ->and(ShopYourVibeWorkflow::normalizeVibeName('Elevated Basics BraceletsGold', $parent)['removed_parent'])->toBeFalse();
});

it('rejects a vibe name that is only the parent collection', function () {
    $normalized = ShopYourVibeWorkflow::normalizeVibeName('Elevated Basics Bracelets', 'Elevated Basics Bracelets');

    expect($normalized['empty'])->toBeTrue()
        ->and($normalized['removed_parent'])->toBeTrue()
        ->and(ShopYourVibeWorkflow::vibeNameError($normalized))
        ->toBe('Please enter a vibe name only. The collection name is already included automatically.');
});

it('rejects an empty vibe name and a name with no letters or numbers', function () {
    expect(ShopYourVibeWorkflow::vibeNameError(ShopYourVibeWorkflow::normalizeVibeName('   ', 'Necklaces')))
        ->toBe('A vibe name is required.')
        ->and(ShopYourVibeWorkflow::vibeNameError(ShopYourVibeWorkflow::normalizeVibeName('!!!', 'Necklaces')))
        ->toBe('Enter a vibe name using letters or numbers.');
});

it('uses the parent title when the parent handle is missing', function () {
    expect(ShopYourVibeWorkflow::parentHandle(['title' => 'Livi Road Bracelets', 'handle' => '']))
        ->toBe('livi-road-bracelets');
});
