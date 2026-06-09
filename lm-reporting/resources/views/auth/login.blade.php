<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Sistem Pelaporan LM</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans">
    <main class="lm-shell flex items-center justify-center px-4 py-10">
        <section class="lm-card w-full max-w-md p-8">
            <div class="mb-8">
                <div class="mb-3 inline-flex h-12 w-12 items-center justify-center rounded bg-[#0f4c3a] text-xl font-bold text-white">PN</div>
                <h1 class="text-2xl font-semibold text-[#0f4c3a]">Sistem Pelaporan LM</h1>
                <p class="mt-2 text-sm text-slate-600">PTPN IV Regional V - Report Viewer</p>
            </div>

            <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
                @csrf

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Email</span>
                    <input name="email" type="email" value="{{ old('email', 'viewer@lm.test') }}" required autofocus
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 focus:border-[#0f4c3a] focus:outline-none focus:ring-2 focus:ring-[#0f4c3a]/20">
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Password</span>
                    <input name="password" type="password" value="password" required
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 focus:border-[#0f4c3a] focus:outline-none focus:ring-2 focus:ring-[#0f4c3a]/20">
                </label>

                @if ($errors->any())
                    <div class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <button class="w-full rounded bg-[#0f4c3a] px-4 py-2.5 font-semibold text-white hover:bg-[#0a3428]">
                    Masuk
                </button>
            </form>

            <div class="mt-6 rounded bg-slate-50 p-4 text-xs leading-6 text-slate-600">
                <div class="font-semibold text-slate-700">Akun seed</div>
                <div>Viewer: viewer@lm.test</div>
                <div>Operator: operator@lm.test</div>
                <div>Admin: admin@lm.test</div>
                <div>Password: password</div>
            </div>
        </section>
    </main>
</body>
</html>
