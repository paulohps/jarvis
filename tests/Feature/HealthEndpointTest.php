<?php

test('health endpoint responds ok', function () {
    $this->get('/up')->assertOk();
});

test('health endpoint fails when the database is unreachable', function () {
    $default = config('database.default');

    config([
        'database.connections.unreachable' => array_merge(config("database.connections.{$default}"), [
            'host' => '127.0.0.1',
            'port' => 1,
        ]),
        'database.default' => 'unreachable',
    ]);

    try {
        $this->get('/up')->assertServerError();
    } finally {
        // RefreshDatabase rolls back the default connection on teardown.
        config(['database.default' => $default]);
    }
});
