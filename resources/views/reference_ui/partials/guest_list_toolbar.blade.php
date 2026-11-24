{{-- Guests page toolbar: live search, sort, filter drawer and active-filter chips.
     Everything is a plain GET form, so it works without JavaScript and links can be shared. --}}
@php
    use App\Services\Events\GuestDirectory;

    $l = fn (string $key, array $replace = []) => __('customer_guest_list.'.$key, $replace, $language);
    $p = $params;
    $base = fn (array $override = []) => route('customer.events.invitation.guests', array_filter(['event' => $event->id] + array_merge(
        ['q' => $p['q'], 'pass' => $p['pass'], 'invite' => $p['invite'], 'share' => $p['share'], 'admission' => $p['admission'], 'phone' => $p['phone'], 'added' => $p['added'],
         'entries' => $p['entries'], 'remaining' => $p['remaining'], 'sort' => $p['sort'] === 'name_asc' ? null : $p['sort']], $override),
        fn ($v) => $v !== null && $v !== '' && $v !== []));
    $passLabels = ['single' => __('customer_guests.add.passes.single', [], $language), 'double' => __('customer_guests.add.passes.double', [], $language), 'special' => __('customer_guests.add.passes.special', [], $language)];
    $groups = [
        'invite' => $whatsapp ? GuestDirectory::INVITE : ['generated', 'outdated', 'not_generated'],
        'share' => GuestDirectory::SHARE,
        'admission' => GuestDirectory::ADMISSION,
        'phone' => ['has', 'missing'],
        'added' => ['today', '7d', '30d'],
        'entries' => ['1', '2', '3plus'],
        'remaining' => ['some', 'none'],
    ];
    $chips = [];
    foreach ($p['pass'] as $pass) {
        $chips[] = [$passLabels[$pass], $base(['pass' => array_values(array_diff($p['pass'], [$pass]))])];
    }
    foreach ($groups as $group => $options) {
        if ($p[$group] !== '') {
            $chips[] = [$l($group.'.'.$p[$group]), $base([$group => null])];
        }
    }
@endphp
<div class="gm-toolbar">
    <form class="gm-search" method="GET" autocomplete="off" action="{{ route('customer.events.invitation.guests', ['event' => $event->id]) }}" role="search" data-gm-form>
        <label class="sr-only" for="rc-q">{{ $l('search_placeholder') }}</label>
        <span class="gm-search-field">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input id="rc-q" type="search" name="q" value="{{ $p['q'] }}" placeholder="{{ $l('search_placeholder') }}" maxlength="60" autocomplete="off" enterkeyhint="search" data-gm-q>
            @if ($p['q'] !== '')
                <a class="gm-clear" href="{{ $base(['q' => null]) }}" aria-label="{{ $l('clear_search') }}" data-gm-clear>×</a>
            @endif
        </span>
        <button class="gm-button" type="submit">{{ $l('search') }}</button>
        <label class="gm-sort"><span class="sr-only">{{ $l('sort') }}</span>
            <select name="sort" autocomplete="off" data-gm-sort aria-label="{{ $l('sort') }}">
                @foreach (GuestDirectory::SORTS as $sort)
                    <option value="{{ $sort }}" @selected($p['sort'] === $sort)>{{ $l('sorts.'.$sort) }}</option>
                @endforeach
            </select>
        </label>
        <button class="gm-button gm-filter-toggle" type="button" aria-controls="gm-filters" aria-expanded="false" data-gm-filters-open>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
            {{ $l('filters') }}@if ($filterCount > 0)<span class="gm-count">{{ $filterCount }}</span>@endif
        </button>

        {{-- Filter drawer (a side sheet on phones, a panel on desktop). Without JS it is simply shown. --}}
        <div class="gm-filters" id="gm-filters" role="dialog" aria-modal="false" aria-labelledby="gm-filters-title" data-gm-filters>
            <span class="gm-handle" aria-hidden="true"></span>
            <div class="gm-filters-head">
                <h2 id="gm-filters-title">{{ $l('filters_title') }}@if ($filterCount > 0)<span class="gm-count">{{ $filterCount }}</span>@endif</h2>
                <a class="gm-reset" href="{{ route('customer.events.invitation.guests', array_filter(['event' => $event->id, 'q' => $p['q']])) }}">{{ $l('reset') }}</a>
                <button type="button" class="gm-x" data-gm-filters-close aria-label="{{ $l('close') }}">×</button>
            </div>
            <div class="gm-filters-body">
                {{-- Pills: radio/checkbox inputs styled as chips (keyboard and screen-reader friendly). --}}
                <fieldset class="gm-group">
                    <legend>{{ $l('groups.pass') }}</legend>
                    <div class="gm-pills">
                        @foreach (GuestDirectory::PASSES as $pass)
                            <label class="gm-pill"><input type="checkbox" name="pass[]" value="{{ $pass }}" @checked(in_array($pass, $p['pass'], true))><span>{{ $passLabels[$pass] }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
                @foreach ($groups as $group => $options)
                    @if ($group === 'phone')
                        @php $moreOpen = $p['phone'] !== '' || $p['added'] !== '' || $p['entries'] !== '' || $p['remaining'] !== ''; @endphp
                        <details class="gm-more" @if ($moreOpen) open @endif>
                            <summary>{{ $l('more_filters') }}</summary>
                    @endif
                    <fieldset class="gm-group">
                        <legend>{{ $l('groups.'.$group) }}</legend>
                        <div class="gm-pills">
                            <label class="gm-pill"><input type="radio" name="{{ $group }}" value="" @checked($p[$group] === '')><span>{{ $l('any') }}</span></label>
                            @foreach ($options as $option)
                                <label class="gm-pill"><input type="radio" name="{{ $group }}" value="{{ $option }}" @checked($p[$group] === $option)><span>{{ $l($group.'.'.$option) }}</span></label>
                            @endforeach
                        </div>
                    </fieldset>
                    @if ($group === 'remaining')
                        </details>
                    @endif
                @endforeach
            </div>
            <div class="gm-filters-foot">
                <button class="gm-button gm-primary" type="submit">{{ $l('apply') }}</button>
            </div>
        </div>
    </form>
    @if ($chips !== [])
        <ul class="gm-chips" aria-label="{{ $l('filters') }}">
            @foreach ($chips as [$label, $href])
                <li><a href="{{ $href }}" aria-label="{{ $l('remove_filter', ['name' => $label]) }}">{{ $label }} <span aria-hidden="true">×</span></a></li>
            @endforeach
            <li><a class="gm-chip-clear" href="{{ route('customer.events.invitation.guests', array_filter(['event' => $event->id, 'q' => $p['q']])) }}">{{ $l('clear_all') }}</a></li>
        </ul>
    @endif
</div>
<div class="gm-scrim" data-gm-scrim hidden></div>
