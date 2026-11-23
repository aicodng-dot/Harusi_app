@extends('layouts.admin', ['title' => 'Edit customer', 'headerTitle' => 'Customers'])

@section('content')
    <section class="space-y-6">
        <div>
            <a href="{{ route('admin.customers.show', $customer->id) }}" class="text-xs font-black uppercase text-emerald-700">&larr; Back to customer</a>
            <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Edit {{ $customer->name }}</h2>
            <p class="mt-1 text-sm font-semibold text-zinc-500">Passwords are never set by administrators; the customer can use "Forgot password" on the sign-in page.</p>
        </div>

        <form method="POST" action="{{ route('admin.customers.update', $customer->id) }}" class="app-card p-5">
            @csrf
            @method('PUT')
            <div class="grid gap-5 lg:grid-cols-2">
                <label class="field-label">Name
                    <input name="name" value="{{ old('name', $customer->name) }}" maxlength="120" class="input-control" autocomplete="off" required>
                    @error('name')<span class="text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                </label>
                <label class="field-label">Email
                    <input type="email" name="email" value="{{ old('email', $customer->email) }}" maxlength="160" class="input-control" autocomplete="off" required>
                    @error('email')<span class="text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                </label>
                <label class="field-label">Phone number
                    <input type="tel" name="phone_number" value="{{ old('phone_number', $customer->phone_number) }}" maxlength="40" class="input-control" placeholder="0712345678">
                    @error('phone_number')<span class="text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                </label>
                <label class="field-label">Preferred language
                    <select name="preferred_language" class="input-control" required>
                        <option value="sw" @selected(old('preferred_language', $customer->preferred_language ?? 'sw') === 'sw')>Kiswahili</option>
                        <option value="en" @selected(old('preferred_language', $customer->preferred_language ?? 'sw') === 'en')>English</option>
                    </select>
                    @error('preferred_language')<span class="text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                </label>
            </div>
            <div class="mt-5 flex flex-wrap gap-3">
                <button type="submit" class="primary-button">Save changes</button>
                <a href="{{ route('admin.customers.show', $customer->id) }}" class="secondary-button">Cancel</a>
            </div>
        </form>
    </section>
@endsection
