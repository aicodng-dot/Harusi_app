<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-950">
    <main class="grid min-h-screen place-items-center px-4">
        <section class="w-full max-w-md rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex items-center gap-3">
                <span class="flex size-11 items-center justify-center rounded-lg bg-emerald-600 text-lg font-bold text-white">QR</span>
                <div>
                    <p class="text-lg font-bold">Wedding QR Admission System</p>
                    <p class="text-sm font-medium text-zinc-500">Base login entry</p>
                </div>
            </div>

            @if ($errors->any())
                <div class="mt-6 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="mt-6 grid gap-4">
                @csrf
                <label class="field-label">
                    Email
                    <input name="email" type="email" value="{{ old('email') }}" class="input-control" placeholder="admin@example.com" required autofocus>
                </label>
                <label class="field-label">
                    Password
                    <input name="password" type="password" class="input-control" placeholder="Password" required>
                </label>
                <label class="inline-flex items-center gap-2 text-sm font-semibold text-zinc-600">
                    <input name="remember" type="checkbox" value="1" class="size-4 rounded border-zinc-300 text-emerald-600">
                    Remember me
                </label>

                <button type="submit" class="primary-button w-full">Sign in</button>
            </form>

            <div class="mt-6 rounded-md bg-zinc-100 p-3 text-sm text-zinc-600">
                <p><strong>Admin:</strong> admin@example.com / password</p>
                <p class="mt-1"><strong>Scanner:</strong> scanner@example.com / password</p>
            </div>
        </section>
    </main>
</body>
</html>
