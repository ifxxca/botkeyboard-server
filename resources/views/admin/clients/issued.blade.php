@extends('admin.layout')
@section('title', 'Token client')
@section('content')
<header class="page-header"><div><h1 class="page-title">Token diterbitkan</h1><p class="page-description">Client <strong>{{ $client->name }}</strong> siap dikonfigurasi.</p></div></header>
<div class="alert alert-warning"><strong>Simpan sekarang.</strong>&nbsp; Token ini hanya ditampilkan sekali dan tidak bisa dilihat lagi.</div>
<section class="card" style="max-width:860px">
    <div class="card-header"><h2 class="card-title">Bearer token</h2><p class="card-description">Masukkan token ini melalui tray agent → Configure Credential.</p></div>
    <div class="card-content section-stack">
        <div class="token-box"><code id="issued-token">{{ $plaintext }}</code><button class="btn btn-secondary btn-sm copy" type="button" data-copy="#issued-token">Salin</button></div>
        <div class="form-actions"><a class="btn btn-primary" href="{{ route('admin.clients.show', $client) }}">Lihat client</a><a class="btn btn-secondary" href="{{ route('admin.clients.index') }}">Semua client</a></div>
    </div>
</section>
@endsection
