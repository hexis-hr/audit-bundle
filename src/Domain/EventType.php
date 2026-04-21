<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Domain;

enum EventType: string
{
    case LOGIN = 'login';
    case LOGIN_FAILURE = 'login_failure';
    case LOGOUT = 'logout';
    case SWITCH_USER_ENTER = 'switch_user_enter';
    case SWITCH_USER_EXIT = 'switch_user_exit';
    case ENTITY_INSERT = 'entity_insert';
    case ENTITY_UPDATE = 'entity_update';
    case ENTITY_DELETE = 'entity_delete';
    case CUSTOM = 'custom';

    public function isSecurity(): bool
    {
        return match ($this) {
            self::LOGIN,
            self::LOGIN_FAILURE,
            self::LOGOUT,
            self::SWITCH_USER_ENTER,
            self::SWITCH_USER_EXIT => true,
            default => false,
        };
    }

    public function isEntity(): bool
    {
        return match ($this) {
            self::ENTITY_INSERT,
            self::ENTITY_UPDATE,
            self::ENTITY_DELETE => true,
            default => false,
        };
    }
}
