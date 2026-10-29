<?php

use Illuminate\Support\Facades\Route;

test('urls keep the https scheme of the proxy that terminated tls', function () {
    Route::get('/_proxy-probe', fn (): string => url('/login'));

    $this->get('/_proxy-probe', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'jarvis.example.com',
        'X-Forwarded-Port' => '443',
    ])->assertOk()->assertSee('https://jarvis.example.com/login', false);
});
