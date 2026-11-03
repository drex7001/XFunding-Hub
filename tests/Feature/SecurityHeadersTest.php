<?php

it('sends browser hardening headers on every response', function () {
    config(['app.debug' => false]);

    $this->get('/login')->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('Content-Security-Policy')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('adds strict transport security only over HTTPS', function () {
    $this->get('https://localhost/login')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('leaves local debug error pages working by omitting the content security policy', function () {
    config(['app.debug' => true]);

    $this->get('/login')->assertOk()->assertHeaderMissing('Content-Security-Policy');
});
