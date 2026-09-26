<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');

            return response()->json(['status' => 'ok']);
        } catch (Throwable) {
            return response()->json(['error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'Service unavailable.']], 503);
        }
    }
}
