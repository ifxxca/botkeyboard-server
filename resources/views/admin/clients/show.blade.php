@extends('admin.layout')
@section('title', $client->name)
@section('content')
@php($connection = $client->connectionStatus())
<header class="page-header">
    <div><div style="margin-bottom:9px">@if($connection==='online')<span class="badge badge-online">Online</span>@elseif($connection==='offline')<span class="badge badge-offline">Offline</span>@elseif($connection==='never')<span class="badge badge-never">Belum pernah terhubung</span>@else<span class="badge badge-disabled">Disabled</span>@endif</div><h1 class="page-title">{{ $client->name }}</h1><p class="page-description">Detail identitas, koneksi, dan kredensial client.</p></div>
    <a class="btn btn-secondary" href="{{ route('admin.clients.index') }}">Kembali</a>
</header>
<div class="content-grid">
    <section class="card"><div class="card-header"><h2 class="card-title">Status agent</h2><p class="card-description">Heartbeat dijadwalkan setiap 30 detik.</p></div><div class="card-content details-grid">
        <div class="detail"><div class="detail-label">Status koneksi</div><div class="detail-value">{{ ucfirst($connection) }}</div></div>
        <div class="detail"><div class="detail-label">Agent version</div><div class="detail-value">{{ $client->agent_version ?? 'Belum dilaporkan' }}</div></div>
        @if($latestAgentVersion)<div class="detail"><div class="detail-label">Versi terbaru</div><div class="detail-value">{{ $latestAgentVersion }} @if($client->agent_version && version_compare($client->agent_version, $latestAgentVersion, '<'))<span class="badge badge-never">Perlu update</span>@else<span class="badge badge-online">Terbaru</span>@endif</div></div>@endif
        <div class="detail"><div class="detail-label">Last seen</div><div class="detail-value">{{ $client->last_seen_at ? $client->last_seen_at->timezone(config('attention.display_timezone'))->format('d M Y H:i:s').' ('.$client->last_seen_at->diffForHumans().')' : 'Belum pernah' }}</div></div>
        <div class="detail"><div class="detail-label">Last authenticated</div><div class="detail-value">{{ $client->last_authenticated_at ? $client->last_authenticated_at->timezone(config('attention.display_timezone'))->format('d M Y H:i:s') : 'Belum pernah' }}</div></div>
        <div class="detail"><div class="detail-label">Public ID</div><div class="detail-value mono">{{ $client->public_id }}</div></div>
        <div class="detail"><div class="detail-label">Terdaftar</div><div class="detail-value">{{ $client->created_at->timezone(config('attention.display_timezone'))->format('d M Y H:i:s') }}</div></div>
    </div></section>
    <section class="card"><div class="card-header"><h2 class="card-title">Pengelolaan client</h2><p class="card-description">Perubahan token langsung membatalkan token lama.</p></div><div class="card-content form-grid">
        @if ($client->active)<form method="post" action="{{ route('admin.clients.disable', $client) }}">@csrf<button class="btn btn-danger" style="width:100%" type="submit">Disable client</button></form>@else<form method="post" action="{{ route('admin.clients.enable', $client) }}">@csrf<button class="btn btn-primary" style="width:100%" type="submit">Enable client</button></form>@endif
        <form method="post" action="{{ route('admin.clients.rotate', $client) }}">@csrf<button class="btn btn-secondary" style="width:100%" type="submit">Rotate token</button></form>
        @if($connection==='offline')<div class="alert alert-danger" style="margin:0">PC tidak mengirim heartbeat. Periksa apakah agent berjalan, jaringan tersambung, Base URL benar, dan token masih valid.</div>@elseif($connection==='never')<div class="alert alert-warning" style="margin:0">Agent belum pernah terhubung. Selesaikan Server Setup dan Configure Credential pada tray client.</div>@endif
    </div></section>
</div>
@endsection
