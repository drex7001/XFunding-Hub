<?php

namespace App;

enum FundingRequestStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Open = 'open';
    case Settled = 'settled';
    case Selected = 'selected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Awaiting approval',
            self::Open => 'Open for offers',
            self::Settled => 'Settled',
            self::Selected => 'Offer selected',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }
}
