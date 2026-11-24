{{-- Guest RSVP form (no JavaScript needed): Yes / No as large choices, the headcount appears
     with Yes (CSS :has), an optional message, one submit. Server re-validates everything. --}}
@php
    $r = fn (string $key, array $replace = []) => __('rsvp.guest.'.$key, $replace, $language);
    $current = old('response', $rsvp->response !== 'pending' ? $rsvp->response : null);
    $currentCount = (int) old('count', $rsvp->attending_count ?? $allowed);
@endphp
<form class="pi-form" method="POST" action="{{ route('invitations.public.rsvp', ['key' => $key]) }}">
    @csrf
    <input type="hidden" name="submission" value="{{ $submissionKey }}">
    <input type="hidden" name="revision" value="{{ (int) $rsvp->revision }}">
    <fieldset class="pi-choices">
        <legend class="sr-only">{{ $r('question') }}</legend>
        <label class="pi-choice is-yes"><input type="radio" name="response" value="attending" required @checked($current === 'attending')><span>{{ $r('yes') }}</span></label>
        <label class="pi-choice is-no"><input type="radio" name="response" value="not_attending" required @checked($current === 'not_attending')><span>{{ $r('no') }}</span></label>
    </fieldset>
    @if ($allowed > 1)
        <label class="pi-field pi-count">
            <span>{{ $r('how_many') }}</span>
            <select name="count">
                @for ($n = 1; $n <= min($allowed, 100); $n++)
                    <option value="{{ $n }}" @selected($currentCount === $n)>{{ trans_choice('rsvp.guest.people', $n, ['count' => $n], $language) }}</option>
                @endfor
            </select>
            <small>{{ $r('how_many_help', ['max' => $allowed]) }}</small>
        </label>
    @endif
    <label class="pi-field">
        <span>{{ $r('note') }}</span>
        <textarea name="note" rows="2" maxlength="{{ \App\Services\Rsvp\RsvpService::NOTE_MAX }}">{{ old('note', $rsvp->note) }}</textarea>
    </label>
    <button class="cu-button cu-button-primary pi-send" type="submit">{{ $r('send') }}</button>
</form>
