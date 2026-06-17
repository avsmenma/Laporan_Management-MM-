<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        // Terapkan status sidebar (buka/tutup) sebelum render agar tidak berkedip.
        try { if (localStorage.getItem('lm-sidebar-collapsed') === '1') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}
    </script>
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
            background: #fff; padding: 3px; box-sizing: border-box; overflow: hidden;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.12); flex: none;
        }
        .brand-mark img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
        .brand-text { line-height: 1.15; min-width: 0; }
        .brand-name { font-weight: 600; font-size: 14.5px; color: #fff; white-space: nowrap; }
        .brand-sub { font-size: 11px; color: #9dc3b4; letter-spacing: .02em; white-space: nowrap; }
        .app-header-right { display: flex; align-items: center; gap: 12px; }

        /* day KPIs inside the topbar */
        .topbar-kpis { display: flex; align-items: center; gap: 22px; }
        .topbar-kpis .tk { text-align: center; line-height: 1; }
        .topbar-kpis .tk .tk-l {
            display: block; font-size: 10px; letter-spacing: .05em; text-transform: uppercase;
            color: #9dc3b4; font-weight: 600; margin-bottom: 4px;
        }
        .topbar-kpis .tk b { font-family: var(--font-mono); font-size: 17px; font-weight: 700; color: #fff; }
        .topbar-kpis .tk b small { font-size: 11px; color: #9dc3b4; font-weight: 500; margin-left: 3px; }

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
        .sidebar-parent { width: 100%; background: transparent; border: none; cursor: pointer; font-family: inherit; }
        .nav-caret { width: 14px; height: 14px; margin-left: auto; flex: none; transition: transform .15s; opacity: .7; }
        .nav-caret.open { transform: rotate(90deg); }
        /* submenu bergaya struktur folder (tree) */
        .sidebar-subnav { list-style: none; margin: 2px 0 8px; padding: 0; position: relative; }
        .sidebar-subnav li { position: relative; margin: 0; padding-left: 38px; }
        .sidebar-subnav li::before { content: ""; position: absolute; left: 26px; top: 0; height: 100%; border-left: 1.5px solid var(--line-strong); }
        .sidebar-subnav li:last-child::before { height: 16px; }
        .sidebar-subnav li::after { content: ""; position: absolute; left: 26px; top: 16px; width: 12px; border-top: 1.5px solid var(--line-strong); }
        .sidebar-sublink {
            display: flex; align-items: center; gap: 7px; padding: 7px 10px; border-radius: 7px; text-decoration: none;
            color: var(--ink-500); font-size: 12.5px; font-weight: 600; transition: background .15s, color .15s;
        }
        .sidebar-sublink .tree-ico { font-size: 12px; flex: none; }
        .sidebar-sublink:hover { background: var(--g-50); color: var(--g-800); }
        .sidebar-sublink.active { background: var(--g-50); color: var(--g-800); box-shadow: inset 2px 0 0 var(--g-700); }

        /* ---------------- Main content ---------------- */
        .app-main { flex: 1; min-width: 0; padding: 24px 26px 56px; background: var(--bg); }

        /* ---------------- Mode layar penuh (fokus, hanya area web) ---------------- */
        /* Tanpa CSS zoom (zoom merusak header Tabulator). Sebagai gantinya kolom & font
           dikecilkan (mode compact via JS) dan tinggi tabel diisi penuh ke bawah. */
        body.lm-focus .app-header,
        body.lm-focus .app-sidebar { display: none !important; }
        body.lm-focus .app-container { min-height: 100vh; }
        body.lm-focus .app-main { padding: 10px 14px 12px; }
        body.lm-focus .filter-bar { margin-bottom: 10px; padding: 12px 14px; }
        body.lm-focus .report-card { box-shadow: none; }
        body.lm-focus .lm-report-table .tabulator { font-size: 8.5px; }
        body.lm-focus .lm-report-table .tabulator-header .tabulator-col,
        body.lm-focus .lm-report-table .tabulator-header .tabulator-col .tabulator-col-title,
        body.lm-focus .lm-report-table .tabulator-header .tabulator-col-group .tabulator-col-title { font-size: 8.5px !important; letter-spacing: 0; }
        body.lm-focus .lm-report-table .tabulator-row .tabulator-cell { padding: 2px 5px; }

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

        /* ---------------- Tab bar (tabs + actions in one row, reference style) ---------------- */
        .report-tabbar {
            display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; flex-wrap: wrap;
            padding: 10px 16px 0; background: var(--surface-2); border-bottom: 1px solid var(--line);
        }
        .tabs { display: flex; gap: 4px; align-items: flex-end; }
        .tab {
            height: 38px; padding: 0 16px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
            font-size: 13px; font-weight: 600; color: var(--ink-500); border: 1px solid transparent; border-bottom: none;
            border-radius: 9px 9px 0 0; position: relative; top: 1px; background: transparent;
            transition: color .14s, background .14s; margin-bottom: 0;
        }
        .tab svg { width: 15px; height: 15px; flex: none; }
        .tab:hover { color: var(--ink-800); background: #e9edea; }
        .tab.active {
            color: var(--g-800); background: var(--surface); border-color: var(--line);
            box-shadow: 0 -2px 0 var(--g-700) inset;
        }

        .report-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding-bottom: 9px; }

        .tab-content { display: none; padding: 0; }
        .tab-content.active { display: block; }

        .hidden { display: none; }

        /* ---------------- Header report controls (tab + aksi dropdown) ---------------- */
        .lm-header-controls { display: flex; align-items: center; gap: 10px; }
        .lm-hc { display: flex; align-items: center; gap: 10px; }
        .lm-hc-select {
            height: 32px; padding: 0 30px 0 12px; border-radius: 7px; cursor: pointer; appearance: none;
            font-family: inherit; font-size: 12.5px; font-weight: 600; color: #eaf2ee;
            background-color: rgba(255,255,255,.10); border: 1px solid rgba(255,255,255,.18);
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23cfe6db' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>");
            background-repeat: no-repeat; background-position: right 9px center;
        }
        .lm-hc-select option { color: #14342a; }
        .lm-hc-static {
            display: inline-flex; align-items: center; height: 32px; padding: 0 14px; border-radius: 7px;
            font-size: 12.5px; font-weight: 600; color: #eaf2ee;
            background: rgba(255,255,255,.10); border: 1px solid rgba(255,255,255,.18);
        }
        .lm-menu { position: relative; }
        .lm-hc-btn {
            display: inline-flex; align-items: center; gap: 7px; height: 32px; padding: 0 13px; border-radius: 7px;
            font-family: inherit; font-size: 12.5px; font-weight: 600; color: #14342a; cursor: pointer;
            background: #eaf2ee; border: 1px solid rgba(255,255,255,.2);
        }
        .lm-hc-btn:hover { background: #fff; }
        .lm-hc-btn svg { width: 14px; height: 14px; flex: none; }
        .lm-menu-pop {
            position: absolute; right: 0; top: calc(100% + 6px); min-width: 184px; z-index: 60;
            background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 6px;
            box-shadow: 0 12px 30px rgba(0,0,0,.18); display: flex; flex-direction: column; gap: 2px;
        }
        .lm-menu-item {
            display: flex; align-items: center; gap: 8px; width: 100%; text-align: left; height: 34px; padding: 0 12px;
            border: none; background: transparent; border-radius: 7px; cursor: pointer; font-family: inherit;
            font-size: 13px; font-weight: 500; color: var(--ink-700);
        }
        .lm-menu-item:hover { background: var(--g-50); color: var(--g-800); }
        .lm-menu-sep { height: 1px; background: var(--line); margin: 4px 2px; }

        /* tombol keluar layar penuh — hanya tampil saat mode fokus (header tersembunyi) */
        .lm-focus-exit { display: none; }
        body.lm-focus .lm-focus-exit {
            display: inline-flex; align-items: center; gap: 6px; position: fixed; top: 10px; right: 12px; z-index: 70;
            height: 32px; padding: 0 13px; border-radius: 8px; cursor: pointer; font-family: inherit;
            font-size: 12.5px; font-weight: 600; color: #fff; background: var(--g-800); border: 1px solid var(--g-900);
            box-shadow: var(--shadow-sm);
        }
        .lm-focus-exit:hover { background: var(--g-700); }
        .lm-focus-exit svg { width: 14px; height: 14px; }

        /* ---------------- Sidebar: tombol keluar di bawah ---------------- */
        .app-sidebar { display: flex; flex-direction: column; }
        .sidebar-spacer { flex: 1 1 auto; }
        .sidebar-logout { border-top: 1px solid var(--line); padding-top: 12px; margin-top: 12px; }
        .sidebar-logout button {
            display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 14px; border-radius: 8px;
            font-family: inherit; font-size: 13px; font-weight: 600; color: var(--ink-600); cursor: pointer;
            background: transparent; border: 1px solid var(--line);
        }
        .sidebar-logout button:hover { background: var(--g-50); color: var(--g-800); border-color: var(--g-100); }

        /* ---------------- Sidebar: tombol buka/tutup ---------------- */
        .app-sidebar { transition: width .18s ease, padding .18s ease; }
        .sidebar-toggle {
            display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px;
            border-radius: 8px; cursor: pointer; flex: none; color: #dff0e8; padding: 0;
            background: rgba(255,255,255,.10); border: 1px solid rgba(255,255,255,.16);
        }
        .sidebar-toggle:hover { background: rgba(255,255,255,.18); color: #fff; }
        .sidebar-toggle svg { width: 18px; height: 18px; }

        /* Saat sidebar ditutup: hanya ikon yang tampil; konten menyesuaikan (flex). */
        html.sidebar-collapsed .app-sidebar { width: 64px; padding: 16px 8px; }
        html.sidebar-collapsed .sidebar-nav-link { justify-content: center; padding: 10px; font-size: 0; gap: 0; }
        html.sidebar-collapsed .sidebar-nav-link .nav-ico { font-size: 16px; }
        html.sidebar-collapsed .nav-caret { display: none; }
        html.sidebar-collapsed .sidebar-subnav { display: none !important; }
        html.sidebar-collapsed .sidebar-logout button { justify-content: center; font-size: 0; gap: 0; padding: 10px; }

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
            <button type="button" class="sidebar-toggle" onclick="lmToggleSidebar()" aria-label="Buka/Tutup menu" title="Buka/Tutup menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="brand-mark"><img src="{{ asset('images/logo-ptpn4.png') }}" alt="Logo PTPN IV"></div>
            <div class="brand-text">
                <div class="brand-name">PT. Perkebunan Nusantara</div>
                <div class="brand-sub">Sistem Pelaporan LM &middot; Regional V</div>
            </div>
        </div>

        <div class="topbar-spacer"></div>

        <div class="app-header-right">
            <div id="lm-header-controls" class="lm-header-controls"></div>
            <div class="user-profile">
                @php
                    $lmName = auth()->user()->name ?? 'User';
                    $lmParts = preg_split('/\s+/', trim($lmName));
                    $lmInitials = strtoupper(substr($lmParts[0] ?? 'U', 0, 1) . (isset($lmParts[1]) ? substr($lmParts[1], 0, 1) : ''));
                @endphp
                <div class="user-avatar">{{ $lmInitials ?: 'U' }}</div>
                <div class="user-meta">
                    <div class="user-name">{{ $lmName }}</div>
                </div>
            </div>
        </div>
    </header>

    <!-- Tombol keluar mode layar penuh (tampil hanya saat fokus, karena header disembunyikan) -->
    <button type="button" class="lm-focus-exit" onclick="document.body.classList.remove('lm-focus'); window.dispatchEvent(new Event('lm-focus-changed'))">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
        Keluar Layar Penuh (Esc)
    </button>

    <!-- Main Container -->
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="app-sidebar">
            <nav>
                <ul class="sidebar-nav">
                    <li class="sidebar-nav-item" x-data="{ open: {{ request()->routeIs('kebun*') ? 'true' : 'false' }} }">
                        <button type="button" class="sidebar-nav-link sidebar-parent {{ request()->routeIs('kebun*') ? 'active' : '' }}" @click="if (document.documentElement.classList.contains('sidebar-collapsed')) { lmToggleSidebar(); open = true } else { open = !open }">
                            <span class="nav-ico">📁</span> KEBUN
                            <svg class="nav-caret" :class="{ 'open': open }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                        <ul class="sidebar-subnav" x-show="open" x-cloak>
                            <li><a href="{{ route('kebun') }}" class="sidebar-sublink {{ request()->routeIs('kebun') ? 'active' : '' }}"><span class="tree-ico">📄</span> LM Eksploitasi</a></li>
                            <li><a href="{{ route('kebun.investasi') }}" class="sidebar-sublink {{ request()->routeIs('kebun.investasi') ? 'active' : '' }}"><span class="tree-ico">📄</span> LM Investasi</a></li>
                        </ul>
                    </li>
                    <li class="sidebar-nav-item" x-data="{ open: {{ request()->routeIs('pabrik*') ? 'true' : 'false' }} }">
                        <button type="button" class="sidebar-nav-link sidebar-parent {{ request()->routeIs('pabrik*') ? 'active' : '' }}" @click="if (document.documentElement.classList.contains('sidebar-collapsed')) { lmToggleSidebar(); open = true } else { open = !open }">
                            <span class="nav-ico">📁</span> PABRIK
                            <svg class="nav-caret" :class="{ 'open': open }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                        <ul class="sidebar-subnav" x-show="open" x-cloak>
                            <li><a href="{{ route('pabrik') }}" class="sidebar-sublink {{ request()->routeIs('pabrik') ? 'active' : '' }}"><span class="tree-ico">📄</span> LM Eksploitasi</a></li>
                            <li><a href="{{ route('pabrik.investasi') }}" class="sidebar-sublink {{ request()->routeIs('pabrik.investasi') ? 'active' : '' }}"><span class="tree-ico">📄</span> LM Investasi</a></li>
                        </ul>
                    </li>
                    @if (in_array(optional(optional(auth()->user())->role)->name, ['Operator', 'Admin'], true))
                        <li class="sidebar-nav-item" style="margin-top:10px;border-top:1px solid var(--line);padding-top:10px">
                            <a href="{{ route('import.index') }}" class="sidebar-nav-link {{ request()->routeIs('import.*') ? 'active' : '' }}">
                                <span class="nav-ico">⬆️</span> IMPORT
                            </a>
                        </li>
                        <li class="sidebar-nav-item">
                            <a href="{{ route('batches.index') }}" class="sidebar-nav-link {{ request()->routeIs('batches.*') ? 'active' : '' }}">
                                <span class="nav-ico">🗂️</span> BATCH
                            </a>
                        </li>
                        <li class="sidebar-nav-item">
                            <a href="{{ route('database.index') }}" class="sidebar-nav-link {{ request()->routeIs('database.*') ? 'active' : '' }}">
                                <span class="nav-ico">🗃️</span> DATABASE
                            </a>
                        </li>
                    @endif
                    @if (auth()->user()?->hasRole('Admin'))
                        <li class="sidebar-nav-item">
                            <a href="{{ route('data.index') }}" class="sidebar-nav-link {{ request()->routeIs('data.*') ? 'active' : '' }}">
                                <span class="nav-ico">🗑️</span> HAPUS DATA
                            </a>
                        </li>
                    @endif
                </ul>
            </nav>
            <div class="sidebar-spacer"></div>
            <div class="sidebar-logout">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">
                        <span style="font-size:15px">🚪</span> Keluar
                    </button>
                </form>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="app-main">
            @yield('content')
        </main>
    </div>

    @stack('scripts')
    <script>
        // Buka/tutup sidebar; status disimpan agar konsisten antar-halaman.
        function lmToggleSidebar() {
            const collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
            try { localStorage.setItem('lm-sidebar-collapsed', collapsed ? '1' : '0'); } catch (e) {}
            // Setelah transisi lebar selesai, beri tahu tabel (Tabulator) agar menyesuaikan.
            setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 220);
        }
        window.lmToggleSidebar = lmToggleSidebar;

        // Esc keluar dari mode layar penuh (fallback selain tombol mengambang).
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains('lm-focus')) {
                document.body.classList.remove('lm-focus');
                window.dispatchEvent(new Event('lm-focus-changed'));
            }
        });
    </script>
</body>
</html>
