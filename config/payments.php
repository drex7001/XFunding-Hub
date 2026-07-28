<?php

return [
    'driver' => 'demo',
    'demo_enabled' => env('DEMO_PAYMENTS_ENABLED', false),
    'webhook_secret' => env('DEMO_PAYMENT_WEBHOOK_SECRET'),
    'signature_tolerance_seconds' => 300,
    'fee_basis_points' => 10,
    'tax_basis_points' => 0,
    'payment_window_hours' => 24,
    'policy_version' => 'demo-v1',
];
