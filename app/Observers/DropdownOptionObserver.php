<?php

namespace App\Observers;

use App\Jobs\RecalculateDropdownOptionProductsJob;
use App\Models\DropdownOption;
use Illuminate\Support\Facades\DB;

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
        $primary = $option->collection_tag_primary;
        $secondary = $option->collection_tag_secondary;

        DB::afterCommit(static function () use ($primary, $secondary): void {
            RecalculateDropdownOptionProductsJob::dispatchSync($primary, $secondary);
        });
    }
}
