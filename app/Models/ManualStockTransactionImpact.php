<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualStockTransactionImpact extends Model
{
    protected $guarded = [];
    protected $casts = ['sources' => 'array', 'shopify_response' => 'array', 'processed_at' => 'datetime'];
    public function transaction(): BelongsTo { return $this->belongsTo(ManualStockTransaction::class, 'manual_stock_transaction_id'); }
    public function variant(): BelongsTo { return $this->belongsTo(Variant::class); }
}
