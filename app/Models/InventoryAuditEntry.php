<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAuditEntry extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $table = 'inventory_operation_audits';
}
