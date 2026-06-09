<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Sistem Pelaporan LM') }} - @yield('title', 'Dashboard')</title>

    <!-- Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        :root {
            --color-primary: #0f4c3a;
            --color-primary-dark: #0a3326;
            --color-primary-light: #155c47;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
        }

        /* Header */
        .app-header {
            background-color: var(--color-primary);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .app-header-left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .app-logo {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .app-title {
            font-size: 1.1rem;
            font-weight: 500;
        }

        .app-header-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-badge {
            background-color: rgba(255,255,255,0.2);
            padding: 0.4rem 0.8rem;
            border-radius: 4px;
            font-size: 0.85rem;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: white;
            color: var(--color-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        /* Layout Container */
        .app-container {
            display: flex;
            min-height: calc(100vh - 64px);
        }

        /* Sidebar */
        .app-sidebar {
            width: 240px;
            background-color: white;
            box-shadow: 2px 0 4px rgba(0,0,0,0.05);
            padding: 1.5rem 0;
        }

        .sidebar-nav {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .sidebar-nav-item {
            margin: 0;
        }

        .sidebar-nav-link {
            display: block;
            padding: 0.75rem 1.5rem;
            color: #333;
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }

        .sidebar-nav-link:hover {
            background-color: #f5f5f5;
            color: var(--color-primary);
        }

        .sidebar-nav-link.active {
            background-color: #e8f5f1;
            color: var(--color-primary);
            border-left-color: var(--color-primary);
            font-weight: 600;
        }

        /* Main Content */
        .app-main {
            flex: 1;
            padding: 2rem;
            background-color: #f5f5f5;
        }

        /* Filter Bar */
        .filter-bar {
            background-color: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #555;
        }

        .filter-input, .filter-select {
            padding: 0.5rem 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
        }

        .filter-select {
            background-color: white;
            cursor: pointer;
        }

        /* Report Card */
        .report-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .report-header {
            background-color: var(--color-primary);
            color: white;
            padding: 1.5rem;
        }

        .report-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0 0 1rem 0;
        }

        .report-meta {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        /* KPI Strip */
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            background-color: #f8f9fa;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e0e0e0;
        }

        .kpi-item {
            text-align: center;
        }

        .kpi-label {
            font-size: 0.75rem;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }

        .kpi-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--color-primary);
        }

        .kpi-unit {
            font-size: 0.9rem;
            color: #888;
            margin-left: 0.25rem;
        }

        /* Toolbar */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e0e0e0;
        }

        .toolbar-left, .toolbar-right {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            background-color: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .btn:hover {
            background-color: #f5f5f5;
            border-color: var(--color-primary);
            color: var(--color-primary);
        }

        .btn-primary {
            background-color: var(--color-primary);
            color: white;
            border-color: var(--color-primary);
        }

        .btn-primary:hover {
            background-color: var(--color-primary-dark);
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            background-color: white;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            margin-bottom: -2px;
        }

        .tab:hover {
            background-color: #f5f5f5;
        }

        .tab.active {
            border-bottom-color: var(--color-primary);
            color: var(--color-primary);
            font-weight: 600;
        }

        .tab-content {
            display: none;
            padding: 1.5rem;
        }

        .tab-content.active {
            display: block;
        }

        /* Search Input */
        .search-input {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 250px;
        }

        /* Utility Classes */
        .hidden {
            display: none;
        }
    </style>

    @stack('styles')
</head>
<body>
    <!-- Header -->
    <header class="app-header">
        <div class="app-header-left">
            <div class="app-logo">PTPN IV</div>
            <div class="app-title">Sistem Pelaporan LM - Regional V</div>
        </div>
        <div class="app-header-right">
            <div class="user-badge">{{ auth()->user()->role ?? 'Guest' }}</div>
            <div class="user-profile">
                <div class="user-avatar">{{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}</div>
                <span>{{ auth()->user()->name ?? 'User' }}</span>
            </div>
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
                            📊 KEBUN
                        </a>
                    </li>
                    <li class="sidebar-nav-item">
                        <a href="{{ route('pabrik') }}" class="sidebar-nav-link {{ request()->routeIs('pabrik') ? 'active' : '' }}">
                            🏭 PABRIK
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
