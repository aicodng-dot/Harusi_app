@php
    use App\Services\Events\InvitationTemplates;
    use App\Services\Invitations\InvitationDesign;
    use App\Services\Invitations\Render\Ornaments;

    $t = fn (string $key, array $replace = []) => __('invitation_studio.'.$key, $replace, $language);
    $doc = $design['doc'];
    $colors = array_merge($template['colors'], $doc['colors']);
    $fontsDoc = array_merge($template['fonts'], $doc['fonts']);
    $hasPhotoSlot = $template['photo_shape'] !== null;
    $icons = [
        'colors' => '<circle cx="12" cy="12" r="9"/><circle cx="8" cy="10" r="1.3" fill="currentColor"/><circle cx="12" cy="7.5" r="1.3" fill="currentColor"/><circle cx="16" cy="10" r="1.3" fill="currentColor"/><path d="M12 21a3 3 0 0 1 0-6h2a3 3 0 0 0 3-3"/>',
        'fonts' => '<path d="M3 6V4h10v2M8 4v16M6 20h4M13 11v-1h8v1M17 10v10M15.5 20h3"/>',
        'photo' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
        'text' => '<path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/><path d="M8 9h8M8 13h5"/>',
        'wording' => '<path d="M4 19.5V5a2 2 0 0 1 2-2h12v18H6a2 2 0 0 1-2-1.5Z"/><path d="M8 7h7M8 11h7"/>',
        'decor' => '<path d="M12 3c2 3 2 5 0 8-2-3-2-5 0-8ZM12 11c3-1 5 0 7 2-3 1-5 0-7-2ZM12 11c-3-1-5 0-7 2 3 1 5 0 7-2ZM12 11v10"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/>',
    ];
    $chevron = '<svg class="ed-row-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>';
    $config = [
        'document' => $doc,
        'defaults' => $defaults,
        'version' => $design['version'],
        'templateColors' => $template['colors'],
        'templateFonts' => $template['fonts'],
        'palettes' => $palettes,
        'wording' => $wording,
        'readOnly' => $readOnly,
        'elements' => $elements,
        'protectedLabels' => ['qr' => $t('elements.qr'), 'guest' => $t('elements.guest')],
        'routes' => [
            'save' => route('customer.events.invitation.save', ['event' => $event->id]),
            'preview' => route('customer.events.invitation.preview', ['event' => $event->id]),
            'photo' => route('customer.events.invitation.photo.store', ['event' => $event->id]),
            'next' => route('customer.events.invitation.continue', ['event' => $event->id]),
        ],
        'messages' => [
            'saving' => $t('edit.saving'), 'save' => $t('edit.save_continue'), 'unsaved' => $t('edit.unsaved'), 'allSaved' => $t('edit.all_saved'),
            'resetConfirm' => $t('edit.reset_confirm'), 'leave' => $t('edit.leave_warning'), 'previewFailed' => $t('edit.preview_failed'),
            'network' => $t('network'), 'uploading' => $t('photo.uploading'), 'uploadFailed' => $t('photo_errors.failed'),
            'warnings' => __('invitation_studio.warnings', [], $language),
            'modeOn' => $t('arrange.mode_on'), 'modeOff' => $t('arrange.mode_off'), 'size' => $t('arrange.size'), 'visible' => $t('arrange.visible'),
            'reset' => $t('arrange.reset'), 'moved' => $t('arrange.moved'),
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $t('edit.page_title') }} · {{ $event->event_name }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/invitation_steps.css', 'resources/js/reference_ui/14_edit_invitation_card.js', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
    <style>
        @foreach ($fonts as $key => $font)
        @font-face { font-family: "inv-{{ $key }}"; src: url("{{ asset('assets/invitation/fonts/'.$font['regular'].'.ttf') }}") format("truetype"); font-display: swap; }
        @endforeach
    </style>
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page cu-wide">
    <header class="is-header">
        <a class="is-back" href="{{ route('customer.events.template', ['event' => $event->id]) }}" aria-label="{{ $t('edit.change_design') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
        </a>
        <span class="is-brand">PangaEvents</span>
        <nav class="is-languages" aria-label="{{ $t('language') }}">
            @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $code)
                <a @class(['is-language', 'is-active' => $language === $locale]) href="{{ route('customer.events.invitation', ['event' => $event->id, 'lang' => $locale]) }}" lang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif data-ed-nav>{{ $code }}</a>
            @endforeach
        </nav>
    </header>

    <main class="is-main" aria-labelledby="ed-heading">
        <h1 id="ed-heading" class="is-heading">{{ $t('edit.title') }}</h1>
        <p class="is-intro">{{ $t('edit.intro') }}</p>
        @include('reference_ui.partials.invitation_steps', ['stage' => 'card'])

        @if ($savedTemplate)
            <p class="is-alert is-alert-ok" role="status">{{ $t('template_saved', ['template' => InvitationTemplates::name($savedTemplate)]) }}</p>
        @endif
        @if ($readOnly)
            <p class="is-alert is-alert-info" role="status">{{ $t('read_only') }}</p>
        @endif
        <div id="ed-warnings" class="is-alert is-alert-warn" role="status" @if ($warnings === []) hidden @endif>
            <ul>@foreach ($warnings as $warning)<li>{{ $t('warnings.'.$warning) }}</li>@endforeach</ul>
        </div>
        <p id="ed-alert" class="is-alert is-alert-error" role="alert" hidden></p>

        <div class="ed-layout">
        <div class="ed-col-preview">
        <figure class="ed-preview" id="ed-preview" aria-live="polite">
            <div class="ed-stage">
                <img id="ed-preview-img" alt="{{ $t('edit.preview_alt', ['event' => $event->event_name]) }}" width="600" height="800">
                {{-- Drag handles, positioned from the renderer's element map (move mode only). --}}
                <div class="ed-overlay" id="ed-overlay" hidden></div>
            </div>
            <figcaption class="ed-spinner" id="ed-spinner" hidden>{{ $t('edit.preview_loading') }}</figcaption>
        </figure>
        <p class="ed-note">{{ $t('edit.sample_note') }}</p>
        <noscript><p class="is-alert is-alert-warn">JavaScript is required for the live card editor.</p></noscript>

        <div class="ed-toolbar">
            <span class="ed-state" id="ed-state" role="status">{{ $design['saved'] ? $t('edit.all_saved') : $t('edit.unsaved') }}</span>
            <button type="button" class="ed-tool" id="ed-undo" disabled aria-label="{{ $t('edit.undo') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/></svg>{{ $t('edit.undo') }}</button>
            <button type="button" class="ed-tool" id="ed-redo" disabled aria-label="{{ $t('edit.redo') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 14 5-5-5-5"/><path d="M20 9H9a5 5 0 0 0 0 10h3"/></svg>{{ $t('edit.redo') }}</button>
            <button type="button" class="ed-tool" id="ed-reset" @disabled($readOnly)>{{ $t('edit.reset') }}</button>
            <button type="button" class="ed-tool ed-arrange-toggle" id="ed-arrange-toggle" aria-pressed="false" @disabled($readOnly)>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 9 2 12l3 3M9 5l3-3 3 3M15 19l-3 3-3-3M19 9l3 3-3 3M2 12h20M12 2v20"/></svg>
                <span id="ed-arrange-label">{{ $t('arrange.mode_on') }}</span>
            </button>
            <a class="ed-tool" href="{{ route('customer.events.template', ['event' => $event->id, 'template' => $doc['template']]) }}" data-ed-nav>{{ $t('edit.change_design') }} · {{ $template['name'] }}</a>
        </div>

        </div>
        <div class="ed-col-controls">
        <form id="ed-form" novalidate onsubmit="return false">
        <fieldset style="border:0;margin:0;padding:0;min-width:0" @disabled($readOnly)>
        <ul class="ed-rows">
            {{-- Rangi --}}
            <li>
                <button type="button" class="ed-row" aria-expanded="false" aria-controls="ed-panel-colors">
                    <span class="ed-row-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons['colors'] !!}</svg></span>
                    <span><span class="ed-row-title">{{ $t('rows.colors.title') }}</span><span class="ed-row-hint">{{ $t('rows.colors.hint') }}</span></span>
                    <span class="ed-row-aside" aria-hidden="true">
                        @foreach (['text', 'accent', 'bg', 'decor'] as $i => $key)
                            <i class="ed-swatch @if ($i === 0) is-first @endif" data-swatch="{{ $key }}" style="background: {{ $colors[$key] }}"></i>
                        @endforeach
                    </span>
                    {!! $chevron !!}
                </button>
                <div class="ed-panel" id="ed-panel-colors" hidden>
                    <div class="ed-field">
                        <span class="ed-label">{{ $t('colors.palettes') }}</span>
                        <div class="ed-palettes" role="group" aria-label="{{ $t('colors.palettes') }}">
                            @foreach ($palettes as $key => $palette)
                                <button type="button" class="ed-palette" data-palette="{{ $key }}" aria-label="{{ $key === $template['palette'] ? $t('colors.template') : str_replace('_', ' ', $key) }}">
                                    @foreach (['bg', 'text', 'accent'] as $c)<i style="background: {{ $palette[$c] }}"></i>@endforeach
                                </button>
                            @endforeach
                        </div>
                    </div>
                    <div class="ed-field">
                        <span class="ed-label">{{ $t('colors.custom') }}</span>
                        <div class="ed-colors">
                            @foreach (InvitationDesign::COLOR_KEYS as $key)
                                <label class="ed-color"><input type="color" value="{{ $colors[$key] }}" data-path="colors.{{ $key }}">{{ $t('colors.'.$key) }}</label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </li>

            {{-- Fonti --}}
            <li>
                <button type="button" class="ed-row" aria-expanded="false" aria-controls="ed-panel-fonts">
                    <span class="ed-row-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons['fonts'] !!}</svg></span>
                    <span><span class="ed-row-title">{{ $t('rows.fonts.title') }}</span><span class="ed-row-hint">{{ $t('rows.fonts.hint') }}</span></span>
                    <span class="ed-row-aside" aria-hidden="true"><span class="ed-font-chip" id="ed-font-chip" style="font-family: 'inv-{{ $fontsDoc['heading'] }}'">{{ $fonts[$fontsDoc['heading']]['label'] }}</span></span>
                    {!! $chevron !!}
                </button>
                <div class="ed-panel" id="ed-panel-fonts" hidden>
                    @foreach (['heading', 'accent', 'body', 'label'] as $role)
                        <label class="ed-field"><span class="ed-label">{{ $t('fonts.'.$role) }}</span>
                            <select class="ed-select" data-path="fonts.{{ $role }}">
                                @foreach (['serif', 'script', 'sans'] as $kind)
                                    <optgroup label="{{ $t('fonts.kinds.'.$kind) }}">
                                        @foreach ($fonts as $key => $font)
                                            @if ($font['kind'] === $kind)
                                                <option value="{{ $key }}" @selected($fontsDoc[$role] === $key)>{{ $font['label'] }}</option>
                                            @endif
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </label>
                    @endforeach
                    <label class="ed-field"><span class="ed-label">{{ $t('fonts.scale') }} <output id="ed-scale-out">{{ round($doc['font_scale'] * 100) }}%</output></span>
                        <input class="ed-range" type="range" min="0.8" max="1.2" step="0.05" value="{{ $doc['font_scale'] }}" data-path="font_scale" data-number>
                    </label>
                    <div class="ed-field"><span class="ed-label">{{ $t('fonts.align') }}</span>
                        <div class="ed-segment">
                            @foreach (['' => 'align_template', 'center' => 'align_center', 'left' => 'align_left'] as $value => $label)
                                <label><input type="radio" name="align" value="{{ $value }}" data-path="align" data-nullable @checked(($doc['align'] ?? '') === $value)><span>{{ $t('fonts.'.$label) }}</span></label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </li>

            {{-- Picha --}}
            <li>
                <button type="button" class="ed-row" aria-expanded="false" aria-controls="ed-panel-photo">
                    <span class="ed-row-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons['photo'] !!}</svg></span>
                    <span><span class="ed-row-title">{{ $t('rows.photo.title') }}</span><span class="ed-row-hint">{{ $t('rows.photo.hint') }}</span></span>
                    <span class="ed-row-aside"><img class="ed-photo-chip" id="ed-photo-chip" alt="{{ $t('photo.thumb_alt') }}" @if ($photoUrl) src="{{ $photoUrl }}" @else hidden @endif></span>
                    {!! $chevron !!}
                </button>
                <div class="ed-panel" id="ed-panel-photo" hidden>
                    @unless ($hasPhotoSlot)
                        <p class="is-alert is-alert-info" style="margin-top:0">{{ $t('photo.no_slot') }}</p>
                    @endunless
                    <div class="ed-field">
                        <div class="ed-photo-actions">
                            <label class="ed-tool" for="ed-photo-input"><span id="ed-photo-label">{{ $photoUrl ? $t('photo.replace') : $t('photo.upload') }}</span></label>
                            <input class="sr-only" id="ed-photo-input" type="file" accept="image/jpeg,image/png,image/webp">
                            <button type="button" class="ed-tool" id="ed-photo-remove" @if (! $photoUrl) hidden @endif>{{ $t('photo.remove') }}</button>
                        </div>
                        <span class="ed-help" id="ed-photo-status">{{ $t('photo.formats') }}</span>
                    </div>
                    @if ($photoShapes !== [])
                        <div class="ed-field"><span class="ed-label">{{ $t('frame.shape') }}</span>
                            <div class="ed-segment">
                                <label><input type="radio" name="photo_shape" value="" data-path="photo.shape" data-nullable @checked(($doc['photo']['shape'] ?? null) === null)><span>{{ $t('frame.template') }}</span></label>
                                @foreach ($photoShapes as $shape)
                                    <label><input type="radio" name="photo_shape" value="{{ $shape }}" data-path="photo.shape" data-nullable @checked(($doc['photo']['shape'] ?? null) === $shape)><span>{{ $t('frame.shapes.'.$shape) }}</span></label>
                                @endforeach
                            </div>
                        </div>
                        <div class="ed-field"><span class="ed-label">{{ $t('frame.border') }}</span>
                            <div class="ed-segment">
                                <label><input type="radio" name="photo_border" value="" data-path="photo.border" data-nullable @checked(($doc['photo']['border'] ?? null) === null)><span>{{ $t('frame.template') }}</span></label>
                                @foreach (\App\Services\Invitations\InvitationDesign::PHOTO_BORDERS as $border)
                                    <label><input type="radio" name="photo_border" value="{{ $border }}" data-path="photo.border" data-nullable @checked(($doc['photo']['border'] ?? null) === $border)><span>{{ $t('frame.borders.'.$border) }}</span></label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <div id="ed-photo-crop" @if (! $photoUrl) hidden @endif>
                        <div class="ed-field">
                            <span class="ed-label">{{ $t('frame.crop') }}</span>
                            <div class="ed-crop" id="ed-crop" role="application" aria-label="{{ $t('frame.crop') }}" tabindex="0"><span class="ed-crop-grid" aria-hidden="true"></span></div>
                            <span class="ed-help">{{ $t('frame.crop_help') }}</span>
                        </div>
                        <label class="ed-field"><span class="ed-label">{{ $t('photo.zoom') }}</span><input class="ed-range" type="range" min="1" max="3" step="0.05" value="{{ $doc['photo']['zoom'] }}" data-path="photo.zoom" data-number></label>
                        <label class="ed-field"><span class="ed-label">{{ $t('photo.pos_x') }}</span><input class="ed-range" type="range" min="0" max="1" step="0.01" value="{{ $doc['photo']['x'] }}" data-path="photo.x" data-number></label>
                        <label class="ed-field"><span class="ed-label">{{ $t('photo.pos_y') }}</span><input class="ed-range" type="range" min="0" max="1" step="0.01" value="{{ $doc['photo']['y'] }}" data-path="photo.y" data-number></label>
                    </div>
                </div>
            </li>

            {{-- Maandishi --}}
            <li>
                <button type="button" class="ed-row" aria-expanded="false" aria-controls="ed-panel-text" id="ed-row-text">
                    <span class="ed-row-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons['text'] !!}</svg></span>
                    <span><span class="ed-row-title">{{ $t('rows.text.title') }}</span><span class="ed-row-hint">{{ $t('rows.text.hint') }}</span></span>
                    <span></span>
                    {!! $chevron !!}
                </button>
                <div class="ed-panel" id="ed-panel-text" hidden>
                    <p class="ed-help" style="margin:0 0 12px">{{ $t('text.date_note') }}</p>
                    @foreach (['names' => $eventFacts['names'], 'label' => null, 'venue' => $eventFacts['venue'], 'location' => null, 'dress_code' => null, 'note' => null, 'guest_label' => null, 'qr_caption' => null] as $field => $fact)
                        <label class="ed-field"><span class="ed-label">{{ $t('text.'.$field) }}</span>
                            <input class="ed-input" type="text" maxlength="{{ InvitationDesign::TEXT_LIMITS[$field] }}" value="{{ $doc['content'][$field] ?? '' }}" data-path="content.{{ $field }}" data-nullable data-text
                                   @if ($fact !== null && $fact !== '') placeholder="{{ $fact }}" @endif>
                            @if ($fact !== null)<span class="ed-help">{{ $fact !== '' ? $t('text.from_event', ['value' => $fact]) : $t('text.hint') }}</span>@endif
                        </label>
                    @endforeach
                </div>
            </li>

            {{-- Maneno ya mwaliko --}}
            <li>
                <button type="button" class="ed-row" aria-expanded="false" aria-controls="ed-panel-wording">
                    <span class="ed-row-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons['wording'] !!}</svg></span>
                    <span><span class="ed-row-title">{{ $t('rows.wording.title') }}</span><span class="ed-row-hint">{{ $t('rows.wording.hint') }}</span></span>
                    <span></span>
                    {!! $chevron !!}
                </button>
                <div class="ed-panel" id="ed-panel-wording" hidden>
                    <div class="ed-field"><span class="ed-label">{{ $t('wording.language') }}</span>
                        <div class="ed-segment">
                            @foreach (['sw', 'en'] as $lang)
                                <label><input type="radio" name="card_language" value="{{ $lang }}" data-path="content.language" @checked($doc['content']['language'] === $lang)><span>{{ $t('wording.languages.'.$lang) }}</span></label>
                            @endforeach
                        </div>
                        <span class="ed-help">{{ $t('wording.language_note') }}</span>
                    </div>
                    <label class="ed-field"><span class="ed-label">{{ $t('wording.hosts') }}</span>
                        <select class="ed-select" data-path="content.host_mode">
                            @foreach (\App\Services\Invitations\InvitationWording::HOST_MODES as $mode)
                                <option value="{{ $mode }}" @selected($doc['content']['host_mode'] === $mode)>{{ $t('wording.host_modes.'.$mode) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ed-field" data-host="one"><span class="ed-label" data-host-label data-label-family="{{ $t('wording.host_one') }}" data-label-other="{{ $t('wording.host_other') }}">{{ $t('wording.host_one') }}</span>
                        <input class="ed-input" type="text" maxlength="120" value="{{ $doc['content']['host_one'] ?? '' }}" placeholder="{{ $t('wording.host_example') }}" data-path="content.host_one" data-nullable data-text>
                    </label>
                    <label class="ed-field" data-host="two"><span class="ed-label">{{ $t('wording.host_two') }}</span>
                        <input class="ed-input" type="text" maxlength="120" value="{{ $doc['content']['host_two'] ?? '' }}" placeholder="{{ $t('wording.host_example') }}" data-path="content.host_two" data-nullable data-text>
                    </label>
                    <div class="ed-field"><span class="ed-label">{{ $t('wording.style') }}</span>
                        <div class="ed-wording" id="ed-wording-options">
                            @foreach (\App\Services\Invitations\InvitationWording::STYLES as $style)
                                <label><input type="radio" name="wording_style" value="{{ $style }}" data-path="content.style" data-style @checked($doc['content']['style'] === $style)>
                                    <span><strong>{{ $t('wording.styles.'.$style) }}</strong><em data-style-preview="{{ $style }}">{{ $wording[$doc['content']['language']][$doc['content']['host_mode']][$style]['intro'] }}</em></span></label>
                            @endforeach
                        </div>
                    </div>
                    <label class="ed-field"><span class="ed-label">{{ $t('text.intro') }}</span>
                        <textarea class="ed-textarea" maxlength="240" data-path="content.intro" data-nullable data-text>{{ $doc['content']['intro'] ?? '' }}</textarea>
                    </label>
                    <label class="ed-field"><span class="ed-label">{{ $t('text.message') }}</span>
                        <textarea class="ed-textarea" maxlength="240" data-path="content.message" data-nullable data-text>{{ $doc['content']['message'] ?? '' }}</textarea>
                        <span class="ed-help">{{ $t('text.hint') }}</span>
                    </label>
                </div>
            </li>

            {{-- Mapambo --}}
            <li>
                <button type="button" class="ed-row" aria-expanded="false" aria-controls="ed-panel-decor">
                    <span class="ed-row-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons['decor'] !!}</svg></span>
                    <span><span class="ed-row-title">{{ $t('rows.decor.title') }}</span><span class="ed-row-hint">{{ $t('rows.decor.hint') }}</span></span>
                    <span></span>
                    {!! $chevron !!}
                </button>
                <div class="ed-panel" id="ed-panel-decor" hidden>
                    <label class="ed-check"><input type="checkbox" data-path="decor.art" data-bool @checked($doc['decor']['art'])>{{ $t('decor.art') }}</label>
                    <div class="ed-field">
                        <span class="ed-label">{{ $t('frame.pieces') }}</span>
                        <span class="ed-help">{{ $t('frame.pieces_help') }}</span>
                        <div class="ed-pieces" id="ed-decor-pieces"></div>
                    </div>
                    @foreach (['frame' => Ornaments::FRAMES, 'pattern' => Ornaments::PATTERNS, 'background' => InvitationDesign::BACKGROUNDS] as $key => $options)
                        <label class="ed-field"><span class="ed-label">{{ $t('decor.'.$key) }}</span>
                            <select class="ed-select" data-path="decor.{{ $key }}" data-nullable>
                                <option value="" @selected($doc['decor'][$key] === null)>{{ $t('decor.template') }} ({{ $t('decor.'.$key.'s.'.$template[$key]) }})</option>
                                @foreach ($options as $option)
                                    <option value="{{ $option }}" @selected($doc['decor'][$key] === $option)>{{ $t('decor.'.$key.'s.'.$option) }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endforeach
                </div>
            </li>

            {{-- Mpangilio: move, resize and hide individual elements --}}
            <li>
                <button type="button" class="ed-row" aria-expanded="false" aria-controls="ed-panel-arrange" id="ed-row-arrange">
                    <span class="ed-row-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 9 2 12l3 3M9 5l3-3 3 3M15 19l-3 3-3-3M19 9l3 3-3 3M2 12h20M12 2v20"/></svg></span>
                    <span><span class="ed-row-title">{{ $t('arrange.title') }}</span><span class="ed-row-hint">{{ $t('arrange.hint') }}</span></span>
                    <span></span>
                    {!! $chevron !!}
                </button>
                <div class="ed-panel" id="ed-panel-arrange" hidden>
                    <p class="ed-help" style="margin:0 0 8px">{{ $t('arrange.help') }}</p>
                    <p class="ed-help" style="margin:0 0 12px">{{ $t('arrange.protected') }}</p>
                    <div class="ed-arrange-list" id="ed-arrange-list"></div>
                    <button type="button" class="ed-tool" id="ed-arrange-reset" style="margin-top:12px">{{ $t('arrange.reset_all') }}</button>
                </div>
            </li>

            {{-- QR --}}
            <li>
                <button type="button" class="ed-row" aria-expanded="false" aria-controls="ed-panel-qr">
                    <span class="ed-row-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons['qr'] !!}</svg></span>
                    <span><span class="ed-row-title">{{ $t('rows.qr.title') }}</span><span class="ed-row-hint">{{ $t('rows.qr.hint') }}</span></span>
                    <span></span>
                    {!! $chevron !!}
                </button>
                <div class="ed-panel" id="ed-panel-qr" hidden>
                    <div class="ed-field"><span class="ed-label">{{ $t('qr.position') }}</span>
                        <div class="ed-segment">
                            @foreach (['left', 'center', 'right'] as $position)
                                <label><input type="radio" name="qr_position" value="{{ $position }}" data-path="qr.position" @checked($doc['qr']['position'] === $position)><span>{{ $t('qr.positions.'.$position) }}</span></label>
                            @endforeach
                        </div>
                    </div>
                    <div class="ed-field"><span class="ed-label">{{ $t('qr.size') }}</span>
                        <div class="ed-segment">
                            @foreach (['s', 'm', 'l'] as $size)
                                <label><input type="radio" name="qr_size" value="{{ $size }}" data-path="qr.size" @checked($doc['qr']['size'] === $size)><span>{{ $t('qr.sizes.'.$size) }}</span></label>
                            @endforeach
                        </div>
                    </div>
                    <p class="ed-help">{{ $t('qr.protected') }}</p>
                </div>
            </li>
        </ul>
        </fieldset>
        </form>

        <div class="is-actions">
            <button type="button" class="is-primary" id="ed-save" @disabled($readOnly)>
                <span id="ed-save-label">{{ $t('edit.save_continue') }}</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg>
            </button>
            <button type="button" class="is-secondary" id="ed-save-only" @disabled($readOnly)>{{ $t('edit.save') }}</button>
        </div>
        </div>
        </div>
    </main>
</div>
<script type="application/json" id="ed-config">@json($config)</script>
</body>
</html>
