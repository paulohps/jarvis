<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * Every route under `/api/v1`, as `METHOD /path` relative to the prefix
 * (`HEAD` and the `PUT` twin of a `PATCH` left out).
 *
 * @return list<string>
 */
function apiV1Endpoints(): array
{
    $endpoints = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RoutingRoute $route */
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $methods = array_diff($route->methods(), ['HEAD']);

        if (in_array('PATCH', $methods, true)) {
            $methods = array_diff($methods, ['PUT']);
        }

        foreach ($methods as $method) {
            $endpoints[] = $method.' '.substr($route->uri(), strlen('api/v1'));
        }
    }

    sort($endpoints);

    return $endpoints;
}

test('api exposes no invoice routes and admin routes only to the super admin', function () {
    $webOnly = '/invoice|issuer|payer|template|connect|qr|ai-provider/i';

    $endpoints = apiV1Endpoints();

    expect($endpoints)->not->toBeEmpty()
        ->and(array_values(preg_grep($webOnly, $endpoints)))->toBe([]);

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }

        expect($route->gatherMiddleware())->not->toContain('module:invoices');

        // The super admin's area of the apps lives under `/admin`, and only there.
        if (str_starts_with($route->uri(), 'api/v1/admin/')) {
            expect($route->gatherMiddleware())->toContain('can:super-admin');
        } else {
            expect($route->gatherMiddleware())->not->toContain('can:super-admin');
        }
    }

    // The WhatsApp accounts are read only: no connecting, reconnecting or removing.
    expect(array_values(preg_grep('#^(?!GET ).* /whatsapp/accounts#', $endpoints)))->toBe([]);

    $this->artisan('route:list', ['--path' => 'api/v1'])
        ->expectsOutputToContain('api/v1/shopping/lists')
        ->doesntExpectOutputToContain('invoice')
        ->assertSuccessful();
});

test('the openapi contract covers every api v1 endpoint', function () {
    $spec = Yaml::parseFile(base_path('docs/api/v1.yaml'));

    expect($spec['openapi'])->toStartWith('3.')
        ->and($spec['servers'][0]['url'])->toBe('/api/v1');

    $documented = [];

    foreach ($spec['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            if ($method !== 'parameters') {
                $documented[] = strtoupper($method).' '.$path;
            }
        }
    }

    sort($documented);

    // Both ways: nothing undocumented, nothing documented that does not exist.
    expect($documented)->toBe(apiV1Endpoints());

    // Every `$ref` points at a component that exists.
    preg_match_all("/\\\$ref: '#\\/components\\/(\\w+)\\/(\\w+)'/", (string) file_get_contents(base_path('docs/api/v1.yaml')), $references, PREG_SET_ORDER);

    expect($references)->not->toBeEmpty();

    foreach ($references as [, $section, $name]) {
        expect($spec['components'][$section] ?? [])->toHaveKey($name);
    }
});
