@php
    $isEdit = $userRecord->exists;
    $selectedRole = old('role', $userRecord->role ?: \App\Models\User::ROLE_SCANNER);
    $selectedEventId = old('event_id', $userRecord->event_id);
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.users.update', $userRecord) : route('admin.users.store') }}" class="app-card p-5">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <label class="field-label">
            Name
            <input name="name" value="{{ old('name', $userRecord->name) }}" class="input-control" autocomplete="off" required>
            @error('name')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Email
            <input type="email" name="email" value="{{ old('email', $userRecord->email) }}" class="input-control" autocomplete="off" required>
            @error('email')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Role
            <select name="role" class="input-control" data-user-role required>
                <option value="{{ \App\Models\User::ROLE_SCANNER }}" @selected($selectedRole === \App\Models\User::ROLE_SCANNER)>Scanner</option>
                <option value="{{ \App\Models\User::ROLE_ADMIN }}" @selected($selectedRole === \App\Models\User::ROLE_ADMIN)>Admin</option>
            </select>
            @error('role')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Phone number (optional)
            <input type="tel" name="phone_number" value="{{ old('phone_number', $userRecord->phone_number) }}" class="input-control" autocomplete="tel" placeholder="0712345678">
            <span class="text-xs font-semibold text-zinc-500">The account can use this number or its email to sign in.</span>
            @error('phone_number')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Preferred language
            <select name="preferred_language" class="input-control" required>
                <option value="sw" @selected(old('preferred_language', $userRecord->preferred_language ?? 'sw') === 'sw')>Kiswahili</option>
                <option value="en" @selected(old('preferred_language', $userRecord->preferred_language ?? 'sw') === 'en')>English</option>
            </select>
            @error('preferred_language')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <div class="field-label">
            <input type="hidden" name="is_active" value="0">
            <label class="inline-flex items-center gap-2">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $userRecord->is_active ?? true))>
                Account active
            </label>
            <span class="text-xs font-semibold text-zinc-500">Inactive accounts cannot sign in or continue using the website.</span>
            @error('is_active')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </div>

        <label class="field-label">
            Gate name
            <input name="gate_name" value="{{ old('gate_name', $userRecord->gate_name) }}" class="input-control" autocomplete="off" data-gate-name>
            <span class="text-xs font-semibold text-zinc-500">Required for scanner users. Admin users do not need a gate.</span>
            @error('gate_name')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Assigned event
            <select name="event_id" class="input-control">
                <option value="">Choose an event</option>
                @foreach ($activeEvents as $event)
                    <option value="{{ $event->id }}" @selected((string) $selectedEventId === (string) $event->id)>{{ $event->event_name }}@if ($event->workspace_id !== null) — Customer: {{ $event->workspace?->name ?? 'workspace #'.$event->workspace_id }}@endif</option>
                @endforeach
            </select>
            <span class="text-xs font-semibold text-zinc-500">Scanner users scan and admit guests for this event. If only one event is available, it is selected automatically.</span>
            @error('event_id')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            {{ $isEdit ? 'Reset password' : 'Password' }}
            <input type="password" name="password" class="input-control" autocomplete="new-password" @required(! $isEdit)>
            <span class="text-xs font-semibold text-zinc-500">
                {{ $isEdit ? 'Leave blank to keep the current password.' : 'Minimum 8 characters.' }}
            </span>
            @error('password')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Confirm password
            <input type="password" name="password_confirmation" class="input-control" autocomplete="new-password" @required(! $isEdit)>
        </label>
    </div>

    <div class="mt-6 flex flex-wrap gap-2">
        <button type="submit" class="primary-button">{{ $isEdit ? 'Save changes' : 'Create user' }}</button>
        <a href="{{ route('admin.users.index') }}" class="secondary-button">Cancel</a>
    </div>
</form>
