@extends('layouts.admin', ['title' => 'Edit Guest', 'headerTitle' => 'Edit Guest'])

@section('content')
    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="section-kicker">Guest management</p>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Edit guest pass</h2>
            </div>
            <a href="{{ route('admin.guests.show', $guest) }}" class="secondary-button">View guest</a>
        </div>

        @if ($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800">
                Please fix the highlighted fields.
            </div>
        @endif

        @include('admin.guests._form')
    </section>
@endsection
