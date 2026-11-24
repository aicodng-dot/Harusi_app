@php
    use App\Models\Guest;

    $t = fn (string $key, array $replace = []) => __('customer_guests.'.$key, $replace, $language);
    $tc = fn (string $key, int $count, array $replace = []) => trans_choice('customer_guests.'.$key, $count, $replace + ['count' => $count], $language);
    $passes = [Guest::PASS_SINGLE => $t('add.passes.single'), Guest::PASS_DOUBLE => $t('add.passes.double'), Guest::PASS_SPECIAL => $t('add.passes.special')];
    $saveable = $counts['ready'];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('review.page_title') }} · {{ $event->event_name }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js', 'resources/js/reference_ui/customer_guests_add.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page cu-wide">
    <header class="is-header">
        <a class="is-back" href="{{ route('customer.events.guests.create', ['event' => $event->id]) }}" aria-label="{{ $t('review.back') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
        </a>
        <span class="is-brand">PangaEvents</span>
        <span></span>
    </header>

    <main class="is-main" aria-labelledby="gr-heading">
        <h1 id="gr-heading" class="is-heading">{{ $t('review.title') }}</h1>
        <p class="is-intro">{{ $t('review.intro') }}</p>

        @if ($error === 'fix')
            <p class="is-alert is-alert-error" role="alert">{{ $t('review.fix') }}</p>
        @elseif ($error === 'locked')
            <p class="is-alert is-alert-error" role="alert">{{ $t('errors.locked') }}</p>
        @endif
        @if ($truncated)
            <p class="is-alert is-alert-warn" role="status">{{ $t('review.truncated', ['max' => $maxRows]) }}</p>
        @endif

        <div class="cu-stats" data-gr-stats>
            <div class="cu-stat"><small>{{ $t('review.total') }}</small><strong data-gr-count="total">{{ $counts['total'] }}</strong></div>
            <div class="cu-stat is-ok"><small>{{ $t('review.ready') }}</small><strong data-gr-count="ready">{{ $counts['ready'] }}</strong></div>
            <div class="cu-stat is-bad"><small>{{ $t('review.invalid') }}</small><strong data-gr-count="invalid">{{ $counts['invalid'] }}</strong></div>
            <div class="cu-stat is-warn"><small>{{ $t('review.duplicate') }}</small><strong data-gr-count="duplicate">{{ $counts['duplicate'] }}</strong></div>
        </div>
        <p class="cu-help cu-help-small">{{ $t('review.defaults_note') }}</p>

        <form method="POST" action="{{ route('customer.events.guests.bulk', ['event' => $event->id]) }}" novalidate data-gr-form data-ga-json>
            @csrf
            <input type="hidden" name="source" value="list">
            @if ($counts['duplicate'] > 0)
                <button type="button" class="cu-button cu-button-ghost" hidden data-gr-remove-duplicates>{{ $t('review.remove_duplicates') }} ({{ $counts['duplicate'] }})</button>
            @endif
            <ol class="cu-review" data-ga-entries>
                @foreach ($rows as $i => $row)
                    @php $data = $row['data']; $bad = fn (string $field) => in_array($field, $row['errors'], true); @endphp
                    <li @class(['cu-review-row', 'is-'.$row['status']]) data-ga-entry data-status="{{ $row['status'] }}">
                        <div class="cu-review-head">
                            <span class="cu-review-number">{{ $i + 1 }}</span>
                            <span @class(['cu-pill', 'is-'.$row['status']])>{{ $t('review.status.'.$row['status']) }}</span>
                            <label class="cu-remove"><input type="checkbox" name="rows[{{ $i }}][remove]" value="1" data-gr-remove><span>{{ $t('review.remove') }}</span></label>
                        </div>
                        <div class="cu-entry-grid">
                            <label @class(['cu-field', 'cu-field-name', 'is-invalid' => $bad('name')])><span>{{ $t('single.name') }}</span>
                                <input type="text" name="rows[{{ $i }}][name]" value="{{ $data['name'] }}" maxlength="120" autocomplete="off" data-ga-field="name" @if ($bad('name')) aria-invalid="true" @endif></label>
                            <label @class(['cu-field', 'cu-field-phone', 'is-invalid' => $bad('phone_number')])><span>{{ $t('single.phone') }}</span>
                                <input type="tel" name="rows[{{ $i }}][phone_number]" value="{{ $data['phone_number'] }}" maxlength="40" inputmode="tel" autocomplete="off" data-ga-field="phone_number" @if ($bad('phone_number')) aria-invalid="true" @endif></label>
                            <label @class(['cu-field', 'is-invalid' => $bad('pass_type')])><span>{{ $t('single.pass') }}</span>
                                <select name="rows[{{ $i }}][pass_type]" data-ga-field="pass_type" data-ga-pass>
                                    @unless (isset($passes[$data['pass_type']]))<option value="{{ $data['pass_type'] }}" selected>{{ $data['pass_type'] }}</option>@endunless
                                    @foreach ($passes as $value => $label)<option value="{{ $value }}" @selected($data['pass_type'] === $value)>{{ $label }}</option>@endforeach
                                </select></label>
                            <label @class(['cu-field', 'cu-field-people', 'is-invalid' => $bad('allowed_entries')])><span>{{ $t('single.people') }}</span>
                                <input type="number" name="rows[{{ $i }}][allowed_entries]" value="{{ $data['allowed_entries'] }}" min="1" max="{{ Guest::MAX_ALLOWED_ENTRIES }}" inputmode="numeric" data-ga-field="allowed_entries" data-ga-people @if ($bad('allowed_entries')) aria-invalid="true" @endif></label>
                        </div>
                        @if ($row['status'] === 'invalid')
                            <ul class="cu-row-errors">@foreach ($row['errors'] as $field)<li>{{ $t('errors.'.$field) }}</li>@endforeach</ul>
                        @elseif ($row['status'] === 'duplicate' && $row['duplicate'])
                            <p class="cu-row-note">{{ $t('review.duplicate_reason.'.$row['duplicate']) }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
            <div class="cu-sticky-actions">
                <button type="submit" class="cu-button cu-button-ghost" name="action" value="check">{{ $t('review.check') }}</button>
                <button type="submit" class="cu-button cu-button-primary" name="action" value="save" data-gr-save data-template="{{ $tc('review.save', 2, ['count' => '__N__']) }}" data-one="{{ $tc('review.save', 1) }}" data-zero="{{ $tc('review.save', 0) }}" data-generic="{{ $t('review.save_generic') }}">{{ $counts['invalid'] > 0 ? $t('review.save_generic') : $tc('review.save', $saveable) }}</button>
            </div>
        </form>
        <p class="cu-back-link"><a class="is-small-link" href="{{ route('customer.events.guests.create', ['event' => $event->id]) }}">← {{ $t('review.back') }}</a></p>
    </main>
</div>
</body>
</html>
