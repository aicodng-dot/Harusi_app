@php
    $t = fn (string $key, array $replace = []) => __('customer_guests.'.$key, $replace, $language);
    $problems = $review === null ? [] : array_values(array_filter($review['rows'], fn ($row) => $row['status'] !== 'ready'));
    $shown = array_slice($problems, 0, 50);
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('import.page_title') }} · {{ $event->event_name }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/invitation_steps.css', 'resources/js/reference_ui/customer_guests.js', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page">
    <header class="is-header">
        <a class="is-back" href="{{ route('customer.events.invitation.guests', ['event' => $event->id]) }}" aria-label="{{ $t('back') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
        </a>
        <span class="is-brand">PangaEvents</span>
        <nav class="is-languages" aria-label="{{ $t('language') }}">
            @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $code)
                <a @class(['is-language', 'is-active' => $language === $locale]) href="{{ route('customer.events.guests.import', ['event' => $event->id, 'lang' => $locale]) }}" lang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $code }}</a>
            @endforeach
        </nav>
    </header>

    <main class="is-main" aria-labelledby="ig-heading">
        <h1 id="ig-heading" class="is-heading">{{ $t('import.title') }}</h1>
        <p class="is-intro">{{ $t('import.intro') }}</p>
        @include('reference_ui.partials.invitation_steps', ['stage' => 'guests'])

        @if ($readOnly)
            <p class="is-alert is-alert-info" role="status">{{ $t('read_only') }}</p>
        @endif
        @if ($error)
            <p class="is-alert is-alert-error" role="alert">{{ $t('import.errors.'.$error, ['max' => $maxRows]) }}</p>
        @endif

        @if ($review === null)
            <form class="ig-upload" method="POST" action="{{ route('customer.events.guests.import.preview', ['event' => $event->id]) }}" enctype="multipart/form-data">
                @csrf
                <span class="ig-cloud" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M7 18a4.500 4.500 0 0 1-.6-8.960A6 6 0 0 1 18 10.500 3.800 3.800 0 0 1 17.500 18"/><path d="M12 19v-7m-3 3 3-3 3 3"/></svg></span>
                <h2 class="ig-title">{{ $t('import.upload_title') }}</h2>
                <p class="ig-help">{{ $t('import.upload_help') }}</p>
                <a class="ig-template" href="{{ route('customer.events.guests.import.sample', ['event' => $event->id]) }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m-4-4 4 4 4-4M5 20h14"/></svg>{{ $t('import.template') }}</a>
                <label class="ig-drop" for="ig-file">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/></svg>
                    <span id="ig-file-name" data-chosen="{{ $t('import.chosen', ['name' => '__NAME__']) }}">{{ $t('import.choose') }}</span>
                </label>
                <input class="sr-only" id="ig-file" type="file" name="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required @disabled($readOnly) data-ig-file>
                <p class="ig-help" style="font-size:13px">{{ $t('import.columns') }}</p>
                <div class="is-actions" style="margin-top:16px">
                    <button type="submit" class="is-primary" @disabled($readOnly)>{{ $t('import.check') }}
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg>
                    </button>
                </div>
            </form>
            <p class="ag-note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 5 6v5c0 4.500 3 8 7 10 4-2 7-5.500 7-10V6z"/><path d="m9 12 2 2 4-4.500"/></svg>
                <span>{{ $t('import.note') }}</span>
            </p>
        @else
            <h2 class="st-subhead">{{ $t('import.summary') }}</h2>
            <p class="ig-help" style="text-align:left">{{ $t('import.summary_help', ['file' => $review['file']]) }}</p>
            <div class="ig-tiles">
                <div class="st-tile muted"><small>{{ $t('import.total') }}</small><strong>{{ $counts['total'] }}</strong></div>
                <div class="st-tile ok"><small>{{ $t('import.ready') }}</small><strong>{{ $counts['ready'] }}</strong></div>
                <div class="st-tile bad"><small>{{ $t('import.invalid') }}</small><strong>{{ $counts['invalid'] }}</strong></div>
                <div class="st-tile warn"><small>{{ $t('import.duplicate') }}</small><strong>{{ $counts['duplicate'] }}</strong></div>
            </div>

            @if ($problems !== [])
                <h2 class="st-subhead">{{ $t('import.problems') }}</h2>
                <ul class="ig-problems">
                    @foreach ($shown as $row)
                        <li>
                            <strong>{{ $t('import.row', ['row' => $row['row']]) }}@if ($row['name'] !== '') · {{ $row['name'] }}@endif</strong>
                            @if ($row['status'] === 'duplicate')
                                <span>{{ $t('import.row_duplicate') }}</span>
                            @else
                                @foreach ($row['errors'] as $field)<span>{{ $t('errors.'.$field) }}</span>@endforeach
                            @endif
                        </li>
                    @endforeach
                    @if (count($problems) > count($shown))
                        <li><span>{{ $t('import.more', ['count' => count($problems) - count($shown)]) }}</span></li>
                    @endif
                </ul>
            @endif

            <p class="ag-note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 5 6v5c0 4.500 3 8 7 10 4-2 7-5.500 7-10V6z"/><path d="m9 12 2 2 4-4.500"/></svg>
                <span>{{ $t('import.note') }}</span>
            </p>
            <div class="is-actions">
                @if ($review['token'])
                    <form method="POST" action="{{ route('customer.events.guests.import.confirm', ['event' => $event->id]) }}">
                        @csrf
                        <input type="hidden" name="token" value="{{ $review['token'] }}">
                        <button type="submit" class="is-primary">{{ trans_choice('customer_guests.import.confirm', $counts['ready'], ['count' => $counts['ready']], $language) }}
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg>
                        </button>
                    </form>
                @else
                    <p class="is-alert is-alert-warn" role="status" style="margin:0">{{ $t('import.nothing') }}</p>
                @endif
                <a class="is-secondary" href="{{ route('customer.events.guests.import', ['event' => $event->id]) }}">{{ $t('import.another') }}</a>
            </div>
            {{-- Every row of the file (including the ones with problems) can be corrected on the review screen. --}}
            <form method="POST" action="{{ route('customer.events.guests.review', ['event' => $event->id]) }}" style="margin-top:12px">
                @csrf
                <input type="hidden" name="mode" value="rows">
                <input type="hidden" name="rows_json" value="{{ json_encode(array_map(fn ($r) => array_map(fn ($v) => mb_substr((string) $v, 0, 120), array_intersect_key($r['data'] ?? [], array_flip(['name', 'phone_number', 'pass_type', 'allowed_entries']))), $review['rows']), JSON_UNESCAPED_UNICODE) }}">
                <button type="submit" class="is-secondary" style="width:100%">{{ $t('import_fix') }}</button>
            </form>
        @endif
    </main>
</div>
</body>
</html>
