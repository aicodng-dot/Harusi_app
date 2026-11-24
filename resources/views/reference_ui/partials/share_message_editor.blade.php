{{-- Message editor: start-from template, the text with placeholder buttons, a live preview
     for one guest and the character / SMS counter. Behaviour: resources/js/reference_ui/customer_share.js. --}}
@php
    $t = fn (string $key, array $replace = []) => __('customer_share.'.$key, $replace, $language);
    $editorId = $editorId ?? 'sh-msg';
@endphp
<div class="sh-editor" data-sh-editor @if (! empty($rendered)) data-rendered @endif>
    @if (! empty($templates))
        <label class="cu-field">
            <span>{{ $t('wizard.template') }}</span>
            <select data-sh-template>
                @foreach ($templates as $key => $template)
                    <option value="{{ $key }}" @selected($templateKey === $key)>{{ $template['name'] }}</option>
                @endforeach
            </select>
        </label>
    @endif
    <label class="cu-field">
        <span>{{ $label ?? $t('wizard.message') }} <span class="cu-counter" data-sh-counter></span></span>
        <textarea id="{{ $editorId }}" name="{{ $name }}" rows="8" maxlength="{{ \App\Services\Sharing\ShareMessage::MAX_LENGTH * 2 }}" data-sh-body @if (! empty($readOnlyBody)) readonly @endif>{{ $message }}</textarea>
    </label>
    @if (empty($hideInsert))
        <div class="sh-insert" role="group" aria-label="{{ $t('wizard.insert') }}">
            <span>{{ $t('wizard.insert') }}:</span>
            @foreach (\App\Services\Sharing\ShareMessage::PLACEHOLDERS as $placeholder)
                <button type="button" class="sh-token" data-sh-insert="{{ $placeholder }}">{{ $t('placeholders.'.$placeholder) }}</button>
            @endforeach
        </div>
    @endif
    <p class="sh-problems" data-sh-problems role="alert" hidden></p>
    @if (empty($hidePreview))
        <figure class="sh-preview">
            <figcaption>{{ $t('wizard.preview', ['name' => $previewName]) }}</figcaption>
            <div class="sh-bubble" data-sh-preview></div>
            <small class="sh-unicode" data-sh-unicode hidden>{{ $t('wizard.unicode') }}</small>
        </figure>
    @endif
</div>
