<?php

namespace App\Observers;

use App\Jobs\RecalculateDropdownOptionProductsJob;
use App\Models\DropdownOption;

final class DropdownOptionObserver
{
    public function created(DropdownOption $option): void
    {
        if ($option->active) {
            $this->dispatch($option);
        }
    }

    public function updated(DropdownOption $option): void
    {
        if (! $option->wasChanged([
            'header',
            'value',
            'collection_tag_primary',
            'collection_tag_secondary',
            'active',
        ])) {
            return;
        }

        if ($option->active || (bool) $option->getOriginal('active')) {
            $this->dispatch($option);
        }
    }

    private function dispatch(DropdownOption $option): void
    {
        RecalculateDropdownOptionProductsJob::dispatch(
            $option->collection_tag_primary,
            $option->collection_tag_secondary,
        )->afterCommit();
    }
}
