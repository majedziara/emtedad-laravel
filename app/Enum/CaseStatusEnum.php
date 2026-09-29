<?php

namespace App\Enum;

enum CaseStatusEnum: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case PAUSED = 'paused';
    case COMPLETED = 'completed';
    case ARCHIVED = 'archived';

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return in_array($next, match ($this) {
            self::DRAFT => [self::PUBLISHED],
            self::PUBLISHED => [self::DRAFT, self::PAUSED, self::COMPLETED],
            self::PAUSED => [self::DRAFT, self::PUBLISHED, self::COMPLETED],
            self::COMPLETED => [self::DRAFT, self::PUBLISHED],
            self::ARCHIVED => [],
        }, true);
    }
}
