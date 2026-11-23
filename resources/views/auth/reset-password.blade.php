<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Reset password - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-950">
    <main class="grid min-h-screen place-items-center px-4">
        <section class="w-full max-w-md rounded-lg border border-zinc-200 bg-white p-6 shadow-sm">
            <h1 class="text-lg font-bold">Reset your password</h1>
            <p class="mt-2 text-sm text-zinc-600">Choose a password with at least 8 characters.</p>

            @if ($errors->any())
                <div role="alert" class="mt-6 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('password.update') }}" class="mt-6 grid gap-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <label class="field-label">
                    Account email
                    <input name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" class="input-control" required autofocus>
                </label>
                <label class="field-label">
                    New password
                    <input name="password" type="password" autocomplete="new-password" minlength="8" class="input-control" required>
                </label>
                <label class="field-label">
                    Confirm new password
                    <input name="password_confirmation" type="password" autocomplete="new-password" minlength="8" class="input-control" required>
                </label>
                <button type="submit" class="primary-button w-full">Reset password</button>
            </form>

            <a href="{{ route('password.request') }}" class="mt-4 inline-block text-sm font-semibold text-emerald-700 underline">Request a new reset link</a>
        </section>
    </main>
</body>
</html>
