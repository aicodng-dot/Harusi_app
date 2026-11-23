@props(['name'])
<svg {{ $attributes }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('search')
            <circle cx="10.5" cy="10.5" r="7"/><path d="m20.5 20.5-5-5"/>
            @break
        @case('filter')
            <path d="M3.5 4.5h17l-6.6 7.9v6.1l-3.8 2v-8.1z"/><path d="M15.2 8.4h4.3" stroke-width="1.6"/>
            @break
        @case('plus')
            <path d="M12 4.5v15M4.5 12h15" stroke-width="2.4"/>
            @break
        @case('calendar')
            <rect x="3.5" y="5" width="17" height="15.5" rx="2.2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>
            @break
        @case('pin')
            <path d="M12 21s-6.5-5.6-6.5-11.2a6.5 6.5 0 0 1 13 0C18.5 15.4 12 21 12 21z"/><circle cx="12" cy="9.8" r="2.4"/>
            @break
        @case('guests')
            <circle cx="9" cy="8" r="3.6"/><path d="M2.8 20c0-3.6 2.8-5.8 6.2-5.8s6.2 2.2 6.2 5.8"/><path d="M15.3 4.6a3.5 3.5 0 0 1 0 6.8M17.6 14.4c2.2.6 3.6 2.6 3.6 5.6"/>
            @break
        @case('home')
            <path d="M3.8 10.2 12 3.6l8.2 6.6v9.4a1.4 1.4 0 0 1-1.4 1.4H5.2a1.4 1.4 0 0 1-1.4-1.4z"/><path d="M9.6 21v-6.2h4.8V21"/>
            @break
        @case('events')
            <rect x="3" y="4.5" width="18" height="16.5" rx="2.6" fill="currentColor" stroke="none"/>
            <path d="M8 2.6v4M16 2.6v4" stroke-width="2"/>
            <path d="M3 9.6h18" stroke="#fff" stroke-width="1.6"/>
            <g fill="#fff" stroke="none"><circle cx="8" cy="13.4" r="1.15"/><circle cx="12" cy="13.4" r="1.15"/><circle cx="16" cy="13.4" r="1.15"/><circle cx="8" cy="17.2" r="1.15"/><circle cx="12" cy="17.2" r="1.15"/></g>
            @break
        @case('reports')
            <rect x="4" y="11" width="3.6" height="9.5" rx="1.6"/><rect x="10.2" y="4" width="3.6" height="16.5" rx="1.6"/><rect x="16.4" y="8" width="3.6" height="12.5" rx="1.6"/>
            @break
        @case('logout')
            <path d="M14 4.5H6.5a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2H14"/><path d="M10 12h10m-3.5-3.5L20 12l-3.5 3.5"/>
            @break
        @case('alert')
            <circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.4v.1" stroke-width="2.2"/>
            @break
        @case('close')
            <path d="m6 6 12 12M18 6 6 18"/>
            @break
    @endswitch
</svg>
