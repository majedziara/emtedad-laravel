<?php

namespace App\Enum;

enum PublicationStatusEnum: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
}
