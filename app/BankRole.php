<?php

namespace App;

enum BankRole: string
{
    case Admin = 'bank_admin';
    case Maker = 'treasury_maker';
    case Approver = 'treasury_approver';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Bank admin',
            self::Maker => 'Treasury maker',
            self::Approver => 'Treasury approver',
            self::Auditor => 'Auditor',
        };
    }
}
