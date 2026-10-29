<?php

use App\Models\EngravedBeadFamilyMember;
use App\Services\EngravedBeads\EngravedBeadSellabilityShopify;

final class BeadSellabilityFake extends EngravedBeadSellabilityShopify
{
    public array $states = [];

    public array $calls = [];

    public bool $failZero = false;

    public function __construct() {}

    public function read(EngravedBeadFamilyMember $member, string $location): array
    {
        return $this->states[$member->required_beads];
    }

    public function deny(EngravedBeadFamilyMember $member): void
    {
        $this->calls[] = ['deny', $member->required_beads];
        $this->states[$member->required_beads]['policy'] = 'DENY';
    }

    public function tracking(EngravedBeadFamilyMember $member, bool $tracked): void
    {
        if ($member->required_beads === 1 && ! $tracked) {
            throw new LogicException('Source tracking disabled');
        }
        $this->calls[] = ['tracking', $member->required_beads, $tracked];
        $this->states[$member->required_beads]['tracked'] = $tracked;
        $this->states[$member->required_beads]['sellable'] = ! $tracked || $this->states[$member->required_beads]['available'] > 0;
    }

    public function zero(EngravedBeadFamilyMember $member, string $location, int $expected, string $key): void
    {
        $this->calls[] = ['zero', $member->required_beads, $key];
        if ($this->failZero) {
            throw new RuntimeException('Simulated zero failure');
        }
        $this->states[$member->required_beads]['available'] = 0;
        $this->states[$member->required_beads]['on_hand'] = 0;
        $this->states[$member->required_beads]['sellable'] = false;
    }
}
