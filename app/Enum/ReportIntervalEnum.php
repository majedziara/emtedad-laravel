<?php

namespace App\Enum;

enum ReportIntervalEnum: string
{
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';
}
