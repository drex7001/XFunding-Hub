<?php

it('retires the public sample loan endpoints with 404', function (string $method, string $path) {
    $this->json($method, $path)->assertNotFound();
})->with([
    'create' => ['POST', '/api/v1/loan-applications'],
    'read' => ['GET', '/api/v1/loan-applications/LA1001'],
    'status' => ['PATCH', '/api/v1/loan-applications/LA1001/status'],
]);
