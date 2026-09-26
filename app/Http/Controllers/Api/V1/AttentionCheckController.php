<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attention\AttentionLookup;
use App\Domain\Attention\AwbNormalizer;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use stdClass;

class AttentionCheckController extends Controller
{
    public function __invoke(Request $request, AwbNormalizer $normalizer, AttentionLookup $lookup): JsonResponse
    {
        $raw = $request->getContent();

        if (! $request->isJson() || strlen($raw) > 2048) {
            return response()->json(['error' => ['code' => 'INVALID_REQUEST', 'message' => 'Expected JSON body of at most 2048 bytes.']], 400);
        }

        try {
            $body = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['error' => ['code' => 'INVALID_REQUEST', 'message' => 'Malformed JSON.']], 400);
        }

        if (! $body instanceof stdClass || ! is_string($body->awb ?? null)) {
            return response()->json(['error' => ['code' => 'INVALID_AWB', 'message' => 'AWB must be a string.']], 422);
        }

        try {
            $awb = $normalizer->normalize($body->awb);
        } catch (InvalidArgumentException) {
            return response()->json(['error' => ['code' => 'INVALID_AWB', 'message' => 'AWB must contain 8–30 ASCII letters or digits.']], 422);
        }

        try {
            return response()->json($lookup->check($awb));
        } catch (QueryException $exception) {
            Log::error('Attention lookup database failure', ['type' => $exception::class, 'sql_state' => $exception->getCode()]);

            return response()->json(['error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'Service unavailable.']], 503);
        }
    }
}
