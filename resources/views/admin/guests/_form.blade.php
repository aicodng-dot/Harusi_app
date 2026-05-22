@php
    $isEdit = $guest->exists;
    $selectedPass = old('pass_type', $guest->pass_type ?: 'single');
    $allowedEntries = old('allowed_entries', $guest->allowed_entries ?: 1);
    $usedEntries = (int) ($guest->used_entries ?? 0);
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.guests.update', $guest) : route('admin.guests.store') }}" class="app-card p-5">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <label class="field-label">
            Guest name
            <input name="name" value="{{ old('name', $guest->name) }}" class="input-control" autocomplete="off" required>
            @error('name')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Phone number
            <input name="phone_number" value="{{ old('phone_number', $guest->phone_number) }}" class="input-control" inputmode="tel" autocomplete="off" required>
            @error('phone_number')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Pass type
            <select name="pass_type" class="input-control" data-pass-type required>
                <option value="single" @selected($selectedPass === 'single')>Single pass</option>
                <option value="double" @selected($selectedPass === 'double')>Double pass</option>
                <option value="special" @selected($selectedPass === 'special')>Special / Family pass</option>
            </select>
            @error('pass_type')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Number of guests allowed
            <input type="number" name="allowed_entries" value="{{ $allowedEntries }}" min="1" max="10" class="input-control" data-allowed-admissions data-used-entries="{{ $usedEntries }}" required>
            <span class="text-xs font-semibold text-zinc-500">
                Single = 1, Double = 2, Special / Family = 3 to 10.
                @if ($usedEntries > 0)
                    This pass already has {{ $usedEntries }} used {{ $usedEntries === 1 ? 'entry' : 'entries' }}.
                @endif
            </span>
            @error('allowed_entries')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>
    </div>

    <div class="mt-6 flex flex-wrap gap-2">
        <button type="submit" class="primary-button">{{ $isEdit ? 'Save changes' : 'Create guest' }}</button>
        <a href="{{ $isEdit ? route('admin.guests.show', $guest) : route('admin.guests.index') }}" class="secondary-button">Cancel</a>
    </div>
</form>
