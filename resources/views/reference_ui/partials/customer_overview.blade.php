{{-- Account overview on My Events: totals across the customer's events, quick actions for
     the next event and the latest invitation activity. Values come from CustomerHomeController::overview(). --}}
@php
    $o = fn (string $key, array $replace = []) => __('customer_overview.'.$key, $replace, $language);
    $oc = fn (string $key, int $count, array $replace = []) => trans_choice('customer_overview.'.$key, $count, $replace + ['count' => $count], $language);
    $focus = $focusEvent ?? null;
    $percent = $overview['people'] > 0 ? (int) round($overview['admitted'] * 100 / $overview['people']) : 0;
    $fmt = fn (int $n) => number_format($n);
@endphp
<section class="ov" aria-labelledby="ov-title">
    <h2 id="ov-title" class="sr-only">{{ $o('label') }}</h2>
    <div class="ov-stats">
        <div class="ov-stat"><small>{{ $o('events') }}</small><strong>{{ $fmt($overview['events']) }}</strong></div>
        <div class="ov-stat"><small>{{ $o('guests') }}</small><strong>{{ $fmt($overview['guests']) }}</strong><span>{{ $oc('people', $overview['people'], ['count' => $fmt($overview['people'])]) }}</span></div>
        <div class="ov-stat"><small>{{ $o('cards') }}</small><strong>{{ $fmt($overview['cards']) }}</strong></div>
        <div class="ov-stat">
            <small>{{ $o('admitted') }}</small><strong>{{ $fmt($overview['admitted']) }}</strong>
            <span class="ov-meter" role="img" aria-label="{{ $percent }}%"><span style="width: {{ $percent }}%"></span></span>
            <span>{{ $o('remaining', ['count' => $fmt($overview['remaining'])]) }}</span>
        </div>
    </div>
    <div class="ov-columns">
        <div class="ov-box">
            <h3 class="ov-box-title">{{ $o('quick') }}@if ($focus)<small>{{ $o('next_for', ['event' => \Illuminate\Support\Str::limit((string) $focus->event_name, 36)]) }}</small>@endif</h3>
            <div class="ov-actions">
                @if ($focus)
                    <a class="ov-action is-primary" href="{{ route('customer.events.guests.create', ['event' => $focus->id]) }}"><span aria-hidden="true">＋</span>{{ $o('actions.add_guests') }}</a>
                    <a class="ov-action" href="{{ route('customer.events.template', ['event' => $focus->id]) }}"><span aria-hidden="true">✎</span>{{ $o('actions.design') }}</a>
                    <a class="ov-action" href="{{ route('customer.events.invitation.guests', ['event' => $focus->id]) }}"><span aria-hidden="true">⤓</span>{{ $o('actions.cards') }}</a>
                @endif
                <a class="ov-action" href="{{ route('customer.events.create') }}"><span aria-hidden="true">＋</span>{{ $o('actions.create') }}</a>
                <a class="ov-action" href="{{ route('customer.help') }}"><span aria-hidden="true">?</span>{{ $o('actions.help') }}</a>
            </div>
        </div>
        <div class="ov-box">
            <h3 class="ov-box-title">{{ $o('activity') }}</h3>
            @if ($overview['activity'] === [])
                <p class="ov-empty">{{ $o('no_activity') }}</p>
            @else
                <ul class="ov-activity">
                    @foreach ($overview['activity'] as $item)
                        @php
                            $guest = $item->guest_name ?: $o('a_guest');
                            $key = \Illuminate\Support\Facades\Lang::has('customer_overview.action.'.$item->action, $language) ? $item->action : 'other';
                            $label = in_array($key, ['guests_imported', 'guests_added'], true) ? $oc('action.'.$key, $item->count) : $o('action.'.$key, ['guest' => $guest]);
                        @endphp
                        <li>
                            <span class="ov-dot" aria-hidden="true"></span>
                            <span class="ov-activity-text"><strong>{{ $label }}</strong><small>{{ \Illuminate\Support\Str::limit((string) $item->event_name, 30) }} · <time datetime="{{ \Illuminate\Support\Carbon::parse($item->created_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($item->created_at)->locale($language)->diffForHumans() }}</time></small></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</section>
