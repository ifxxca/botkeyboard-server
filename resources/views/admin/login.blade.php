@extends('admin.layout')
@section('title', 'Masuk')
@section('content')
<section class="card">
    <div class="card-header"><h1 class="card-title">Masuk ke admin</h1><p class="card-description">Kelola data attention dan pantau koneksi PC.</p></div>
    <form class="card-content form-grid" method="post" action="{{ route('admin.login.store') }}">
        @csrf
        <label class="field"><span class="label">Email</span><input class="input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
        <label class="field"><span class="label">Password</span><input class="input" type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary" type="submit">Masuk</button>
    </form>
</section>
@endsection
