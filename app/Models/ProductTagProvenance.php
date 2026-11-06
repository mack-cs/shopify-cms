<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductTagProvenance extends Model
{
    public const SHOPIFY = 'shopify';

    public const PLANNED = 'planned';

    protected $table = 'product_tag_provenance';

    protected $guarded = [];
}
