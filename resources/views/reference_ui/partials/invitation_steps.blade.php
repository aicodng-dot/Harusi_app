{{--
    Five-stage invitation indicator: Choose Design -> Customize Card -> Add Guests -> Create Cards -> Share.
    $progress comes from App\Services\Invitations\InvitationProgress (saved data only);
    $stage is the stage this screen represents. Completed stages link back to their screen.
--}}
@php
    use App\Services\Invitations\InvitationProgress;

    $st = fn (string $key, array $replace = []) => __('invitation_studio.steps.'.$key, $replace, $language);
    $pageStep = array_search($stage, InvitationProgress::STAGES, true) + 1;
@endphp
<nav class="inv-steps" aria-label="{{ $st('aria') }}">
    <p class="inv-steps-label">{{ $st('label', ['step' => $pageStep, 'name' => $st('names.'.$stage)]) }}</p>
    <p class="inv-steps-hint">{{ $st('hints.'.$stage) }}</p>
    <ol class="inv-steps-track">
        @foreach (InvitationProgress::STAGES as $index => $key)
            @php
                $isDone = in_array($key, $progress['done'], true);
                $isHere = $key === $stage;
            @endphp
            <li @class(['inv-step', 'is-done' => $isDone && ! $isHere, 'is-current' => $isHere]) @if ($isHere) aria-current="step" @endif>
                @if ($isDone && ! $isHere)
                    <a href="{{ route(InvitationProgress::route($key), ['event' => $event->id]) }}" title="{{ $st('names.'.$key) }}">
                        <span class="inv-step-dot" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 12.5 4 4 8-9"/></svg></span>
                        <span class="inv-step-name">{{ $st('names.'.$key) }}</span>
                        <span class="sr-only">({{ $st('done') }})</span>
                    </a>
                @else
                    <span class="inv-step-dot" aria-hidden="true">{{ $index + 1 }}</span>
                    <span class="inv-step-name">{{ $st('names.'.$key) }}</span>
                    @if ($isHere)<span class="sr-only">({{ $st('current') }})</span>@endif
                @endif
            </li>
        @endforeach
    </ol>
</nav>
