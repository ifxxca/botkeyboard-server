@extends('admin.layout')
@section('title', 'Client PCs')
@section('content')
<div data-auto-refresh="30000">
    <header class="page-header">
        <div><h1 class="page-title">Client PCs</h1><p class="page-description">Pantau agent pada setiap komputer. Halaman diperbarui otomatis setiap 30 detik; agent dianggap offline jika heartbeat tidak diterima selama {{ config('attention.client_online_seconds') }} detik.</p></div>
        <div class="actions"><a class="btn btn-secondary" href="{{ route('admin.clients.index') }}">Refresh status</a><a class="btn btn-primary" href="{{ route('admin.clients.create') }}">+ Daftarkan PC</a></div>
    </header>
    <section class="stats-grid">
        <div class="card stat-card"><div class="stat-head">Total client <span class="status-dot"></span></div><div class="stat-value">{{ $counts['total'] }}</div><div class="stat-note">Semua PC terdaftar</div></div>
        <div class="card stat-card"><div class="stat-head">Online <span class="status-dot online"></span></div><div class="stat-value">{{ $counts['online'] }}</div><div class="stat-note">Heartbeat diterima</div></div>
        <div class="card stat-card"><div class="stat-head">Offline <span class="status-dot offline"></span></div><div class="stat-value">{{ $counts['offline'] }}</div><div class="stat-note">Perlu diperiksa</div></div>
        <div class="card stat-card"><div class="stat-head">Belum terhubung <span class="status-dot never"></span></div><div class="stat-value">{{ $counts['never'] }}</div><div class="stat-note">Belum ada heartbeat</div></div>
    </section>
    <section class="card">
        <div class="card-header"><h2 class="card-title">Daftar client</h2><p class="card-description">Status ini berasal dari heartbeat agent, bukan dari aktivitas scan.</p></div>
        <div class="card-content" style="padding-left:0;padding-right:0;padding-bottom:0">
            <div class="table-wrap"><table class="table"><thead><tr><th>Nama PC</th><th>Status koneksi</th><th>Agent</th><th>Terakhir terlihat</th><th>Terdaftar</th><th></th></tr></thead><tbody>
            @forelse ($clients as $client)
                @php($connection = $client->connectionStatus())
                <tr>
                    <td><a class="table-link" href="{{ route('admin.clients.show', $client) }}">{{ $client->name }}</a><div class="muted mono" style="margin-top:4px">{{ $client->public_id }}</div></td>
                    <td>
                        @if ($connection === 'online') <span class="badge badge-online">Online</span>
                        @elseif ($connection === 'offline') <span class="badge badge-offline">Offline</span>
                        @elseif ($connection === 'never') <span class="badge badge-never">Belum pernah terhubung</span>
                        @else <span class="badge badge-disabled">Disabled</span> @endif
                    </td>
                    <td>{{ $client->agent_version ?? '—' }} @if($latestAgentVersion && $client->agent_version && version_compare($client->agent_version, $latestAgentVersion, '<'))<div class="badge badge-never" style="margin-top:5px">Perlu update · terbaru {{ $latestAgentVersion }}</div>@endif</td>
                    <td>@if($client->last_seen_at)<strong>{{ $client->last_seen_at->diffForHumans() }}</strong><div class="muted" style="margin-top:3px">{{ $client->last_seen_at->timezone(config('attention.display_timezone'))->format('d M Y H:i:s') }}</div>@else<span class="muted">—</span>@endif</td>
                    <td>{{ $client->created_at->timezone(config('attention.display_timezone'))->format('d M Y') }}</td>
                    <td><a class="btn btn-secondary btn-sm" href="{{ route('admin.clients.show', $client) }}">Detail</a></td>
                </tr>
            @empty <tr><td class="empty" colspan="6">Belum ada client. Daftarkan PC pertama untuk mulai memantau koneksi.</td></tr>@endforelse
            </tbody></table></div>
            @if($clients->hasPages())<div class="pagination">{{ $clients->links() }}</div>@endif
        </div>
    </section>
</div>
@endsection
