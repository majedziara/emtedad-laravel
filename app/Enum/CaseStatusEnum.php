<?php

namespace App\Enum;

enum CaseStatusEnum: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case PAUSED = 'paused';
    case COMPLETED = 'completed';
    case ARCHIVED = 'archived';
}
