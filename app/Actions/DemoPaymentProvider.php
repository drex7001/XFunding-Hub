<?php

namespace App\Actions;

use Illuminate\Http\Request;

class DemoPaymentProvider
{
    public function enabled(): bool
    {
        $secret = config('payments.webhook_secret');

        return app()->environment(['local', 'testing']) && config('payments.driver') === 'demo'
            && (bool) config('payments.demo_enabled') && is_string($secret) && strlen($secret) >= 32;
    }

    public function assertEnabled(): void
    {
        abort_unless($this->enabled(), 404);
    }

    public function signature(string $body, string $timestamp): string
    {
        $this->assertEnabled();

        return hash_hmac('sha256', $timestamp.'.'.$body, config('payments.webhook_secret'));
    }

    public function authenticate(Request $request): void
    {
        $this->assertEnabled();
        abort_unless($request->isJson(), 415, 'Payment notifications must use JSON.');
        $body = $request->getContent();
        abort_if(strlen($body) > 16384, 413);
        $timestamp = (string) $request->header('X-Payment-Timestamp', '');
        $signature = (string) $request->header('X-Payment-Signature', '');
        abort_unless(preg_match('/^[0-9]{1,12}$/', $timestamp) && preg_match('/^[a-f0-9]{64}$/', $signature), 401, 'Invalid payment signature.');
        abort_unless(abs(now()->getTimestamp() - (int) $timestamp) <= config('payments.signature_tolerance_seconds'), 401, 'Expired payment signature.');
        abort_unless(hash_equals($this->signature($body, $timestamp), $signature), 401, 'Invalid payment signature.');
    }
}
