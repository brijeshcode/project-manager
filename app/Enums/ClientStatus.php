<?php

namespace App\Enums;

enum ClientStatus: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    /** x-ui.badge colour name */
    public function color(): string
    {
        return match ($this) {
            self::Active   => 'green',
            self::Inactive => 'amber',
            self::Archived => 'slate',
        };
    }
}
