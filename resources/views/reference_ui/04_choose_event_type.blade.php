@php
    $t = fn (string $key, array $replace = []) => __('event_type.'.$key, $replace, $language);
    $assets = 'assets/reference_ui/04_Choose_Event_Type/';
    $images = ['wedding' => 'Wedding', 'send_off' => 'Send_Off', 'kitchen_party' => 'Kitchen_Party', 'other' => 'Other_Celebration'];
    $typeError = $formErrors['event_type'] ?? null;
    $workspaceError = $formErrors['workspace_id'] ?? null;
    $multipleWorkspaces = $workspaces->count() > 1;
    $languageUrl = fn (string $locale) => route('customer.events.create', array_filter([
        'lang' => $locale,
        'type' => $selectedType,
        'workspace' => $multipleWorkspaces ? $selectedWorkspace : null,
    ]));
    $clientMessages = ['type' => $t('type_required'), 'workspace' => $t('workspace_required'), 'check' => $t('check_fields')];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fcfaf3">
    <title>{{ $t('page_title') }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/04_choose_event_type.css', 'resources/js/reference_ui/04_choose_event_type.js'])
</head>
<body class="et-body">
    <div class="et-page">
        <header class="et-header">
            <a class="et-back" href="{{ route('customer.home') }}" aria-label="{{ $t('back') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12H4m7-7-7 7 7 7"/></svg>
            </a>
            <div class="et-brand">
                <img src="{{ asset('assets/pangaevents-mark.svg') }}" width="68" height="76" alt="">
                <span>PangaEvents</span>
            </div>
            <nav class="et-languages" aria-label="{{ $t('language') }}">
                @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $label)
                    <a @class(['et-language', 'is-active' => $language === $locale])
                       href="{{ $languageUrl($locale) }}" lang="{{ $locale }}" hreflang="{{ $locale }}"
                       data-et-language="{{ $locale }}"
                       @if ($language === $locale) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </header>

        <main class="et-main" aria-labelledby="et-heading">
            <h1 id="et-heading" class="et-heading">{{ $t('heading') }}</h1>
            <p class="et-intro">{{ $t('intro') }}</p>

            @if ($notice === 'setup_missing')
                <p class="et-alert et-alert-status" role="status">{{ $t('setup_missing') }}</p>
            @endif
            <p id="et-alert" class="et-alert et-alert-error" role="alert" @if ($formErrors === []) hidden @endif>{{ $formErrors === [] ? '' : $t('check_fields') }}</p>

            <form class="et-form" method="POST" action="{{ route('customer.events.type') }}" novalidate data-et-form>
                @csrf
                <fieldset class="et-types" @if ($typeError) aria-describedby="et-error-type" @endif>
                    <legend class="sr-only">{{ $t('types_label') }}</legend>
                    @foreach ($types as $type)
                        <label @class(['et-card', 'is-selected' => $selectedType === $type])>
                            <input class="sr-only" type="radio" name="event_type" value="{{ $type }}" @checked($selectedType === $type) data-et-type>
                            <span class="et-card-art">
                                <img src="{{ asset($assets.'04_Choose_Event_Type_'.$images[$type].'.webp') }}" alt="" width="368" height="340" @if (! $loop->first) loading="lazy" @endif decoding="async">
                            </span>
                            <span class="et-card-check" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                            </span>
                            <span class="et-card-label">{{ $t('types.'.$type) }}</span>
                        </label>
                    @endforeach
                </fieldset>
                <p id="et-error-type" class="et-error" @unless ($typeError) hidden @endunless>{{ $typeError }}</p>

                @if ($multipleWorkspaces)
                    {{-- More than one eligible workspace: the customer chooses explicitly. --}}
                    <fieldset class="et-workspaces" aria-describedby="et-workspace-hint{{ $workspaceError ? ' et-error-workspace' : '' }}">
                        <legend class="et-workspace-legend">{{ $t('workspace_label') }}</legend>
                        <p id="et-workspace-hint" class="et-workspace-hint">{{ $t('workspace_hint') }}</p>
                        @foreach ($workspaces as $workspace)
                            <label @class(['et-workspace', 'is-selected' => $selectedWorkspace === $workspace->id])>
                                <input type="radio" name="workspace_id" value="{{ $workspace->id }}" @checked($selectedWorkspace === $workspace->id) data-et-workspace>
                                <span>{{ $workspace->name }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    <p id="et-error-workspace" class="et-error" @unless ($workspaceError) hidden @endunless>{{ $workspaceError }}</p>
                @endif

                <div class="et-progress" role="img" aria-label="{{ $t('progress') }}">
                    <span class="is-active"></span><span></span><span></span><span></span>
                </div>

                <button class="et-continue" type="submit" data-et-submit data-submitting-label="{{ $t('continuing') }}">
                    <span class="et-continue-label">{{ $t('continue') }}</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg>
                </button>
            </form>
        </main>
    </div>
    <script type="application/json" id="et-messages">@json($clientMessages)</script>
</body>
</html>
