<?php

namespace App\Enum;

enum RoleEnum: string
{
    case ADMIN = 'admin';
    case MEMBER = 'member';
    case DONOR = 'donor';
}
