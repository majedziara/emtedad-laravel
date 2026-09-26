<?php

namespace App\Enum;

enum PaymentProviderEnum: string
{
    case PAYPAL = 'paypal';
    case BANK_TRANSFER = 'bank_transfer';
}
