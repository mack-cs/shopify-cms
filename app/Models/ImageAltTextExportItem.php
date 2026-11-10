<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImageAltTextExportItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['csv_row' => 'array'];
}
