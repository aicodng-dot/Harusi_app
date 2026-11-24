{{-- Guests page results: a data table on desktop that becomes compact cards on phones.
     Swapped in place by the live search (the region is re-rendered on the server). --}}
@php
    use Illuminate\Support\Carbon;

    $l = fn (string $key, array $replace = []) => __('customer_guest_list.'.$key, $replace, $language);
    $t = fn (string $key, array $replace = []) => __('invitation_studio.'.$key, $replace, $language);
    $messageTone = ['pending' => 'warn', 'sent' => 'info', 'delivered' => 'ok', 'read' => 'ok', 'failed' => 'bad', 'skipped' => 'muted', 'no_phone' => 'muted'];
    $cardTone = ['none' => 'muted', 'current' => 'ok', 'stale' => 'warn'];
    $admissionTone = ['not_checked_in' => 'muted', 'partial' => 'info', 'full' => 'ok', 'cancelled' => 'bad'];
    $from = ($result['page'] - 1) * \App\Services\Events\GuestDirectory::PER_PAGE + 1;
    $to = $from + $guests->count() - 1;
    $pageUrl = fn (int $page) => request()->fullUrlWithQuery(['page' => $page > 1 ? $page : null]);
@endphp
<div id="gm-results" class="gm-results" aria-live="polite" data-gm-results data-matching="{{ json_encode(array_values($result['ids'])) }}">
    @if ($guests->isEmpty())
        <div class="gm-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M8.5 11h5"/></svg>
            <p class="gm-empty-title">{{ $l('no_results_title') }}</p>
            <p>{{ $t('guests.no_results') }} {{ $l('no_results_text') }}</p>
            <a class="gm-button" href="{{ route('customer.events.invitation.guests', ['event' => $event->id]) }}">{{ $l('clear_all') }}</a>
        </div>
    @else
        <div class="gm-summary">
            <span><strong data-gm-showing data-template="{{ $l('showing', ['from' => $from, 'to' => '__TO__', 'matching' => $result['matching']]) }}" data-from="{{ $from }}">{{ $l('showing', ['from' => $from, 'to' => $to, 'matching' => $result['matching']]) }}</strong> · {{ $l('of_total', ['total' => $result['total']]) }}</span>
            @if ($result['truncated'])<span class="gm-warn">{{ $l('truncated', ['max' => \App\Services\Events\GuestDirectory::MAX_MATCHES]) }}</span>@endif
        </div>
        @unless ($readOnly)
            <div class="gm-selectbar">
                <label class="gm-option"><input type="checkbox" id="rc-all" data-gm-page-all><span>{{ $l('select_page') }}</span></label>
                @if ($result['matching'] > $guests->count())
                    <button type="button" class="gm-link" data-gm-select-matching data-label="{{ $l('select_matching', ['count' => count($result['ids'])]) }}" data-selected="{{ $l('selected_matching', ['count' => count($result['ids'])]) }}">{{ $l('select_matching', ['count' => count($result['ids'])]) }}</button>
                @endif
                <button type="button" class="gm-link" data-gm-clear-selection hidden>{{ $l('clear_selection') }}</button>
            </div>
        @endunless
        <table class="gm-table">
            <thead>
                <tr>
                    <th scope="col" class="gm-col-check"><span class="sr-only">{{ $l('select_page') }}</span></th>
                    <th scope="col">{{ $l('columns.guest') }}</th>
                    <th scope="col">{{ $l('columns.phone') }}</th>
                    <th scope="col">{{ $l('columns.pass') }}</th>
                    <th scope="col" class="gm-num">{{ $l('columns.allowed') }}</th>
                    <th scope="col" class="gm-num">{{ $l('columns.used') }}</th>
                    <th scope="col" class="gm-num">{{ $l('columns.remaining') }}</th>
                    <th scope="col">{{ $l('columns.invitation') }}</th>
                    <th scope="col">{{ $l('columns.admission') }}</th>
                    <th scope="col" class="gm-col-added">{{ $l('columns.added') }}</th>
                    <th scope="col"><span class="sr-only">{{ $l('columns.actions') }}</span></th>
                </tr>
            </thead>
            <tbody id="rc-list">
                @foreach ($guests as $guest)
                    <tr class="gm-row">
                        <td class="gm-col-check"><label class="rc-check"><input type="checkbox" value="{{ $guest->id }}" data-rc-guest @disabled($readOnly || $guest->status === 'cancelled')><span class="sr-only">{{ $guest->name }}</span></label></td>
                        <th scope="row" class="gm-guest">
                            <span class="gm-guest-inner">
                            <span class="rc-avatar t{{ $guest->id % 4 }}" aria-hidden="true">{{ str_replace(' & ', '', $guest->initials) }}</span>
                            <span class="gm-guest-text">
                                <a class="rc-name" href="{{ route('customer.events.invitation.guest', ['event' => $event->id, 'guest' => $guest->id]) }}">{{ $guest->name }}</a>
                                @if ($guest->code !== '')<small>{{ $l('code', ['code' => $guest->code]) }}</small>@endif
                            </span>
                            </span>
                        </th>
                        <td class="gm-phone" data-label="{{ $l('columns.phone') }}">{{ $guest->phone !== '' ? $guest->phone : $l('no_phone') }}</td>
                        <td class="gm-pass" data-label="{{ $l('columns.pass') }}">{{ $t('guests.passes.'.$guest->pass_type, ['count' => $guest->allowed]) }}</td>
                        <td class="gm-num gm-hide-sm">{{ $guest->allowed }}</td>
                        <td class="gm-num gm-hide-sm">{{ $guest->used }}</td>
                        <td class="gm-num gm-hide-sm">{{ $guest->remaining }}</td>
                        <td class="gm-invite" data-label="{{ $l('columns.invitation') }}">
                            @if ($guest->status === 'cancelled')
                                <span class="is-pill bad">{{ $t('guests.cancelled') }}</span>
                            @else
                                <span class="is-pill {{ $cardTone[$guest->card] }}">{{ $t('guests.card_states.'.$guest->card) }}</span>
                            @endif
                            @if ($guest->rsvp)
                                <span class="is-pill {{ ['attending' => 'ok', 'not_attending' => 'bad', 'pending' => 'muted'][$guest->rsvp] }}">{{ __('rsvp.responses.'.$guest->rsvp, [], $language) }}</span>
                            @endif
                            @if ($guest->share)
                                <span class="is-pill {{ ['confirmed' => 'ok', 'initiated' => 'warn', 'skipped' => 'muted'][$guest->share] }}">{{ __('customer_share.statuses.'.$guest->share, [], $language) }}</span>
                            @endif
                            @if ($guest->message)
                                <span class="is-pill {{ $messageTone[$guest->message] ?? 'muted' }}">{{ $t('guests.message_states.'.$guest->message) }}</span>
                            @endif
                        </td>
                        <td class="gm-admission" data-label="{{ $l('columns.admission') }}">
                            <span class="is-pill {{ $admissionTone[$guest->admission] }}">{{ $l('admission.'.$guest->admission) }}</span>
                            <span class="gm-meter" role="img" aria-label="{{ $l('admitted_of', ['used' => $guest->used, 'allowed' => $guest->allowed]) }}"><span style="width: {{ $guest->allowed > 0 ? min(100, (int) round($guest->used * 100 / $guest->allowed)) : 0 }}%"></span></span>
                            <small>{{ $l('admitted_of', ['used' => $guest->used, 'allowed' => $guest->allowed]) }}</small>
                        </td>
                        <td class="gm-added gm-hide-sm gm-col-added">@if ($guest->created_at)<time datetime="{{ Carbon::parse($guest->created_at)->toDateString() }}">{{ Carbon::parse($guest->created_at)->locale($language)->isoFormat('D MMM YYYY') }}</time>@endif</td>
                        <td class="gm-actions">
                            <a href="{{ route('customer.events.invitation.guest', ['event' => $event->id, 'guest' => $guest->id]) }}" aria-label="{{ $t('guests.view', ['name' => $guest->name]) }}">{{ $l('view_card') }}</a>
                            @if (! $readOnly && $guest->status !== 'cancelled')<a class="gm-share" href="{{ route('customer.events.share.guest', ['event' => $event->id, 'guest' => $guest->id]) }}" aria-label="{{ __('customer_share.guest.title', [], $language) }}: {{ $guest->name }}">{{ $l('share') }}</a>@endif
                            @unless ($readOnly)<a class="cm-inline-edit" href="{{ route('customer.events.guests.edit', ['event' => $event->id, 'guest' => $guest->id]) }}" aria-label="{{ __('customer_manage.guest.edit', [], $language) }}: {{ $guest->name }}">{{ $l('edit') }}</a>@endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($result['pages'] > 1)
            <nav class="gm-pages" aria-label="{{ $l('pagination') }}" data-more="{{ $l('load_more') }}" data-loading="{{ $l('loading_more') }}">
                @if ($result['page'] > 1)<a class="gm-button" href="{{ $pageUrl($result['page'] - 1) }}" rel="prev" data-gm-page>← {{ $l('previous') }}</a>@else<span></span>@endif
                <span>{{ $l('page', ['page' => $result['page'], 'pages' => $result['pages']]) }}</span>
                @if ($result['page'] < $result['pages'])<a class="gm-button" href="{{ $pageUrl($result['page'] + 1) }}" rel="next" data-gm-page>{{ $l('next') }} →</a>@else<span></span>@endif
            </nav>
        @endif
    @endif
</div>
