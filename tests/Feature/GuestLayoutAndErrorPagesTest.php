<?php

use App\Exceptions\ModuleAccessDenied;
use App\Models\Locale;
use App\Models\Module;
use App\Models\User;
use App\Support\Localization;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    Route::middleware('web')->group(function () {
        Route::get('/error-probe/module', fn () => throw ModuleAccessDenied::for(Module::INVOICES));
        Route::get('/error-probe/{status}', fn (int $status) => abort($status))->whereNumber('status');
    });
});

test('the guest layout shows the brand, the language switch and the glass card', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Jarvis')
        ->assertSee('Sign in to Jarvis')
        ->assertSee('glass-thick', false)
        ->assertSee('ambient-backdrop', false)
        ->assertSee(route('locale.update', 'pt_BR'), false)
        ->assertSee('Português (Brasil)')
        ->assertSee('href="#main-content"', false);
});

test('every page carries the brand icons and the web app manifest', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false)
        ->assertSee('<link rel="manifest" href="'.route('pwa.manifest').'" crossorigin="use-credentials">', false)
        ->assertSee('src="'.asset('favicon.svg').'"', false);

    expect($this->get(route('pwa.manifest'))->assertOk()->json())
        ->name->toBe('Jarvis')
        ->icons->each(fn ($icon) => $icon->src->toBeString());

    foreach (['favicon.ico', 'apple-touch-icon.png', 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png'] as $icon) {
        expect(public_path($icon))->toBeFile();
    }
});

test('guests are redirected to the sign in page and users away from it', function () {
    $this->get('/')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get('/login')
        ->assertRedirect(route('dashboard'));
});

test('a visitor switches the interface language', function () {
    $this->from('/login')
        ->get(route('locale.update', 'pt_BR'))
        ->assertRedirect('/login')
        ->assertSessionHas(Localization::SESSION_KEY, 'pt_BR');

    $this->get('/login')->assertSee('Entrar no Jarvis');
});

test('an unsupported language is not found', function () {
    $this->get('/locale/de')->assertNotFound();
});

test('error pages are translated', function (int $status, string $english, string $portuguese) {
    $this->get("/error-probe/{$status}")
        ->assertStatus($status)
        ->assertSee($english)
        ->assertSee((string) $status);

    $this->withSession([Localization::SESSION_KEY => 'pt_BR'])
        ->get("/error-probe/{$status}")
        ->assertStatus($status)
        ->assertSee($portuguese);
})->with([
    [403, 'Access denied', 'Acesso negado'],
    [404, 'Page not found', 'Página não encontrada'],
    [419, 'Page expired', 'Página expirada'],
    [500, 'Something went wrong', 'Algo deu errado'],
]);

test('an unknown page answers 404 in the reader language', function () {
    $user = User::factory()->create(['locale_id' => Locale::idFor(Locale::PT_BR)]);

    $this->actingAs($user)
        ->get('/no-such-page')
        ->assertNotFound()
        ->assertSee('Página não encontrada');
});

test('the module 403 says the user has no access to the module', function () {
    $this->get('/error-probe/module')
        ->assertForbidden()
        ->assertSee("You don't have access to this module")
        ->assertDontSee('Access denied');

    $this->withSession([Localization::SESSION_KEY => 'pt_BR'])
        ->get('/error-probe/module')
        ->assertForbidden()
        ->assertSee('Você não tem acesso a este módulo');
});

test('the api still answers json errors', function () {
    $this->getJson('/api/v1/no-such-endpoint')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

test('the privacy policy and terms of use are public and linked from the guest screens', function () {
    $this->get('/login')
        ->assertSee(route('legal.privacy'), false)
        ->assertSee(route('legal.terms'), false);

    $this->get(route('legal.privacy'))
        ->assertOk()
        ->assertSee('Privacy policy')
        ->assertSee('gmail.send')
        ->assertSee('Limited Use');

    $this->get(route('legal.terms'))->assertOk()->assertSee('Terms of use');

    $this->actingAs(User::factory()->create(['locale_id' => Locale::idFor(Locale::PT_BR)]))
        ->get(route('legal.privacy'))
        ->assertOk()
        ->assertSee('Política de privacidade')
        ->assertSee('Uso Limitado');
});
