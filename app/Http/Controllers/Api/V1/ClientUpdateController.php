<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ClientUpdateController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $version = config('attention.agent_latest_version');
        $file = config('attention.agent_release_file');

        if (! is_string($version) || preg_match('/\A[0-9A-Za-z.+-]{1,32}\z/D', $version) !== 1 ||
            ! is_string($file) || ! Storage::disk('local')->exists($file)) {
            return response()->json(['update_available' => false]);
        }

        $path = Storage::disk('local')->path($file);

        return response()->json([
            'update_available' => true,
            'version' => $version,
            'package_url' => route('api.v1.client.update.package'),
            'sha256' => hash_file('sha256', $path),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function download(): BinaryFileResponse|JsonResponse
    {
        $file = config('attention.agent_release_file');
        if (! is_string($file) || ! Storage::disk('local')->exists($file)) {
            return response()->json(['error' => ['code' => 'UPDATE_UNAVAILABLE']], 404);
        }

        return response()->download(Storage::disk('local')->path($file), 'AwbInputMonitor-update.zip', [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
