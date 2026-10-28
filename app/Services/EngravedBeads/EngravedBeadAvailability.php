<?php

namespace App\Services\EngravedBeads;

/** Pure accounting policy. Never use untracked member inventory quantities. */
final class EngravedBeadAvailability
{
    public function calculate(int $remoteAvailable, int $unappliedReservations, int $automationHeld = 0): int
    {
        if ($unappliedReservations < 0 || $automationHeld < 0) {
            throw new \InvalidArgumentException('Reservation and automation-held quantities cannot be negative.');
        }

        // Applied available->reserved moves are ALREADY reflected in remoteAvailable.
        return $remoteAvailable + $automationHeld - $unappliedReservations;
    }

    public function shouldDisable(int $available, int $threshold): bool
    {
        return $available <= $threshold;
    }
}
