<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewProductDraftApproval extends Model
{
    protected $fillable = [
        'new_product_draft_id',
        'user_id',
        'approval_version',
    ];
}
