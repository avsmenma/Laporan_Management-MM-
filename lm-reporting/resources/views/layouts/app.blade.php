<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth
        <meta name="lm-report-user" content="{{ auth()->id() }}">
        <meta name="lm-report-token" content="{{ hash_hmac('sha256', auth()->id().'|'.auth()->user()->email.'|'.auth()->user()->role_id, config('app.key')) }}">
    @endauth
    <title>{{ config('app.name', 'Sistem Pelaporan LM') }} - @yield('title', 'Dashboard')</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        [x-cloak] { display: none !important; }

        body {
            font-family: var(--font-sans);
            margin: 0;
            padding: 0;
            color: var(--ink-800);
            background-color: var(--bg);
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        /* ---------------- Topbar ---------------- */
        .app-header {
            height: 60px;
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 0 22px;
            background: var(--g-900);
            color: #eaf2ee;
            border-bottom: 1px solid var(--g-950);
            position: sticky;
            top: 0;
            z-index: 40;
        }
        .app-header-left { display: flex; align-items: center; gap: 16px; min-width: 0; }
        .brand-mark {
            width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center;
            background: linear-gradient(150deg, #1d765b, #0f4c3a); color: #fff; font-weight: 700;
            font-size: 14px; letter-spacing: .02em; box-shadow: inset 0 0 0 1px rgba(255,255,255,.12); flex: none;
        }
        .brand-text { line-height: 1.15; min-width: 0; }
        .brand-name { font-weight: 600; font-size: 14.5px; color: #fff; white-space: nowrap; }
        .brand-sub { font-size: 11px; color: #9dc3b4; letter-spacing: .02em; white-space: nowrap; }
        .topbar-divider { width: 1px; height: 26px; background: rgba(255,255,255,.14); flex: none; }
        .topbar-unit { font-size: 12px; color: #bcd7cc; white-space: nowrap; }
        .topbar-unit b { color: #fff; font-weight: 600; margin-left: 4px; }
        .app-header-right { display: flex; align-items: center; gap: 12px; margin-left: auto; }

        .role-badge {
            display: inline-flex; align-items: center; gap: 7px; height: 28px; padding: 0 11px;
            border-radius: 100px; white-space: nowrap; flex: none; font-size: 11.5px; font-weight: 600;
            background: rgba(255,255,255,.10); color: #dff0e8; border: 1px solid rgba(255,255,255,.14);
        }
        .role-badge .dot { width: 7px; height: 7px; border-radius: 50%; background: #5fd0a4; flex: none; }
        .user-profile { display: flex; align-items: center; gap: 9px; cursor: default; }
        .user-avatar {
            width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center;
            font-weight: 600; font-size: 12px; background: #dfeee7; color: var(--g-800);
            border: 1px solid rgba(255,255,255,.2); flex: none;
        }
        .user-meta { line-height: 1.15; }
        .user-name { font-size: 12.5px; color: #fff; font-weight: 600; white-space: nowrap; }
        .user-role { font-size: 11px; color: #a9cbbd; white-space: nowrap; }
        .logout-btn {
            display: inline-flex; align-items: center; height: 30px; padding: 0 12px; border-radius: 7px;
            font-size: 12.5px; font-weight: 600; background: rgba(255,255,255,.10); color: #dff0e8;
            border: 1px solid rgba(255,255,255,.14); cursor: pointer; font-family: inherit;
        }
        .logout-btn:hover { background: rgba(255,255,255,.18); color: #fff; }

        /* ---------------- Layout container ---------------- */
        .app-container { display: flex; min-height: calc(100vh - 60px); }

        /* ---------------- Sidebar ---------------- */
        .app-sidebar {
            width: 232px; flex: none; background: var(--surface);
            border-right: 1px solid var(--line); padding: 16px 12px;
        }
        .sidebar-nav { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
        .sidebar-nav-item { margin: 0; }
        .sidebar-nav-link {
            display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 8px;
            color: var(--ink-600); text-decoration: none; font-size: 13px; font-weight: 600;
            transition: background .15s, color .15s;
        }
        .sidebar-nav-link:hover { background: var(--g-50); color: var(--g-800); }
        .sidebar-nav-link.active { background: var(--g-50); color: var(--g-800); box-shadow: inset 2px 0 0 var(--g-700); }
        .sidebar-nav-link .nav-ico {
            width: 28px; height: 28px; border-radius: 7px; display: grid; place-items: center; flex: none;
            background: var(--surface-2); border: 1px solid var(--line); font-size: 14px;
        }
        .sidebar-nav-link.active .nav-ico { background: #fff; border-color: var(--g-100); }

        /* ---------------- Main content ---------------- */
        .app-main { flex: 1; min-width: 0; padding: 24px 26px 56px; background: var(--bg); }

        /* ---------------- Filter bar ---------------- */
        .filter-bar {
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
            box-shadow: var(--shadow-sm); padding: 18px; margin-bottom: 18px;
        }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; }
        .filter-group { display: flex; flex-direction: column; gap: 7px; }
        .filter-label {
            font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-500);
        }
        .filter-input, .filter-select {
            height: 42px; padding: 0 13px; border: 1px solid var(--line-strong); border-radius: 9px;
            font-family: inherit; font-size: 13.5px; color: var(--ink-900); background: var(--surface);
            transition: border-color .15s, box-shadow .15s;
        }
        .filter-select { cursor: pointer; appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236a756f' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>");
            background-repeat: no-repeat; background-position: right 12px center; padding-right: 34px;
        }
        .filter-input:focus, .filter-select:focus { outline: none; border-color: var(--g-600); box-shadow: 0 0 0 3px var(--g-50); }
        .filter-meta { font-size: 11.5px; color: var(--ink-500); margin-top: 2px; }

        /* ---------------- Report card ---------------- */
        .report-card {
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
            box-shadow: var(--shadow-sm); overflow: hidden;
        }
        .report-header { padding: 16px 18px; border-bottom: 1px solid var(--line); background: linear-gradient(180deg, #f3f8f5, #fff); }
        .report-title { font-size: 17px; font-weight: 700; color: var(--g-900); letter-spacing: -.01em; margin: 0; }
        .report-meta { font-size: 12.5px; color: var(--ink-600); margin-top: 4px; }

        /* ---------------- KPI strip ---------------- */
        .kpi-strip {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 0;
            background: var(--surface-2); border-bottom: 1px solid var(--line);
        }
        .kpi-item { padding: 14px 18px; text-align: center; border-left: 1px solid var(--line); }
        .kpi-item:first-child { border-left: none; }
        .kpi-label { font-size: 10.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-500); margin-bottom: 6px; }
        .kpi-value { font-size: 24px; font-weight: 700; color: var(--g-700); font-family: var(--font-mono); line-height: 1; }
        .kpi-unit { font-size: 12px; color: var(--ink-400); margin-left: 4px; font-family: var(--font-sans); font-weight: 500; }

        /* ---------------- Toolbar ---------------- */
        .toolbar {
            display: flex; justify-content: space-between; align-items: center; gap: 12px;
            padding: 12px 16px; border-bottom: 1px solid var(--line); background: var(--surface-2); flex-wrap: wrap;
        }
        .toolbar-left, .toolbar-right { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            height: 34px; padding: 0 14px; border: 1px solid var(--line-strong); background: var(--surface);
            color: var(--ink-700); border-radius: 7px; cursor: pointer; font-size: 12.5px; font-weight: 600;
            font-family: inherit; transition: border-color .14s, color .14s, background .14s;
        }
        .btn:hover { border-color: var(--g-500); color: var(--g-700); background: var(--g-50); }
        .btn-primary { background: var(--g-800); color: #fff; border-color: var(--g-800); box-shadow: var(--shadow-sm); }
        .btn-primary:hover { background: var(--g-700); border-color: var(--g-700); color: #fff; }

        .search-input {
            height: 34px; padding: 0 13px; border: 1px solid var(--line); border-radius: 7px;
            width: 250px; font-family: inherit; font-size: 13px; color: var(--ink-800); background: var(--surface);
        }
        .search-input:focus { outline: none; border-color: var(--g-600); box-shadow: 0 0 0 3px var(--g-50); }

        /* ---------------- Tabs (reference style) ---------------- */
        .tabs {
            display: flex; gap: 4px; align-items: flex-end; padding: 10px 16px 0;
            background: var(--surface-2); border-bottom: 1px solid var(--line);
        }
        .tab {
            height: 38px; padding: 0 18px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
            font-size: 13px; font-weight: 600; color: var(--ink-500); border: 1px solid transparent; border-bottom: none;
            border-radius: 9px 9px 0 0; position: relative; top: 1px; background: transparent;
            transition: color .14s, background .14s; margin-bottom: 0;
        }
        .tab:hover { color: var(--ink-800); background: #e9edea; }
        .tab.active {
            color: var(--g-800); background: var(--surface); border-color: var(--line);
            box-shadow: 0 -2px 0 var(--g-700) inset;
        }

        .tab-content { display: none; padding: 0; }
        .tab-content.active { display: block; }

        .hidden { display: none; }

        @media (max-width: 820px) {
            .app-sidebar { width: 64px; padding: 16px 8px; }
            .sidebar-nav-link { justify-content: center; padding: 10px; font-size: 0; gap: 0; }
            .sidebar-nav-link .nav-ico { font-size: 16px; }
            .app-main { padding: 18px 14px 48px; }
        }
    </style>

    @stack('styles')
</head>
<body>
    <!-- Header / Topbar -->
    <header class="app-header">
        <div class="app-header-left">
            <div class="brand-mark">PN</div>
            <div class="brand-text">
                <div class="brand-name">PT. Perkebunan Nusantara</div>
                <div class="brand-sub">Sistem Pelaporan LM &middot; Regional V</div>
            </div>
        </div>

        <div class="topbar-divider"></div>
        <div class="topbar-unit" x-data="{ unit: '' }" x-cloak x-show="unit"
             @lm-topbar-unit.window="unit = $event.detail.label">
            @yield('unit-label', 'Unit')<b x-text="unit"></b>
        </div>

        <div class="app-header-right">
            <span class="role-badge"><span class="dot"></span>{{ auth()->user()?->role?->name ?? 'Guest' }}</span>
            <div class="user-profile">
                @php
                    $lmName = auth()->user()->name ?? 'User';
                    $lmParts = preg_split('/\s+/', trim($lmName));
                    $lmInitials = strtoupper(substr($lmParts[0] ?? 'U', 0, 1) . (isset($lmParts[1]) ? substr($lmParts[1], 0, 1) : ''));
                @endphp
                <div class="user-avatar">{{ $lmInitials ?: 'U' }}</div>
                <div class="user-meta">
                    <div class="user-name">{{ $lmName }}</div>
                    <div class="user-role">{{ auth()->user()?->role?->name ?? 'Guest' }}</div>
                </div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="logout-btn">Keluar</button>
            </form>
        </div>
    </header>

    <!-- Main Container -->
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="app-sidebar">
            <nav>
                <ul class="sidebar-nav">
                    <li class="sidebar-nav-item">
                        <a href="{{ route('kebun') }}" class="sidebar-nav-link {{ request()->routeIs('kebun') ? 'active' : '' }}">
                            <span class="nav-ico">🌱</span> KEBUN
                        </a>
                    </li>
                    <li class="sidebar-nav-item">
                        <a href="{{ route('pabrik') }}" class="sidebar-nav-link {{ request()->routeIs('pabrik') ? 'active' : '' }}">
                            <span class="nav-ico">🏭</span> PABRIK
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="app-main">
            @yield('content')
        </main>
    </div>

    @stack('scripts')
</body>
</html>
