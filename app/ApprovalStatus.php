<?php

namespace App;

/**
 * The outcome of a maker-checker proposal awaiting independent review.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
