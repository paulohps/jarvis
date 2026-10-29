<?php

test('returns a successful response', function () {
    $this->get('/login')->assertOk();
});
