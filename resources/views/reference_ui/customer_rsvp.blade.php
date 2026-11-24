@php
    use App\Services\Rsvp\RsvpService;
    use Illuminate\Support\Carbon;

    $o = fn (string $key, array $replace = []) => __('rsvp.owner.'.$key, $replace, $language);
    $ev = ['event' => $event->id];
    $tone = ['attending' => 'ok', 'not_attending' => 'bad', 'pending' => 'warn'];
    $people = fn (int $n) => trans_choice('rsvp.owner.people', $n, ['count' => $n], $language);
    $localTime = fn ($utc, string $format = 'D MMM, HH:mm') => $utc ? Carbon::parse($utc, 'UTC')->setTimezone(RsvpService::TIMEZONE)->locale($language)->isoFormat($format) : '';
    $filtered = $filters['response'] !== '' || $filters['q'] !== '';
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $o('title') }} · {{ $event->event_name }}</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page sh-page">
    <main class="is-main" id="cs-content" aria-labelledby="rv-heading">
        <h1 id="rv-heading" class="is-heading">{{ $o('title') }}</h1>
        <p class="is-intro">{{ $o('intro') }}</p>

        @if (session('status'))<p class="sh-note is-ok" role="status">{{ session('status') }}</p>@endif
        @if (session('error'))<p class="sh-note is-bad" role="alert">{{ session('error') }}</p>@endif
        @if ($errors->any())<p class="sh-note is-bad" role="alert">{{ $errors->first() }}</p>@endif

        <dl class="sh-stats rv-stats">
            @foreach (['attending' => 'is-ok', 'not_attending' => '', 'pending' => 'is-warn', 'invited' => '', 'expected_people' => ''] as $key => $class)
                <div class="sh-stat {{ $class }}"><dt>{{ $o('stats.'.$key) }}</dt><dd>{{ number_format($summary[$key]) }}</dd></div>
            @endforeach
        </dl>
        <p class="cu-help cu-help-small">{{ $o('expected_help') }} {{ $o('invited_help') }}</p>
        <p class="sh-note">
            @if (! $settings['enabled']) {{ $o('disabled') }}
            @elseif (! $open) {{ $o('closed_now') }}
            @elseif ($settings['closes_at']) {{ $o('closes', ['date' => $localTime($settings['closes_at'], 'D MMMM YYYY, HH:mm')]) }}
            @else {{ $o('open_always') }} @endif
        </p>

        @unless ($readOnly)
            <details class="cu-details" @if ($errors->has('deadline')) open @endif>
                <summary>{{ $o('settings') }}</summary>
                <form class="cu-details-body" method="POST" action="{{ route('customer.events.rsvp.settings', $ev) }}">
                    @csrf @method('PUT')
                    <label class="cu-check"><input type="checkbox" name="enabled" value="1" @checked($settings['enabled'])><span>{{ $o('enabled') }}</span></label>
                    <label class="cu-check"><input type="checkbox" name="allow_changes" value="1" @checked($settings['allow_changes'])><span>{{ $o('allow_changes') }}</span></label>
                    <label class="cu-field"><span>{{ $o('deadline') }}</span>
                        <input type="datetime-local" name="deadline" value="{{ RsvpService::local($settings['deadline']) }}"></label>
                    <p class="cu-help cu-help-small">{{ $o('deadline_help') }}</p>
                    <button class="cu-button cu-button-primary" type="submit">{{ $o('save') }}</button>
                </form>
            </details>
        @endunless

        <form class="sh-filters" method="GET" action="{{ route('customer.events.rsvp', $ev) }}" role="search">
            <label class="cu-field sh-filter-q"><span>{{ $o('search') }}</span>
                <input type="search" name="q" value="{{ $filters['q'] }}" maxlength="60" autocomplete="off" enterkeyhint="search"></label>
            <label class="cu-field"><span>{{ $o('filter') }}</span>
                <select name="response">
                    <option value="">{{ $o('all') }}</option>
                    @foreach (RsvpService::STATES as $state)
                        <option value="{{ $state }}" @selected($filters['response'] === $state)>{{ __('rsvp.responses.'.$state, [], $language) }}</option>
                    @endforeach
                </select></label>
            <button class="cu-button cu-button-ghost" type="submit">{{ $o('filter') }}</button>
            <a class="cu-button cu-button-ghost" href="{{ route('customer.events.rsvp.export', $ev) }}">{{ $o('export') }}</a>
        </form>

        @if ($rows->isEmpty())
            <div class="sh-empty">
                <p>{{ $filtered ? $o('empty_filtered') : $o('empty') }}</p>
                @unless ($filtered)<a class="cu-button cu-button-primary" href="{{ route('customer.events.share', $ev) }}">{{ $o('share') }}</a>@endunless
            </div>
        @else
            <ul class="sh-history rv-list">
                @foreach ($rows as $item)
                    <li>
                        <span class="sh-history-main">
                            <strong>{{ $item->name }}</strong>
                            <small>
                                @if ($item->response === 'attending'){{ $people((int) ($item->attending_count ?? 1)) }} · @endif
                                @if ($item->responded_at){{ $localTime($item->response_changed_at ?? $item->responded_at) }} · {{ $o('source.'.$item->response_source) }}@endif
                            </small>
                            @if ($item->note)<q class="rv-note">{{ $item->note }}</q>@endif
                        </span>
                        <span class="is-pill {{ $tone[$item->response] }}">{{ __('rsvp.responses.'.$item->response, [], $language) }}</span>
                        @unless ($readOnly)
                            <details class="rv-record">
                                <summary>{{ $o('record') }}</summary>
                                <form method="POST" action="{{ route('customer.events.rsvp.record', $ev) }}">
                                    @csrf
                                    <input type="hidden" name="guest_id" value="{{ $item->id }}">
                                    <input type="hidden" name="submission" value="{{ bin2hex(random_bytes(16)) }}">
                                    <p class="cu-help cu-help-small">{{ $o('record_help') }}</p>
                                    <div class="rv-record-row">
                                        <select name="response" aria-label="{{ $o('record') }}">
                                            @foreach (RsvpService::RESPONSES as $response)
                                                <option value="{{ $response }}" @selected($item->response === $response)>{{ __('rsvp.responses.'.$response, [], $language) }}</option>
                                            @endforeach
                                        </select>
                                        @if ((int) $item->allowed_entries > 1)
                                            <select name="count" aria-label="{{ __('rsvp.guest.how_many', [], $language) }}">
                                                @for ($n = 1; $n <= min(100, (int) $item->allowed_entries); $n++)
                                                    <option value="{{ $n }}" @selected((int) ($item->attending_count ?? $item->allowed_entries) === $n)>{{ $people($n) }}</option>
                                                @endfor
                                            </select>
                                        @endif
                                        <button class="cu-button cu-button-ghost" type="submit">{{ $o('record') }}</button>
                                    </div>
                                </form>
                            </details>
                        @endunless
                    </li>
                @endforeach
            </ul>
            @if ($rows->hasPages())
                <nav class="gm-pages" aria-label="{{ __('customer_guest_list.pagination', [], $language) }}">
                    @if ($rows->previousPageUrl())<a class="gm-button" href="{{ $rows->previousPageUrl() }}" rel="prev">← {{ __('customer_guest_list.previous', [], $language) }}</a>@else<span></span>@endif
                    <span>{{ __('customer_guest_list.page', ['page' => $rows->currentPage(), 'pages' => $rows->lastPage()], $language) }}</span>
                    @if ($rows->nextPageUrl())<a class="gm-button" href="{{ $rows->nextPageUrl() }}" rel="next">{{ __('customer_guest_list.next', [], $language) }} →</a>@else<span></span>@endif
                </nav>
            @endif
        @endif
    </main>
</div>
</body>
</html>
