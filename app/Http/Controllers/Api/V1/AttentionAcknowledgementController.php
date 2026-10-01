<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attention\AttentionAcknowledgementService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;
use stdClass;

class AttentionAcknowledgementController extends Controller
{
    public function __invoke(Request $request, string $attention, AttentionAcknowledgementService $acknowledger): JsonResponse
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

        if (! $body instanceof stdClass || ! is_string($body->method ?? null) ||
            ! in_array($body->method, ['SCAN', 'MANUAL_CLOSE'], true)) {
            return response()->json(['error' => ['code' => 'INVALID_METHOD', 'message' => 'Method must be SCAN or MANUAL_CLOSE.']], 422);
        }

        $client = $request->attributes->get('authenticated_client');

        if (! $client instanceof Client) {
            return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'A valid client token is required.']], 401);
        }

        try {
            $result = $acknowledger->acknowledge($attention, $client, $body->method);
        } catch (QueryException $exception) {
            Log::error('Attention acknowledgement database failure', ['type' => $exception::class, 'sql_state' => $exception->getCode()]);

            return response()->json(['error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'Service unavailable.']], 503);
        }

        if ($result === null) {
            return response()->json(['error' => ['code' => 'ATTENTION_NOT_FOUND', 'message' => 'Attention is not in the active dataset.']], 404);
        }

        return response()->json($result);
    }
}
