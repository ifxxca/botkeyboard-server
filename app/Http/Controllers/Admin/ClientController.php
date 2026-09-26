<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\AuditWriter;
use App\Services\ClientTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(): View
    {
        $clients = Client::orderBy('name')->paginate(25);
        $all = Client::all();

        return view('admin.clients.index', [
            'clients' => $clients,
            'counts' => [
                'total' => $all->count(),
                'online' => $all->filter(fn (Client $client): bool => $client->connectionStatus() === 'online')->count(),
                'offline' => $all->filter(fn (Client $client): bool => $client->connectionStatus() === 'offline')->count(),
                'never' => $all->filter(fn (Client $client): bool => $client->connectionStatus() === 'never')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.clients.create');
    }

    public function store(Request $request, ClientTokenService $tokens, AuditWriter $audit): Response
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120', Rule::unique('clients', 'name')]]);
        $plaintext = $tokens->generate();

        $client = DB::transaction(function () use ($data, $tokens, $plaintext, $audit, $request): Client {
            $client = Client::create([
                'public_id' => (string) Str::uuid(),
                'name' => $data['name'],
                'token_hash' => $tokens->hash($plaintext),
                'active' => true,
            ]);
            $audit->record('CLIENT_CREATED', $request->user()->id, 'client', $client->id);

            return $client;
        });

        return response()->view('admin.clients.issued', compact('client', 'plaintext'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(Client $client): View
    {
        return view('admin.clients.show', compact('client'));
    }

    public function disable(Request $request, Client $client, AuditWriter $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $client, $audit): void {
            $client->update(['active' => false]);
            $audit->record('CLIENT_DISABLED', $request->user()->id, 'client', $client->id);
        });

        return redirect()->route('admin.clients.show', $client);
    }

    public function enable(Request $request, Client $client, AuditWriter $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $client, $audit): void {
            $client->update(['active' => true]);
            $audit->record('CLIENT_ENABLED', $request->user()->id, 'client', $client->id);
        });

        return redirect()->route('admin.clients.show', $client);
    }

    public function rotate(Request $request, Client $client, ClientTokenService $tokens, AuditWriter $audit): Response
    {
        $plaintext = $tokens->generate();

        DB::transaction(function () use ($request, $client, $tokens, $plaintext, $audit): void {
            $locked = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $locked->update(['token_hash' => $tokens->hash($plaintext)]);
            $audit->record('CLIENT_TOKEN_ROTATED', $request->user()->id, 'client', $client->id);
        });

        return response()->view('admin.clients.issued', compact('client', 'plaintext'))
            ->header('Cache-Control', 'no-store, private');
    }
}
