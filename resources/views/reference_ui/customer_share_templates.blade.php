@php
    use App\Services\Sharing\ShareMessage;

    $t = fn (string $key, array $replace = []) => __('customer_share.'.$key, $replace, $language);
    $ev = ['event' => $event->id];
    $config = ['values' => $values, 'placeholders' => ShareMessage::PLACEHOLDERS, 'max' => ShareMessage::MAX_LENGTH, 'templates' => [],
        'i18n' => ['chars' => $t('js.chars', ['count' => '__COUNT__']), 'sms_parts' => $t('js.sms_parts', ['count' => '__COUNT__']), 'problems' => __('customer_share.problems', [], $language)]];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('templates.title') }} · {{ $event->event_name }}</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js', 'resources/js/reference_ui/customer_share.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page sh-page">
    <main class="is-main" id="cs-content" aria-labelledby="sh-heading" data-sh-templates>
        <h1 id="sh-heading" class="is-heading">{{ $t('templates.title') }}</h1>
        <p class="is-intro">{{ $t('templates.intro') }}</p>

        @if (session('status'))<p class="sh-note is-ok" role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())
            <div class="sh-note is-bad" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <section class="cu-panel" aria-labelledby="sh-yours">
            <h2 id="sh-yours" class="cu-panel-title">{{ $t('templates.yours') }}</h2>
            @forelse ($saved as $template)
                <details class="cu-details">
                    <summary>{{ $template->name }}</summary>
                    <div class="cu-details-body">
                        <p class="sh-template-text">{{ $template->body }}</p>
                        @unless ($readOnly)
                            <form method="POST" action="{{ route('customer.events.share.templates.update', $ev + ['template' => $template->id]) }}">
                                @csrf @method('PUT')
                                <label class="cu-field"><span>{{ $t('templates.name') }}</span><input type="text" name="name" maxlength="80" required value="{{ $template->name }}"></label>
                                @include('reference_ui.partials.share_message_editor', ['templates' => [], 'templateKey' => '', 'message' => $template->body, 'name' => 'body', 'editorId' => 'sh-t'.$template->id, 'previewName' => $values['guest_name'], 'label' => $t('templates.body')])
                                <div class="cu-actions"><button class="cu-button cu-button-primary" type="submit">{{ $t('templates.update') }}</button></div>
                            </form>
                            <form method="POST" action="{{ route('customer.events.share.templates.destroy', $ev + ['template' => $template->id]) }}" data-sh-confirm-submit="{{ $t('templates.delete_confirm') }}">
                                @csrf @method('DELETE')
                                <button class="sh-danger" type="submit">{{ $t('templates.delete') }}</button>
                            </form>
                        @endunless
                    </div>
                </details>
            @empty
                <p class="cu-help">{{ $t('templates.none') }}</p>
            @endforelse
        </section>

        @unless ($readOnly)
            <section class="cu-panel" aria-labelledby="sh-new">
                <h2 id="sh-new" class="cu-panel-title">{{ $t('templates.new') }}</h2>
                @if ($saved->count() >= $max)
                    <p class="cu-help">{{ $t('templates.limit', ['max' => $max]) }}</p>
                @else
                    <form method="POST" action="{{ route('customer.events.share.templates.store', $ev) }}">
                        @csrf
                        <label class="cu-field"><span>{{ $t('templates.name') }}</span><input type="text" name="name" maxlength="80" required value="{{ old('name') }}"></label>
                        @include('reference_ui.partials.share_message_editor', ['templates' => [], 'templateKey' => '', 'message' => old('body', $defaults['default_whatsapp']['body']), 'name' => 'body', 'editorId' => 'sh-new-body', 'previewName' => $values['guest_name'], 'label' => $t('templates.body')])
                        <div class="cu-actions"><button class="cu-button cu-button-primary" type="submit">{{ $t('templates.save') }}</button></div>
                    </form>
                @endif
            </section>
        @endunless

        <section class="cu-panel" aria-labelledby="sh-builtin">
            <h2 id="sh-builtin" class="cu-panel-title">{{ $t('templates.built_in') }}</h2>
            @foreach ($defaults as $template)
                <details class="cu-details">
                    <summary>{{ $template['name'] }}</summary>
                    <p class="cu-details-body sh-template-text">{{ $template['body'] }}</p>
                </details>
            @endforeach
        </section>
    </main>
</div>
<script type="application/json" id="sh-config">@json($config)</script>
</body>
</html>
