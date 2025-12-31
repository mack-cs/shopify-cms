<?php

namespace App\Enums;

enum RolesEnum: string
{
    case SuperAdmin   = 'SuperAdmin';
    case Admin        = 'Admin';
    case User         = 'User';
    case SeoReviewer  = 'SeoReviewer';
}
