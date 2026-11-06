<?php

namespace App\Http\Controllers;

use App\Models\ProcurementSupplierImportBatch;
use App\Services\InventoryAccessService;
use App\Services\Procurement\SupplierOrderCsvService;

final class SupplierImportRejectedRowsController extends Controller
{
    public function __invoke(ProcurementSupplierImportBatch $batch)
    {
        app(InventoryAccessService::class)->authorizeInventory(auth()->id());

        return response()->streamDownload(fn () => print app(SupplierOrderCsvService::class)->rejectedCsv($batch),
            'rejected-'.$batch->type.'-'.$batch->uuid.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
