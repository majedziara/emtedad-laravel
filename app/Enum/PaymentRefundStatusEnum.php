<?php

namespace App\Enum;

enum PaymentRefundStatusEnum: string
{
    case COMPLETED = 'COMPLETED';
    case PENDING = 'PENDING';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
