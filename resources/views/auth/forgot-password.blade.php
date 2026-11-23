<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Forgot password - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-950">
    <main class="grid min-h-screen place-items-center px-4">
        <section class="w-full max-w-md rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
            <h1 class="text-lg font-bold">Forgot your password?</h1>
            <p class="mt-2 text-sm text-zinc-600">Enter your account email or phone number. Password reset links are sent by email.</p>

            @if (session('status'))
                <div role="status" class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="mt-6 grid gap-4">
                @csrf
                <label class="field-label">
                    Email or phone number
                    <input name="identifier" type="text" autocomplete="username" class="input-control" placeholder="you@example.com or 0712345678" maxlength="255" required autofocus>
                </label>
                <button type="submit" class="primary-button w-full">Send reset link</button>
            </form>

            <p class="mt-4 text-sm text-zinc-600">If you cannot access your registered email, contact your account administrator.</p>
            <a href="{{ route('login') }}" class="mt-4 inline-block text-sm font-semibold text-emerald-700 underline">Back to sign in</a>
        </section>
    </main>
</body>
</html>
