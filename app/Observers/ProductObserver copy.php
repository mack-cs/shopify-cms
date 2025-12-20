<?php

namespace App\Observers;

use App\Models\ChangeLog;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;

class ProductObserver
{
    public function updating(Product $product): void
    {
        $dirty = $product->getDirty();
        if (empty($dirty)) return;

        $userId = Auth::id();

        foreach ($dirty as $field => $newValue) {
            ChangeLog::create([
                'import_id'   => $product->import_id,
                'product_id'  => $product->id,
                'changed_by'  => $userId,
                'model_type'  => Product::class,
                'model_id'    => $product->id,
                'field'       => $field,
                'old_value'   => (string) $product->getOriginal($field),
                'new_value'   => is_scalar($newValue) ? (string)$newValue : json_encode($newValue),
            ]);
        }
    }
}
