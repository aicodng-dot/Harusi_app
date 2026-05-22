<nav class="scanner-bottom-nav" aria-label="Scanner navigation">
    <a href="{{ route('scanner.dashboard') }}" @class(['scanner-bottom-link', 'is-active' => request()->routeIs('scanner.dashboard')])>
        <span class="scanner-bottom-icon">H</span>
        <span>Home</span>
    </a>
    <a href="{{ route('scanner.scan') }}" @class(['scanner-bottom-link', 'is-active' => request()->routeIs('scanner.scan') || request()->routeIs('scanner.verify-token') || request()->routeIs('scanner.ticket')])>
        <span class="scanner-bottom-icon">QR</span>
        <span>Scan</span>
    </a>
    <a href="{{ route('scanner.recent-scans') }}" @class(['scanner-bottom-link', 'is-active' => request()->routeIs('scanner.recent-scans')])>
        <span class="scanner-bottom-icon">R</span>
        <span>Recent</span>
    </a>
</nav>
