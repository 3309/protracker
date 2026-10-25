<?php

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as Router;

/**
 * Routes reachable without logging in: the login form, the health check,
 * Livewire's own scripts and update endpoint (which check the session and
 * CSRF token themselves), and development tooling that is only registered
 * locally.
 *
 * @return list<string>
 */
function routesOpenToGuests(): array
{
    return ['/', 'login', 'up', 'storage/{path}', '_boost/browser-logs'];
}

it('requires login for every page and action of the app', function () {
    $unprotected = collect(Router::getRoutes()->getRoutes())
        ->reject(fn (Route $route) => in_array($route->uri(), routesOpenToGuests(), true) || str_starts_with($route->uri(), 'livewire-'))
        ->reject(fn (Route $route) => collect($route->gatherMiddleware())->contains(fn ($middleware) => is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:'))))
        ->map(fn (Route $route) => $route->uri())
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});

it('sends guests to the login page from every page, including ones for a specific record', function () {
    $pages = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => in_array('GET', $route->methods(), true) && in_array('auth', $route->gatherMiddleware(), true));

    expect($pages)->not->toBeEmpty();

    foreach ($pages as $route) {
        $this->get('/'.preg_replace('/\{[^}]+\}/', '1', $route->uri()))->assertRedirect(route('login'));
    }
});

it('sends safe browser headers', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('tells browsers to keep using HTTPS when the app is served over HTTPS', function () {
    $this->get('https://protracker.test/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('never serves private files such as backups over the web', function () {
    File::put($file = storage_path('app/private/security-test.sqlite'), 'secret');

    try {
        $this->actingAs(User::factory()->create())->get('/storage/security-test.sqlite')->assertNotFound();
    } finally {
        File::delete($file);
    }
});

it('keeps session cookies encrypted and out of reach of page scripts', function () {
    expect(config('session.encrypt'))->toBeTrue()
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');
});
