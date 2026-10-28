<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
}
