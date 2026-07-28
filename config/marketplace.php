<?php

return [
    'currencies' => ['LKR', 'USD', 'GBP'],
    'day_counts' => ['ACT/360', 'ACT/365F'],
    'rate_basis' => 'annual_simple',
    'max_tenor_days' => 3650,
    'max_document_kilobytes' => 10240,
    'collateral_types' => ['unsecured', 'sovereign_bonds'],
    'interest_payments' => ['at_maturity', 'monthly'],
];
