<?php

namespace App\Observers;

use App\Models\Import;
use App\Models\RequiredField;
use App\Services\Normalizer;

class RequiredFieldObserver
{
    public function saved(RequiredField $field): void
    {
        $normalizer = app(Normalizer::class);
        $imports = Import::where('is_current', true)->get();
        foreach ($imports as $import) {
            $normalizer->recalculateErrors($import);
        }
    }
}
