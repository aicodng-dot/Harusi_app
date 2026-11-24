@php
    use Illuminate\Support\Carbon;

    $t = fn (string $key, array $replace = []) => __('customer_share.'.$key, $replace, $language);
    $ev = ['event' => $event->id];
    $stateTone = ['confirmed' => 'ok', 'initiated' => 'warn', 'skipped' => 'muted'];
    $filtered = $filters['channel'] !== '' || $filters['status'] !== '' || $filters['q'] !== '';
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('history.title') }} · {{ $event->event_name }}</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page sh-page">
    <main class="is-main" id="cs-content" aria-labelledby="sh-heading">
        <h1 id="sh-heading" class="is-heading">{{ $t('history.title') }}</h1>
        <p class="is-intro">{{ $t('history.intro') }}</p>

        @if ($sessions !== [] && ! $readOnly)
            <section class="cu-panel" aria-labelledby="sh-unfinished">
                <h2 id="sh-unfinished" class="cu-panel-title">{{ $t('history.unfinished') }}</h2>
                <ul class="sh-runs">
                    @foreach ($sessions as $run)
                        <li>
                            <span class="sh-run-text">
                                <strong>{{ $t('hub.session_line', ['channel' => $t('channels.'.$run->channel), 'done' => $run->confirmed + $run->skipped, 'total' => $run->total]) }}</strong>
                                <progress max="{{ max(1, $run->total) }}" value="{{ $run->confirmed + $run->skipped }}"></progress>
                            </span>
                            <a class="cu-button cu-button-primary" href="{{ route('customer.events.share.sessions.show', $ev + ['session' => $run->id]) }}">{{ $t('hub.continue') }}</a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <form class="sh-filters" method="GET" action="{{ route('customer.events.share.history', $ev) }}" role="search">
            <label class="cu-field sh-filter-q"><span>{{ $t('history.search') }}</span>
                <input type="search" name="q" value="{{ $filters['q'] }}" maxlength="60" autocomplete="off" enterkeyhint="search"></label>
            <label class="cu-field"><span>{{ $t('history.channel') }}</span>
                <select name="channel">
                    <option value="">{{ $t('history.all') }}</option>
                    @foreach (\App\Services\Sharing\ShareMessage::CHANNELS as $channel)
                        <option value="{{ $channel }}" @selected($filters['channel'] === $channel)>{{ $t('channels.'.$channel) }}</option>
                    @endforeach
                </select></label>
            <label class="cu-field"><span>{{ $t('history.status') }}</span>
                <select name="status">
                    <option value="">{{ $t('history.all') }}</option>
                    @foreach (\App\Services\Sharing\ShareRecords::STATUSES as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $t('statuses.'.$status) }}</option>
                    @endforeach
                </select></label>
            <button class="cu-button cu-button-ghost" type="submit">{{ $t('history.filter') }}</button>
        </form>

        @if ($rows->isEmpty())
            <p class="sh-empty">{{ $filtered ? $t('history.empty_filtered') : $t('history.empty') }}</p>
        @else
            <ul class="sh-history">
                @foreach ($rows as $item)
                    <li>
                        <span class="sh-history-main">
                            <strong>{{ $item->name }}</strong>
                            <small>{{ $t('channels.'.$item->channel) }}@if ($item->session_id) · {{ $t('history.in_session') }}@endif
                                · <time datetime="{{ Carbon::parse($item->updated_at)->toIso8601String() }}">{{ Carbon::parse($item->updated_at)->locale($language)->isoFormat('D MMM, HH:mm') }}</time>
                                @if ($item->attempts > 1) · {{ $t('guest.attempts', ['count' => $item->attempts]) }}@endif</small>
                        </span>
                        <span class="is-pill {{ $stateTone[$item->status] ?? 'muted' }}">{{ $t('statuses.'.$item->status) }}</span>
                        @unless ($readOnly)
                            <a class="sh-history-retry" href="{{ route('customer.events.share.guest', $ev + ['guest' => $item->guest_id]) }}">{{ $t('history.retry') }}</a>
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
        <p class="sh-note">{{ $t('honesty') }}</p>
    </main>
</div>
</body>
</html>
