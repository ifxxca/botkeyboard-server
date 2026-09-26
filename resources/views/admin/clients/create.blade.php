@extends('admin.layout')
@section('title', 'Daftarkan PC')
@section('content')
<header class="page-header"><div><h1 class="page-title">Daftarkan PC baru</h1><p class="page-description">Buat identitas client dan bearer token untuk satu instalasi AWB Input Monitor.</p></div><a class="btn btn-secondary" href="{{ route('admin.clients.index') }}">Kembali</a></header>
<section class="card" style="max-width:680px">
    <div class="card-header"><h2 class="card-title">Identitas client</h2><p class="card-description">Gunakan nama lokasi yang mudah dikenali, misalnya PC-OUTBOUND-01.</p></div>
    <form class="card-content form-grid" method="post" action="{{ route('admin.clients.store') }}">@csrf
        <label class="field"><span class="label">Client name</span><input class="input" name="name" value="{{ old('name') }}" maxlength="120" placeholder="PC-OUTBOUND-01" required><span class="help">Bearer token akan muncul sekali setelah client dibuat.</span></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Buat client & token</button><a class="btn btn-ghost" href="{{ route('admin.clients.index') }}">Batal</a></div>
    </form>
</section>
@endsection
