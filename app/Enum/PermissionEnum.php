<?php

namespace App\Enum;

enum PermissionEnum: string
{
    case DASHBOARD_VIEW = 'dashboard.view';
    case CATEGORIES_MANAGE = 'categories.manage';
    case CASES_DOCUMENTS_VIEW = 'cases.documents.view';
    case CASES_DOCUMENTS_MANAGE = 'cases.documents.manage';
    case CASES_VIEW = 'cases.view';
    case CASES_CREATE = 'cases.create';
    case CASES_UPDATE = 'cases.update';
    case CASES_PUBLISH = 'cases.publish';
    case CASES_ARCHIVE = 'cases.archive';
    case DONATIONS_VIEW = 'donations.view';
    case DONATIONS_EXPORT = 'donations.export';
    case CONTENT_MANAGE = 'content.manage';
    case SETTINGS_MANAGE = 'settings.manage';
    case USERS_MANAGE = 'users.manage';
    case ROLES_MANAGE = 'roles.manage';
}
