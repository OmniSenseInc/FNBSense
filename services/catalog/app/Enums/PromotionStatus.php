<?php

declare(strict_types=1);

namespace App\Enums;

enum PromotionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
}
