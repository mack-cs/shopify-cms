<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    protected $fillable = ['product_id', 'user_id','approval_version'];
    public $timestamps = true;
}
