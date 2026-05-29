<nav class="scanner-bottom-nav grid-cols-4" aria-label="Scanner navigation">
    <a href="{{ route('scanner.dashboard') }}" @class(['scanner-bottom-link', 'is-active' => request()->routeIs('scanner.dashboard')])>
        <span class="scanner-bottom-icon">HM</span>
        <span>Home</span>
    </a>
    <a href="{{ route('scanner.scan') }}" @class(['scanner-bottom-link', 'is-active' => request()->routeIs('scanner.scan') || request()->routeIs('scanner.verify-token') || request()->routeIs('scanner.ticket')])>
        <span class="scanner-bottom-icon">QR</span>
        <span>Scan</span>
    </a>
    <a href="{{ route('scanner.manual-search') }}" @class(['scanner-bottom-link', 'is-active' => request()->routeIs('scanner.manual-search')])>
        <span class="scanner-bottom-icon">SR</span>
        <span>Manual</span>
    </a>
    <a href="{{ route('scanner.recent-scans') }}" @class(['scanner-bottom-link', 'is-active' => request()->routeIs('scanner.recent-scans')])>
        <span class="scanner-bottom-icon">RC</span>
        <span>Recent</span>
    </a>
</nav>
