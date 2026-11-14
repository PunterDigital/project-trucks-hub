<?php

namespace App\Enums;

enum CompanyRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Driver = 'driver';

    /** Owners and managers run recruitment and can remove drivers. */
    public function canManage(): bool
    {
        return $this !== self::Driver;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
