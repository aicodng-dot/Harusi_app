@props(['name'])
<svg {{ $attributes }} viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('user')
            <circle cx="14" cy="8" r="4.5"/><path d="M4.5 25v-2.5c0-3.8 4-5.8 9.5-5.8s9.5 2 9.5 5.8V25z"/>
            @break
        @case('mail')
            <rect x="3" y="5" width="22" height="19" rx="2"/><path d="m5 8 9 7 9-7"/>
            @break
        @case('lock')
            <rect x="5" y="11" width="18" height="15" rx="2"/><path d="M9 11V8a5 5 0 0 1 10 0v3"/><circle cx="14" cy="18" r=".8"/><path d="M14 19v2"/>
            @break
        @case('eye')
            <path d="M1.5 14S6 6 14 6s12.5 8 12.5 8S22 22 14 22 1.5 14 1.5 14Z"/><circle cx="14" cy="14" r="3.5"/><path class="register-eye-slash" d="m4 3 20 22"/>
            @break
        @case('chevron')
            <path d="m6 10 8 8 8-8"/>
            @break
    @endswitch
</svg>
