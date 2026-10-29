<?php

use App\Models\Locale;
use App\Models\User;
use App\Support\Pwa\StartupImages;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('the manifest describes the installable app in the reader language', function () {
    $this->get('/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('name', 'Jarvis')
        ->assertJsonPath('lang', 'en')
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('start_url', '/')
        ->assertJsonPath('icons.2.purpose', 'maskable')
        ->assertJsonFragment(['name' => 'Agenda', 'url' => '/agenda'])
        ->assertJsonFragment(['name' => 'Notifications', 'url' => '/notifications'])
        ->assertJsonFragment(['name' => 'Profile', 'url' => '/profile']);

    $user = User::factory()->create(['locale_id' => Locale::idFor('pt_BR')]);

    $this->actingAs($user)
        ->get('/manifest.webmanifest')
        ->assertOk()
        ->assertJsonPath('lang', 'pt-BR')
        ->assertJsonFragment(['name' => 'Notificações', 'url' => '/notifications']);
});

test('the service worker is served from the root and never cached', function () {
    $response = $this->get('/sw.js')
        ->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/')
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

    expect($response->headers->get('Content-Type'))->toStartWith('application/javascript')
        ->and($response->getContent())
        ->toContain("addEventListener('push'")
        ->toContain("addEventListener('notificationclick'")
        ->toContain('const OFFLINE_URL = "/offline"')
        ->toContain('/badge-96.png');
});

test('the offline page stands on its own', function () {
    $this->get('/offline')
        ->assertOk()
        ->assertSee('You are offline')
        ->assertDontSee('/build/');
});

test('every page carries the manifest and the home screen metadata', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('rel="manifest" href="'.route('pwa.manifest').'" crossorigin="use-credentials"', false)
        ->assertSee('name="apple-mobile-web-app-capable" content="yes"', false)
        ->assertSee('rel="apple-touch-startup-image"', false)
        ->assertSee('id="jarvis-pwa-config"', false)
        ->assertSee('"isAuthenticated":false', false)
        ->assertDontSee('data-pwa-install-banner', false);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-pwa-install-banner', false)
        ->assertSee('data-pwa-snooze-days', false)
        ->assertSee('data-pwa-never', false)
        ->assertSee('"isAuthenticated":true', false)
        ->assertSee('"firebase":null', false)
        ->assertDontSee('data-pwa-push-banner', false);
});

test('the page gets the firebase web app once it is set up', function () {
    config([
        'services.firebase.project_id' => 'jarvis-test',
        'services.firebase.web' => [
            'api_key' => 'key',
            'auth_domain' => null,
            'messaging_sender_id' => '123',
            'app_id' => '1:123:web:abc',
            'vapid_key' => 'vapid',
        ],
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('"authDomain":"jarvis-test.firebaseapp.com"', false)
        ->assertSee('"vapidKey":"vapid"', false)
        ->assertSee('data-pwa-push-banner', false)
        ->assertSee('data-pwa-push-snooze-days', false)
        ->assertSee('data-pwa-push-never', false);
});

test('every launch screen listed is rendered at its size', function () {
    foreach (StartupImages::files() as $file => [$width, $height]) {
        $path = public_path("splash/{$file}");

        expect(is_file($path))->toBeTrue("missing {$file}");

        [$actualWidth, $actualHeight] = getimagesize($path) ?: [0, 0];

        expect([$actualWidth, $actualHeight])->toBe([$width, $height], $file);
    }

    expect(is_file(public_path('badge-96.png')))->toBeTrue()
        ->and(count(StartupImages::links()))->toBe(count(StartupImages::files()));
});
