<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'AWB Attention') · Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@guest
    <main class="auth-shell">
        <div class="auth-card">
            <div class="auth-brand">
                <div class="brand">
                    <span class="brand-mark">A</span>
                    <div><div class="brand-title">AWB Attention</div><div class="brand-subtitle">Central monitoring server</div></div>
                </div>
            </div>
            @if ($errors->any())
                <div class="alert alert-danger"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            @yield('content')
        </div>
    </main>
@else
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="{{ route('admin.home') }}">
                <span class="brand-mark">A</span>
                <div><div class="brand-title">AWB Attention</div><div class="brand-subtitle">Operations console</div></div>
            </a>
            <nav class="nav" aria-label="Navigasi utama">
                <div class="nav-label">Workspace</div>
                <a class="nav-link {{ request()->routeIs('admin.imports.*') ? 'active' : '' }}" href="{{ route('admin.imports.index') }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M4 17v3h16v-3"/></svg> Import data
                </a>
                <a class="nav-link {{ request()->routeIs('admin.items.*') ? 'active' : '' }}" href="{{ route('admin.items.index') }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h6"/></svg> Active items
                </a>
                <a class="nav-link {{ request()->routeIs('admin.clients.*') ? 'active' : '' }}" href="{{ route('admin.clients.index') }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg> Client PCs
                </a>
            </nav>
            <div class="sidebar-footer">
                <div class="user-row"><span class="avatar">{{ strtoupper(substr(auth()->user()->name ?? auth()->user()->email, 0, 1)) }}</span><div class="user-name">{{ auth()->user()->name ?? auth()->user()->email }}</div></div>
                <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="btn btn-ghost" style="width:100%" type="submit">Keluar</button></form>
            </div>
        </aside>
        <main class="main">
            <header class="topbar"><div><div class="topbar-kicker">Central server</div><div class="topbar-title">@yield('title', 'AWB Attention')</div></div><span class="badge badge-online">Server aktif</span></header>
            <div class="content">
                @if (session('status')) <div class="alert alert-success">{{ session('status') }}</div> @endif
                @if ($errors->any()) <div class="alert alert-danger"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif
                @yield('content')
            </div>
        </main>
    </div>
@endguest
</body>
</html>
