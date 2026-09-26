<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ClientHeartbeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'agent_version' => ['required', 'string', 'max:32', 'regex:/\A[0-9A-Za-z.+-]+\z/'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'error' => ['code' => 'INVALID_REQUEST', 'message' => 'Invalid heartbeat payload.'],
            ], 422);
        }
        $data = $validator->validated();

        /** @var Client $client */
        $client = $request->attributes->get('authenticated_client');
        $now = now();
        $client->update([
            'last_authenticated_at' => $now,
            'last_seen_at' => $now,
            'agent_version' => $data['agent_version'],
        ]);

        return response()->json(['status' => 'ok']);
    }
}
