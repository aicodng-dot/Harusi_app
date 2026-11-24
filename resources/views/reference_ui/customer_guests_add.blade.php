@php
    use App\Models\Guest;

    $t = fn (string $key, array $replace = []) => __('customer_guests.'.$key, $replace, $language);
    $tc = fn (string $key, int $count, array $replace = []) => trans_choice('customer_guests.'.$key, $count, $replace + ['count' => $count], $language);
    $passes = [Guest::PASS_SINGLE => $t('add.passes.single'), Guest::PASS_DOUBLE => $t('add.passes.double'), Guest::PASS_SPECIAL => $t('add.passes.special')];
    $methodIcons = [
        'single' => '<path d="m4 20 4-1 11-11-3-3L5 16z"/><path d="m14 7 3 3"/>',
        'names' => '<path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'details' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 10v10M15 10v10"/>',
        'file' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 13l2 2 4-4"/>',
        'contacts' => '<rect x="5" y="2.5" width="14" height="19" rx="2.5"/><circle cx="12" cy="10" r="2.6"/><path d="M8 17a4 4 0 0 1 8 0"/>',
    ];
    $icon = fn (string $name) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$methodIcons[$name].'</svg>';
    $rowsShown = 3;
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('hub.page_title') }} · {{ $event->event_name }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/invitation_steps.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js', 'resources/js/reference_ui/customer_guests_add.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page cu-wide">
    <header class="is-header">
        <a class="is-back" href="{{ route('customer.events.invitation.guests', ['event' => $event->id]) }}" aria-label="{{ $t('back') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
        </a>
        <span class="is-brand">PangaEvents</span>
        <nav class="is-languages" aria-label="{{ $t('language') }}">
            @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $code)
                <a @class(['is-language', 'is-active' => $language === $locale]) href="{{ route('customer.events.guests.create', ['event' => $event->id, 'lang' => $locale, 'method' => $method]) }}" lang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $code }}</a>
            @endforeach
        </nav>
    </header>

    <main class="is-main" aria-labelledby="ga-heading">
        <h1 id="ga-heading" class="is-heading">{{ $t('hub.title') }}</h1>
        <p class="is-intro">{{ $t('hub.intro') }}</p>
        @include('reference_ui.partials.invitation_steps', ['stage' => 'guests'])

        @if (session('guest_notice'))
            <p class="is-alert is-alert-ok" role="status">{{ session('guest_notice') }}</p>
        @endif
        @if ($readOnly)
            <p class="is-alert is-alert-info" role="status">{{ $t('read_only') }}</p>
        @endif
        @foreach ($errors as $field => $code)
            <p class="is-alert is-alert-error" role="alert">{{ $t('add_errors.'.$code, ['max' => $maxRows]) }}</p>
        @endforeach

        <p class="cu-count">
            <span>{{ $tc('hub.count', $guestCount) }}</span>
            <a href="{{ route('customer.events.invitation.guests', ['event' => $event->id]) }}">{{ $t('hub.view_list') }} →</a>
        </p>

        <h2 class="cu-label" id="ga-methods-label">{{ $t('hub.methods_label') }}</h2>
        <div class="cu-methods" role="tablist" aria-labelledby="ga-methods-label" data-ga-tabs>
            @foreach (['single', 'names', 'details', 'file', 'contacts'] as $key)
                <a class="cu-method" id="ga-tab-{{ $key }}" href="{{ route('customer.events.guests.create', ['event' => $event->id, 'method' => $key]) }}#ga-panel-{{ $key }}"
                   role="tab" aria-controls="ga-panel-{{ $key }}" aria-selected="{{ $method === $key ? 'true' : 'false' }}" data-ga-tab="{{ $key }}" @if ($key === 'contacts') data-ga-contacts-tab @endif>
                    <span class="cu-method-icon">{!! $icon($key) !!}</span>
                    <span class="cu-method-text"><strong>{{ $t('hub.methods.'.$key.'.title') }}</strong><small>{{ $t('hub.methods.'.$key.'.text') }}</small></span>
                    @if ($key === 'names')<span class="cu-badge">{{ $t('hub.recommended') }}</span>@endif
                </a>
            @endforeach
        </div>

        {{-- A. Type guests one by one (several rows, "Add another guest"). --}}
        <section class="cu-panel" id="ga-panel-single" role="tabpanel" aria-labelledby="ga-tab-single" data-ga-panel="single" @if ($method !== 'single') hidden @endif>
            <h2 class="cu-panel-title">{{ $t('single.heading') }}</h2>
            <p class="cu-help">{{ $t('single.help') }}</p>
            <p class="is-alert is-alert-ok" role="status" hidden data-ga-contacts-note></p>
            <form method="POST" action="{{ route('customer.events.guests.bulk', ['event' => $event->id]) }}" novalidate data-ga-single data-ga-json>
                @csrf
                <input type="hidden" name="source" value="single">
                <input type="hidden" name="action" value="save">
                <fieldset class="cu-fieldset" @disabled($readOnly)>
                    <ol class="cu-entries" data-ga-entries>
                        @for ($i = 0; $i < $rowsShown; $i++)
                            <li class="cu-entry" data-ga-entry>
                                <div class="cu-entry-head">
                                    <span class="cu-entry-number" data-ga-number>{{ $t('single.guest', ['number' => $i + 1]) }}</span>
                                    <button type="button" class="cu-icon-button" data-ga-remove aria-label="{{ $t('single.remove') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
                                </div>
                                <div class="cu-entry-grid">
                                    <label class="cu-field cu-field-name"><span>{{ $t('single.name') }}</span>
                                        <input type="text" name="rows[{{ $i }}][name]" maxlength="120" autocomplete="off" autocapitalize="words" enterkeyhint="next" data-ga-field="name" @if ($i === 0) autofocus @endif></label>
                                    <label class="cu-field cu-field-phone"><span>{{ $t('single.phone') }}</span>
                                        <input type="tel" name="rows[{{ $i }}][phone_number]" maxlength="40" inputmode="tel" autocomplete="off" placeholder="07XX XXX XXX" data-ga-field="phone_number"></label>
                                    <label class="cu-field"><span>{{ $t('single.pass') }}</span>
                                        <select name="rows[{{ $i }}][pass_type]" data-ga-field="pass_type" data-ga-pass>
                                            @foreach ($passes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                        </select></label>
                                    <label class="cu-field cu-field-people"><span>{{ $t('single.people') }}</span>
                                        <input type="number" name="rows[{{ $i }}][allowed_entries]" value="1" min="1" max="{{ Guest::MAX_ALLOWED_ENTRIES }}" inputmode="numeric" data-ga-field="allowed_entries" data-ga-people></label>
                                </div>
                            </li>
                        @endfor
                    </ol>
                    <p class="cu-help cu-help-small">{{ $t('single.people_help') }}</p>
                    <button type="button" class="cu-button cu-button-ghost cu-add-row" data-ga-add>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>{{ $t('single.add_row') }}</button>
                    <div class="cu-actions">
                        <button type="submit" class="cu-button cu-button-primary" data-ga-busy="{{ $t('single.saving') }}">{{ $t('single.save') }}</button>
                    </div>
                </fieldset>
            </form>
        </section>

        {{-- B. Paste a list of names. --}}
        <section class="cu-panel" id="ga-panel-names" role="tabpanel" aria-labelledby="ga-tab-names" data-ga-panel="names" @if ($method !== 'names') hidden @endif>
            <h2 class="cu-panel-title">{{ $t('names.heading') }}</h2>
            <p class="cu-help">{{ $t('names.help') }}</p>
            <form method="POST" action="{{ route('customer.events.guests.review', ['event' => $event->id]) }}">
                @csrf
                <input type="hidden" name="mode" value="names">
                <fieldset class="cu-fieldset" @disabled($readOnly)>
                    <label class="cu-field"><span>{{ $t('names.label') }} <small class="cu-counter" data-ga-lines data-template="{{ $tc('names.lines', 2, ['count' => '__N__']) }}" data-zero="{{ $tc('names.lines', 0) }}" data-one="{{ $tc('names.lines', 1) }}" aria-live="polite"></small></span>
                        <textarea name="text" rows="10" maxlength="100000" placeholder="{{ $t('names.placeholder') }}" spellcheck="false" autocapitalize="words" data-ga-text>{{ $method === 'names' ? $text : '' }}</textarea></label>
                    <label class="cu-check"><input type="checkbox" name="split_words" value="1"><span>{{ $t('names.split_words') }}</span></label>
                    <div class="cu-row">
                        <label class="cu-field"><span>{{ $t('names.defaults') }}</span>
                            <select name="default_pass" data-ga-default-pass>
                                @foreach ($passes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select></label>
                        <label class="cu-field" data-ga-default-count hidden><span>{{ $t('names.count_label') }}</span>
                            <input type="number" name="default_count" value="{{ Guest::MIN_SPECIAL_ENTRIES }}" min="{{ Guest::MIN_SPECIAL_ENTRIES }}" max="{{ Guest::MAX_ALLOWED_ENTRIES }}" inputmode="numeric"></label>
                    </div>
                    <p class="cu-help cu-help-small">{{ $t('names.defaults_help') }}</p>
                    <div class="cu-actions"><button type="submit" class="cu-button cu-button-primary">{{ $t('names.continue') }} →</button></div>
                </fieldset>
            </form>
        </section>

        {{-- C. Paste names with details (structured text). --}}
        <section class="cu-panel" id="ga-panel-details" role="tabpanel" aria-labelledby="ga-tab-details" data-ga-panel="details" @if ($method !== 'details') hidden @endif>
            <h2 class="cu-panel-title">{{ $t('details.heading') }}</h2>
            <p class="cu-help">{{ $t('details.help') }}</p>
            <form method="POST" action="{{ route('customer.events.guests.review', ['event' => $event->id]) }}">
                @csrf
                <input type="hidden" name="mode" value="structured">
                <fieldset class="cu-fieldset" @disabled($readOnly)>
                    <label class="cu-field"><span>{{ $t('details.label') }}</span>
                        <textarea name="text" rows="9" maxlength="100000" placeholder="{{ $t('details.placeholder') }}" spellcheck="false">{{ $method === 'details' ? $text : '' }}</textarea></label>
                    <details class="cu-details">
                        <summary>{{ $t('details.rules_title') }}</summary>
                        <ul>@foreach (__('customer_guests.details.rules', [], $language) as $rule)<li>{{ $rule }}</li>@endforeach</ul>
                    </details>
                    <div class="cu-actions"><button type="submit" class="cu-button cu-button-primary">{{ $t('details.continue') }} →</button></div>
                </fieldset>
            </form>
        </section>

        {{-- D. Excel / CSV (the existing import, kept and explained). --}}
        <section class="cu-panel" id="ga-panel-file" role="tabpanel" aria-labelledby="ga-tab-file" data-ga-panel="file" @if ($method !== 'file') hidden @endif>
            <h2 class="cu-panel-title">{{ $t('file.heading') }}</h2>
            <p class="cu-help">{{ $t('file.help') }}</p>
            <form method="POST" action="{{ route('customer.events.guests.import.preview', ['event' => $event->id]) }}" enctype="multipart/form-data">
                @csrf
                <fieldset class="cu-fieldset" @disabled($readOnly)>
                    <label class="cu-drop" for="ga-file">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 18a4.5 4.5 0 0 1-.6-8.96A6 6 0 0 1 18 10.5 3.8 3.8 0 0 1 17.5 18"/><path d="M12 19v-7m-3 3 3-3 3 3"/></svg>
                        <span id="ig-file-name" data-chosen="{{ $t('import.chosen', ['name' => '__NAME__']) }}">{{ $t('import.choose') }}</span>
                    </label>
                    <input class="sr-only" id="ga-file" type="file" name="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required data-ig-file>
                    <div class="cu-actions"><button type="submit" class="cu-button cu-button-primary">{{ $t('file.open') }} →</button></div>
                </fieldset>
            </form>
            <div class="cu-templates">
                <a class="cu-button cu-button-ghost" href="{{ route('customer.events.guests.import.sample', ['event' => $event->id]) }}">↓ {{ $t('file.template_xlsx') }}</a>
                <a class="cu-button cu-button-ghost" href="{{ route('customer.events.guests.import.sample_csv', ['event' => $event->id]) }}">↓ {{ $t('file.template_csv') }}</a>
            </div>
            <details class="cu-details" open>
                <summary>{{ $t('file.columns_title') }}</summary>
                <ul>@foreach (['name', 'phone', 'pass', 'people'] as $column)<li>{{ $t('file.columns.'.$column) }}</li>@endforeach</ul>
            </details>
        </section>

        {{-- E. Phone contacts (Contact Picker API where the browser supports it). --}}
        <section class="cu-panel" id="ga-panel-contacts" role="tabpanel" aria-labelledby="ga-tab-contacts" data-ga-panel="contacts" @if ($method !== 'contacts') hidden @endif>
            <h2 class="cu-panel-title">{{ $t('contacts.heading') }}</h2>
            <p class="cu-help">{{ $t('contacts.help') }}</p>
            <p class="is-alert is-alert-info" data-ga-contacts-unsupported>{{ $t('contacts.unsupported') }}</p>
            <button type="button" class="cu-button cu-button-primary" hidden @disabled($readOnly) data-ga-contacts
                data-added-one="{{ $tc('contacts.added', 1) }}" data-added-many="{{ $tc('contacts.added', 2, ['count' => '__N__']) }}" data-none="{{ $t('contacts.none') }}">{{ $t('contacts.button') }}</button>
        </section>
    </main>
</div>
</body>
</html>
