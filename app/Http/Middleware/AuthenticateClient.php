<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateClient
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || preg_match('/\Aaim_[a-f0-9]{64}\z/D', $token) !== 1) {
            return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'A valid client token is required.']], 401);
        }

        $client = Client::where('token_hash', hash('sha256', $token))->first();

        if ($client === null) {
            return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'A valid client token is required.']], 401);
        }

        if (! $client->active) {
            return response()->json(['error' => ['code' => 'CLIENT_INACTIVE', 'message' => 'Client is inactive.']], 403);
        }

        $request->attributes->set('authenticated_client', $client);

        return $next($request);
    }
}
