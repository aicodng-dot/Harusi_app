@php
    $t = fn (string $key, array $replace = []) => __('customer_share.'.$key, $replace, $language);
    $ev = ['event' => $event->id];
    $audience = old('audience', $selection !== [] ? 'selection' : 'not_shared');
    $channelValue = old('channel', $channel);
    $templateKey = old('template', $channelValue === 'sms' ? 'default_sms' : 'default_whatsapp');
    $message = old('message', $templates[$templateKey]['body'] ?? $templates['default_whatsapp']['body']);
    $passes = ['single', 'double', 'special'];
    $oldPass = (array) old('pass', []);
    $config = [
        'pool' => $pool,
        'selection' => $selection,
        'templates' => $templates,
        'values' => $values,
        'placeholders' => \App\Services\Sharing\ShareMessage::PLACEHOLDERS,
        'max' => \App\Services\Sharing\ShareMessage::MAX_LENGTH,
        'i18n' => [
            'start' => $t('wizard.start', ['count' => '__COUNT__']),
            'start_plain' => $t('wizard.start_plain'),
            'chars' => $t('js.chars', ['count' => '__COUNT__']),
            'sms_parts' => $t('js.sms_parts', ['count' => '__COUNT__']),
            'no_card' => $t('wizard.no_card_note', ['count' => '__COUNT__']),
            'problems' => __('customer_share.problems', ['max' => \App\Services\Sharing\ShareMessage::MAX_LENGTH], $language),
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('wizard.title') }} · {{ $event->event_name }}</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js', 'resources/js/reference_ui/customer_share.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page sh-page">
    <main class="is-main" id="cs-content" aria-labelledby="sh-heading">
        <h1 id="sh-heading" class="is-heading">{{ $t('wizard.title') }}</h1>

        <ol class="sh-stepper" data-sh-stepper>
            @foreach (['guests', 'channel', 'message'] as $i => $step)
                <li data-step-label="{{ $i }}" @if ($i === 0) aria-current="step" @endif><span>{{ $i + 1 }}</span>{{ $t('wizard.steps.'.$step) }}</li>
            @endforeach
        </ol>

        @if ($errors->any())
            <div class="sh-note is-bad" role="alert">
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('customer.events.share.sessions.store', $ev) }}" class="sh-wizard" data-sh-wizard data-error-step="{{ $errors->has('message') ? 2 : 0 }}" novalidate>
            @csrf
            <input type="hidden" name="template" value="{{ $templateKey }}" data-sh-template-key>

            {{-- Step 1: guests --}}
            <section class="cu-panel sh-step" data-step="0" aria-labelledby="sh-s1">
                <p class="sh-step-count">{{ $t('wizard.step', ['n' => 1]) }}</p>
                <h2 id="sh-s1" class="cu-panel-title">{{ $t('wizard.who') }}</h2>
                <div class="sh-choices" role="radiogroup" aria-labelledby="sh-s1">
                    @foreach (['selection', 'not_shared', 'all', 'pass'] as $option)
                        @continue($option === 'selection' && $selection === [])
                        <label class="sh-choice">
                            <input type="radio" name="audience" value="{{ $option }}" @checked($audience === $option) data-sh-audience>
                            <span><strong>{{ __('customer_share.wizard.audience.'.$option, [], $language)[0] }}</strong>
                                <small>{{ str_replace(':count', (string) count($selection), __('customer_share.wizard.audience.'.$option, [], $language)[1]) }}</small></span>
                        </label>
                    @endforeach
                </div>
                <div class="sh-passes gm-pills" data-sh-passes @if ($audience !== 'pass') hidden @endif>
                    @foreach ($passes as $pass)
                        <label class="gm-pill"><input type="checkbox" name="pass[]" value="{{ $pass }}" @checked(in_array($pass, $oldPass, true)) data-sh-pass><span>{{ __('customer_guests.add.passes.'.$pass, [], $language) }}</span></label>
                    @endforeach
                </div>
                <label class="cu-check"><input type="checkbox" name="with_phone" value="1" @checked(old('with_phone', $channelValue === 'share' ? null : '1')) data-sh-with-phone><span>{{ $t('wizard.with_phone') }}</span></label>
                <label class="cu-check"><input type="checkbox" name="skip_done" value="1" @checked(old('skip_done', '1')) data-sh-skip-done><span>{{ $t('wizard.skip_done') }}</span></label>

                <dl class="sh-summary" aria-live="polite">
                    @foreach (['selected', 'with_phone', 'missing_phone', 'shared', 'no_card', 'remaining'] as $key)
                        <div @class(['is-main' => $key === 'remaining'])><dt>{{ $t('wizard.summary.'.$key) }}</dt><dd data-sum="{{ $key }}">–</dd></div>
                    @endforeach
                </dl>
                <p class="sh-note is-warn" data-sh-no-card hidden><span></span> <a href="{{ route('customer.events.invitation.guests', $ev + ['invite' => 'not_generated']) }}">{{ $t('wizard.generate_link') }}</a></p>
                <div class="sh-step-actions"><button type="button" class="cu-button cu-button-primary" data-sh-next>{{ $t('wizard.next') }}</button></div>
            </section>

            {{-- Step 2: channel --}}
            <section class="cu-panel sh-step" data-step="1" aria-labelledby="sh-s2">
                <p class="sh-step-count">{{ $t('wizard.step', ['n' => 2]) }}</p>
                <h2 id="sh-s2" class="cu-panel-title">{{ $t('wizard.how_send') }}</h2>
                <div class="sh-choices" role="radiogroup" aria-labelledby="sh-s2">
                    @foreach (\App\Services\Sharing\ShareMessage::CHANNELS as $option)
                        <label class="sh-choice sh-channel-{{ $option }}">
                            <input type="radio" name="channel" value="{{ $option }}" @checked($channelValue === $option) data-sh-channel>
                            <span><strong>{{ $t('channels.'.$option) }}</strong><small>{{ $t('wizard.channel_text.'.$option) }}</small></span>
                        </label>
                    @endforeach
                </div>
                <p class="sh-note">{{ $t('wizard.manual_note') }}</p>
                <div class="sh-step-actions">
                    <button type="button" class="cu-button cu-button-ghost" data-sh-prev>{{ $t('wizard.back') }}</button>
                    <button type="button" class="cu-button cu-button-primary" data-sh-next>{{ $t('wizard.next') }}</button>
                </div>
            </section>

            {{-- Step 3: message --}}
            <section class="cu-panel sh-step" data-step="2" aria-labelledby="sh-s3">
                <p class="sh-step-count">{{ $t('wizard.step', ['n' => 3]) }}</p>
                <h2 id="sh-s3" class="cu-panel-title">{{ $t('wizard.message') }}</h2>
                @include('reference_ui.partials.share_message_editor', ['templateKey' => $templateKey, 'message' => $message, 'name' => 'message', 'previewName' => $values['guest_name']])
                <label class="cu-check"><input type="checkbox" name="save_template" value="1" @checked(old('save_template')) data-sh-save-toggle><span>{{ $t('wizard.save_template') }}</span></label>
                <label class="cu-field" data-sh-save-name @unless (old('save_template')) hidden @endunless>
                    <span>{{ $t('wizard.template_name') }}</span>
                    <input type="text" name="template_name" maxlength="80" value="{{ old('template_name') }}">
                </label>
                <p class="sh-note">{{ $t('honesty') }}</p>
                <div class="sh-step-actions">
                    <button type="button" class="cu-button cu-button-ghost" data-sh-prev>{{ $t('wizard.back') }}</button>
                    <button type="submit" class="cu-button cu-button-primary" data-sh-start>{{ $t('wizard.start_plain') }}</button>
                </div>
            </section>
        </form>
    </main>
</div>
<script type="application/json" id="sh-config">@json($config)</script>
</body>
</html>
