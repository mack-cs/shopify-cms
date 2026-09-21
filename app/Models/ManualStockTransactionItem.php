<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualStockTransactionItem extends Model
{
    protected $guarded = [];
    protected $casts = ['quantity' => 'integer', 'is_stack' => 'boolean'];
    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($product = Product::query()->with('variants')->find($item->product_id)) {
                $item->product_title = $product->title;
                $item->sku = $product->variants->first()?->sku;
            }
        });
        static::saved(fn (self $item) => $item->invalidatePreview());
        static::deleted(fn (self $item) => $item->invalidatePreview());
    }

    private function invalidatePreview(): void
    {
        $transaction = $this->transaction()->first();
        if (! $transaction || $transaction->impacts()->where('status', 'completed')->exists()) return;
        $transaction->impacts()->delete();
        if ($transaction->status !== 'draft') $transaction->update(['status' => 'draft', 'inventory_update_status' => 'not_started', 'last_error' => null]);
    }
    public function transaction(): BelongsTo { return $this->belongsTo(ManualStockTransaction::class, 'manual_stock_transaction_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function variant(): BelongsTo { return $this->belongsTo(Variant::class); }
}
