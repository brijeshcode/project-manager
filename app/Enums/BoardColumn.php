<?php

namespace App\Enums;

enum BoardColumn: string
{
    case Backlog    = 'Backlog';
    case InProgress = 'In Progress';
    case Testing    = 'Testing';
    case Done       = 'Done';

    /** Ordered list used for board rendering. */
    public static function ordered(): array
    {
        return [self::Backlog, self::InProgress, self::Testing, self::Done];
    }

    /** Returns true if the given string is a valid column value. */
    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }
}
