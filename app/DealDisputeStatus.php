<?php

namespace App;

enum DealDisputeStatus: string
{
    case Open = 'open';
    case Settled = 'settled';
    case ClosedUnsettled = 'closed_unsettled';
}
