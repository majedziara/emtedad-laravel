<?php

namespace App\Enum;

enum WebhookStatusEnum: string
{
    case RECEIVED = 'received';
    case PROCESSED = 'processed';
    case FAILED = 'failed';
    case IGNORED = 'ignored';
}
