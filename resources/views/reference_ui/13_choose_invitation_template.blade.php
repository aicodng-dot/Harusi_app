@php
    use App\Services\Events\InvitationTemplates;
    use App\Services\Invitations\InvitationTemplateCatalog;
    use Illuminate\Support\Carbon;

    $t = fn (string $key, array $replace = []) => __('invitation_template.'.$key, $replace, $language);
    $type = in_array($event->event_type, ['wedding', 'send_off', 'kitchen_party', 'other'], true) ? $event->event_type : null;
    $thumb = ['wedding' => 'Wedding', 'send_off' => 'Send_Off', 'kitchen_party' => 'Kitchen_Party', 'other' => 'Other_Celebration'][$type] ?? null;
    $date = $event->event_date === null ? null : Carbon::parse($event->event_date)->locale($language);
    $time = null;
    if (is_string($event->event_time) && preg_match('/\A(\d{2}):(\d{2})/', $event->event_time, $m)) {
        $hour = (int) $m[1];
        $period = match (true) {
            $hour < 4 || $hour >= 19 => 'night',
            $hour < 7 => 'dawn',
            $hour < 12 => 'morning',
            $hour < 16 => 'afternoon',
            default => 'evening',
        };
        // Swahili time counts from 06:00/18:00 (18:00 is "saa 12 jioni").
        $time = $language === 'sw'
            ? $t('time', ['time' => (($hour + 6) % 12 ?: 12).':'.$m[2]]).' '.$t('periods.'.$period)
            : Carbon::createFromTime($hour, (int) $m[2])->format('g:i A');
    }
    $nameParts = explode(' & ', $event->event_name, 2);
    $label = __('event_dashboard.invitation_label.'.($type ?? 'none'), [], $language);
    $meta = implode(' · ', array_filter([$date?->isoFormat('D MMMM YYYY') ?? $t('date_missing'), filled($event->venue_name) ? $event->venue_name : $t('venue_missing')]));
    $pageUrl = fn (array $query) => route('customer.events.template', array_filter(['event' => $event->id] + $query, fn ($value) => $value !== null && $value !== ''));
    $assets = 'assets/reference_ui/13_Choose_Invitation_Template/';
    $formError = $formErrors['form'] ?? null;
    $templateError = $formErrors['template'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('page_title') }} · {{ $event->event_name }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/13_choose_invitation_template.css', 'resources/css/reference_ui/invitation_steps.css','resources/js/reference_ui/13_choose_invitation_template.js', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body class="tp-body">
@include('reference_ui.partials.customer_shell')
    <div class="tp-page">
        <header class="tp-header">
            <a class="tp-back" href="{{ route('customer.events.dashboard', ['event' => $event->id]) }}" aria-label="{{ $t('back') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
            </a>
            <span class="tp-brand">PangaEvents</span>
            <nav class="tp-languages" aria-label="{{ $t('language') }}">
                @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $code)
                    <a @class(['tp-language', 'is-active' => $language === $locale])
                       href="{{ $pageUrl(['lang' => $locale, 'category' => $category, 'template' => $selected]) }}" lang="{{ $locale }}" hreflang="{{ $locale }}"
                       data-tp-keep @if ($language === $locale) aria-current="true" @endif>{{ $code }}</a>
                @endforeach
            </nav>
        </header>

        <main class="tp-main" aria-labelledby="tp-heading">
            <h1 id="tp-heading" class="tp-heading">{{ $t('heading') }}</h1>
            <p class="tp-intro">{{ $t('intro') }}</p>
            @include('reference_ui.partials.invitation_steps', ['stage' => 'design'])

            @if ($saved)
                <p class="tp-alert tp-alert-status" role="status" data-tp-saved>{{ $t('saved', ['template' => InvitationTemplates::name($saved)]) }}</p>
            @endif
            @if ($readOnly)
                <p class="tp-alert tp-alert-info" role="status">{{ $t('read_only') }}</p>
            @endif
            <p id="tp-alert" class="tp-alert tp-alert-error" role="alert" @unless ($formError || $templateError) hidden @endunless>{{ $formError ?? ($templateError ? $t('check_fields') : '') }}</p>

            {{-- Selected event: x48-816 / y332-486, thumb 187x117, Badilisha x659-798. --}}
            <section class="tp-event" aria-label="{{ $t('selected_event') }}" data-event-id="{{ $event->id }}">
                <span @class(['tp-event-thumb', 'is-placeholder' => $thumb === null]) aria-hidden="true">
                    @if ($thumb)
                        <img src="{{ asset('assets/reference_ui/04_Choose_Event_Type/04_Choose_Event_Type_'.$thumb.'.webp') }}" alt="" width="368" height="340">
                    @else
                        <img class="tp-event-mark" src="{{ asset('assets/pangaevents-mark.svg') }}" alt="" width="68" height="76">
                    @endif
                </span>
                <div class="tp-event-body">
                    <p class="tp-event-label">{{ $t('selected_event') }}</p>
                    <h2 class="tp-event-name">{{ $event->event_name }}</h2>
                    <p class="tp-event-meta">{{ $meta }}</p>
                </div>
                <a class="tp-change" href="{{ route('customer.home') }}">{{ $t('change_event') }}</a>
            </section>

            <nav class="tp-chips" aria-label="{{ $t('categories_label') }}">
                @foreach (array_merge(['featured'], InvitationTemplateCatalog::EVENT_CATEGORIES, InvitationTemplateCatalog::STYLE_CATEGORIES) as $option)
                    <a @class(['tp-chip', 'is-active' => $category === $option])
                       href="{{ $pageUrl(['category' => $option, 'template' => $selected, 'q' => $search]) }}"
                       data-tp-keep @if ($category === $option) aria-current="true" @endif>{{ $t('categories.'.$option) }}</a>
                @endforeach
                <a @class(['tp-chip', 'is-active' => $category === null]) href="{{ route('customer.events.template', array_filter(['event' => $event->id, 'category' => 'all', 'template' => $selected, 'q' => $search])) }}" data-tp-keep @if ($category === null) aria-current="true" @endif>{{ $t('categories.all') }}</a>
            </nav>

            <form class="tp-search" method="GET" action="{{ route('customer.events.template', ['event' => $event->id]) }}" role="search">
                <label class="sr-only" for="tp-q">{{ $t('search_label') }}</label>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input id="tp-q" type="search" name="q" value="{{ $search }}" maxlength="60" placeholder="{{ $t('search_placeholder') }}">
                <input type="hidden" name="category" value="{{ $category ?? 'all' }}">
                @if ($selected)<input type="hidden" name="template" value="{{ $selected }}">@endif
                <button class="sr-only" type="submit">{{ $t('search_label') }}</button>
            </form>
            <p class="tp-count">{{ $t('count', ['shown' => count($templates), 'total' => $total, 'library' => $libraryTotal]) }}</p>

            <form class="tp-form" method="POST" action="{{ route('customer.events.template.store', ['event' => $event->id]) }}" novalidate data-tp-form>
                @csrf
                @if ($category)
                    <input type="hidden" name="category" value="{{ $category }}">
                @endif
                @if ($templates === [])
                    <p class="tp-empty">{{ $t('no_templates') }}</p>
                @else
                    <fieldset class="tp-grid" @if ($templateError) aria-describedby="tp-error-template" @endif @disabled($readOnly)>
                        <legend class="sr-only">{{ $t('templates_label') }}</legend>
                        @foreach ($templates as $key => $template)
                            <label @class(['tp-card', 'is-selected' => $selected === $key]) data-template="{{ $key }}">
                                <input class="sr-only" type="radio" name="template" value="{{ $key }}" @checked($selected === $key) data-tp-template>
                                <span class="tp-preview is-rendered" role="img" aria-label="{{ $t('preview_of', ['template' => $template['name'], 'event' => $event->event_name]) }}">
                                    {{-- Rendered by the invitation engine with this event's real details. --}}
                                    <img class="tp-preview-bg" src="{{ route('customer.events.invitation.thumb', ['event' => $event->id, 'template' => $key, 'lang' => $language]) }}" alt="" width="330" height="440" @if ($loop->index > 3) loading="lazy" @endif decoding="async">
                                    <span class="tp-category" aria-hidden="true">{{ isset(InvitationTemplateCatalog::FEATURED[$key]) ? $t('families.'.InvitationTemplateCatalog::FEATURED[$key]) : $t('categories.'.$template['category']) }}</span>
                                    <span class="tp-thumb-error" hidden>{{ $t('thumb_failed') }}</span>
                                    <button type="button" class="tp-zoom" data-tp-preview="{{ $key }}" data-name="{{ $template['name'] }}"
                                            data-src="{{ route('customer.events.invitation.thumb', ['event' => $event->id, 'template' => $key, 'lang' => $language, 'size' => 'large']) }}"
                                            aria-label="{{ $t('preview_of_large', ['template' => $template['name']]) }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M11 8v6M8 11h6"/></svg>
                                        <span>{{ $t('preview') }}</span>
                                    </button>
                                    <span class="tp-badge" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 12.5 4 4 8-9"/></svg></span>
                                </span>
                                <span class="tp-card-foot">
                                    <span class="tp-card-name">{{ $template['name'] }}</span>
                                    @if ($event->invitation_template === $key)
                                        <span class="tp-saved">{{ $t('current_design') }}</span>
                                    @endif
                                    <span class="tp-radio" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 12.5 4 4 8-9"/></svg></span>
                                </span>
                            </label>
                        @endforeach
                    </fieldset>
                @endif
                <p id="tp-error-template" class="tp-error" @unless ($templateError) hidden @endunless>{{ $templateError }}</p>
                @if ($hasMore)
                    <a class="tp-more" href="{{ $pageUrl(['category' => $category ?? 'all', 'template' => $selected, 'q' => $search, 'page' => $pages + 1]) }}#tp-more-{{ $pages }}" id="tp-more-{{ $pages }}" data-tp-keep>{{ $t('load_more') }}</a>
                @endif
                @if ($event->invitation_template)
                    <a class="tp-more" href="{{ route('customer.events.invitation', ['event' => $event->id]) }}">{{ $t('edit_saved') }}</a>
                @endif

                {{-- One primary action; when the saved design is still selected it simply continues. --}}
                <button class="tp-submit" type="submit" data-tp-submit data-submitting-label="{{ $t('submitting') }}" data-current="{{ $event->invitation_template }}"
                        data-label-use="{{ $t('submit') }}" data-label-continue="{{ $t('continue_current') }}" @disabled($readOnly) @if ($readOnly) data-read-only @endif>
                    <span class="tp-submit-label">{{ $selected !== null && $selected === $event->invitation_template ? $t('continue_current') : $t('submit') }}</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg>
                </button>
            </form>
        </main>
    </div>
    {{-- Large preview, rendered on demand by the same engine as the final cards. --}}
    <dialog class="tp-dialog" id="tp-dialog" aria-labelledby="tp-dialog-title">
        <div class="tp-dialog-head">
            <h2 id="tp-dialog-title" class="tp-dialog-title"></h2>
            <button type="button" class="tp-dialog-close" data-tp-close aria-label="{{ $t('close') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="tp-dialog-body"><img id="tp-dialog-img" alt="" width="900" height="1200"><p class="tp-thumb-error" id="tp-dialog-error" hidden>{{ $t('thumb_failed') }}</p></div>
        @unless ($readOnly)
            <button type="button" class="tp-submit" data-tp-use>
                <span>{{ $t('submit') }}</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg>
            </button>
        @endunless
    </dialog>
    <script type="application/json" id="tp-messages">@json(['template' => $t('template_required'), 'check' => $t('check_fields')])</script>
</body>
</html>
