<?php

namespace App;

enum DealStatus: string
{
    case AwaitingFee = 'awaiting_fee';
    case Unlocked = 'unlocked';
    case Disputed = 'disputed';
    case Settled = 'settled';
    case ClosedUnsettled = 'closed_unsettled';
    case Cancelled = 'cancelled';
}
