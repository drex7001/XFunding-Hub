<?php

namespace App;

enum OfferStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Selected = 'selected';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
    case Unsuccessful = 'unsuccessful';

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'Awaiting approval',
            default => ucfirst($this->value),
        };
    }
}
