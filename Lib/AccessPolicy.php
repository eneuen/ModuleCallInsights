<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

/**
 * Who may use Call Insights. v1 is admin-only (plan rule 9): this check is
 * the real gate, because admins bypass the core ACL and another module could
 * grant other roles access to our controller.
 *
 * Pure PHP, no core dependencies, so it is unit-tested without a PBX.
 */
final class AccessPolicy
{
    /** Same value as MikoPBX\Common\Providers\AclProvider::ROLE_ADMINS. */
    public const string ADMIN_ROLE = 'admins';

    /**
     * Fails closed: an unknown or unreadable role is refused.
     */
    public static function isAllowedRole(?string $role): bool
    {
        return $role === self::ADMIN_ROLE;
    }

    /**
     * Write actions must be a POST, sent as AJAX, with a Bearer token. The
     * core also accepts the login cookie alone, so this is checked here
     * (plan: Security, cross-site request forgery).
     */
    public static function isAllowedWrite(bool $isPost, bool $isAjax, ?string $authorizationHeader): bool
    {
        return $isPost
            && $isAjax
            && $authorizationHeader !== null
            && preg_match('/^Bearer \S+$/', $authorizationHeader) === 1;
    }
}
