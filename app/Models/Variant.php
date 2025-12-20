<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Variant extends Model
{
    protected $fillable = [
    'product_id',

    'sku',
    'barcode',

    'option1_name',
    'option1_value',
    'option2_name',
    'option2_value',
    'option3_name',
    'option3_value',

    'price',
    'compare_at_price',

    'inventory_qty',
    'inventory_policy',

    'requires_shipping',
    'taxable',

    'weight',
    'weight_unit',

    'position',
];


protected $casts = [
    'price' => 'decimal:2',
    'compare_at_price' => 'decimal:2',
    'weight' => 'decimal:3',

    'requires_shipping' => 'boolean',
    'taxable' => 'boolean',
];

}
