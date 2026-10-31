<?php

namespace App\Enums;

enum PaymentType: string
{
    case Project       = 'project';
    case OtherService  = 'other_service';
    case ProjectUpdate = 'project_update';
    case Maintenance   = 'maintenance';
}
