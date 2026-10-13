<?php

namespace App\States;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class LoanApplicationState extends State
{
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Submitted::class)
            ->allowTransition(Submitted::class, UnderReview::class)
            ->allowTransition(Submitted::class, Cancelled::class)
            ->allowTransition(UnderReview::class, Approved::class)
            ->allowTransition(UnderReview::class, Rejected::class)
            ->allowTransition(UnderReview::class, Cancelled::class)
            ->allowTransition(Approved::class, Disbursed::class);
    }

    public static function classesByName(): array
    {
        return [
            'SUBMITTED' => Submitted::class,
            'UNDER_REVIEW' => UnderReview::class,
            'APPROVED' => Approved::class,
            'REJECTED' => Rejected::class,
            'DISBURSED' => Disbursed::class,
            'CANCELLED' => Cancelled::class,
        ];
    }
}
