<?php

namespace App\Http\Controllers;

use App\Services\ProcurementPredictionIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProcurementPredictionController extends Controller
{
    public function store(Request $request, ProcurementPredictionIngestService $ingest): JsonResponse
    {
        $run = $ingest->persist($request->all());

        return response()->json([
            'run_id' => $run->id,
            'run_uuid' => $run->run_uuid,
            'status' => $run->status,
            'prediction_rows' => $run->total_prediction_rows,
        ]);
    }
}
