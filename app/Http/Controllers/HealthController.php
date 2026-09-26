<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * GET /health → {"status":"ok|degraded","checks":{...}} con 200 o 503. Sin datos sensibles.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // CONTRATO: implementar comprobaciones de base de datos, Redis, colas y disco (agente backend).
        return response()->json(['status' => 'ok', 'checks' => []]);
    }
}
