@extends('layouts.admin', ['title' => 'Edit Event', 'headerTitle' => 'Edit Event'])

@section('content')
    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="section-kicker">Event management</p>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">{{ $event->event_name }}</h2>
            </div>
            <a href="{{ route('admin.events.index') }}" class="secondary-button">Back to events</a>
        </div>

        @if ($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800">
                Please fix the highlighted fields.
            </div>
        @endif

        @include('admin.events._form')
    </section>
@endsection
