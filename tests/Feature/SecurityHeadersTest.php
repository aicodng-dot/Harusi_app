<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_pages_send_baseline_security_headers(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        // The browser scanner needs the camera on this origin.
        $this->assertStringContainsString('camera=(self)', (string) $response->headers->get('Permissions-Policy'));
    }

    public function test_route_level_headers_are_not_overridden(): void
    {
        Route::middleware('web')->get('/__security-headers-probe', fn () => response('ok')->withHeaders(['Referrer-Policy' => 'no-referrer']));

        $response = $this->get('/__security-headers-probe');

        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_redirects_also_carry_the_headers(): void
    {
        $this->get('/admin/dashboard')
            ->assertRedirect(route('login'))
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
