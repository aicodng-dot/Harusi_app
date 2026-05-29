@php
    $isEdit = $event->exists;
    $selectedStatus = old('status', $event->status ?: \App\Models\Event::STATUS_ACTIVE);
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.events.update', $event) : route('admin.events.store') }}" class="app-card p-5">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <label class="field-label">
            Event name
            <input name="event_name" value="{{ old('event_name', $event->event_name) }}" class="input-control" autocomplete="off" required>
            @error('event_name')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Event date
            <input type="date" name="event_date" value="{{ old('event_date', $event->event_date?->format('Y-m-d')) }}" class="input-control">
            @error('event_date')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Bride name
            <input name="bride_name" value="{{ old('bride_name', $event->bride_name) }}" class="input-control" autocomplete="off">
            @error('bride_name')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Groom name
            <input name="groom_name" value="{{ old('groom_name', $event->groom_name) }}" class="input-control" autocomplete="off">
            @error('groom_name')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Venue
            <input name="venue_name" value="{{ old('venue_name', $event->venue_name) }}" class="input-control" autocomplete="off">
            @error('venue_name')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="field-label">
            Status
            <select name="status" class="input-control" required>
                <option value="active" @selected($selectedStatus === 'active')>Active</option>
                <option value="archived" @selected($selectedStatus === 'archived')>Archived</option>
                <option value="cancelled" @selected($selectedStatus === 'cancelled')>Cancelled</option>
            </select>
            @error('status')
                <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
            @enderror
        </label>
    </div>

    <div class="mt-6 flex flex-wrap gap-2">
        <button type="submit" class="primary-button">{{ $isEdit ? 'Save event' : 'Create event' }}</button>
        <a href="{{ route('admin.events.index') }}" class="secondary-button">Cancel</a>
    </div>
</form>
