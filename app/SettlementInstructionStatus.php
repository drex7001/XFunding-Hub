<?php

namespace App;

enum SettlementInstructionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Retired = 'retired';
}
