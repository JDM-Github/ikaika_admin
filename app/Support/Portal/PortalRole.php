<?php

namespace App\Support\Portal;

/**
 * Portal access ranks. Executive is highest and cannot be assigned or changed here.
 */
final class PortalRole
{
    public const ADMIN = 'Admin';

    public const USER = 'User';

    public const PROJECT_ADMIN = 'ProjectAdmin';

    public const EXECUTIVE_LEVEL = 'Executive';

    /**
     * @return list<string>
     */
    public static function assignableRoles(): array
    {
        return [self::ADMIN, self::USER, self::PROJECT_ADMIN];
    }

    public static function isRemovingAdminRights(?string $currentRole, ?string $currentLevel, string $newRole): bool
    {
        return self::isAdmin($currentRole, $currentLevel)
            && self::normalize($newRole) !== 'admin';
    }

    public static function normalize(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    public static function isExecutive(?string $role, ?string $roleLevel): bool
    {
        return self::normalize($roleLevel) === 'executive'
            || self::normalize($role) === 'executive';
    }

    public static function isAdmin(?string $role, ?string $roleLevel): bool
    {
        if (self::isExecutive($role, $roleLevel)) {
            return true;
        }

        return self::normalize($role) === 'admin';
    }

    public static function canManageUsers(?string $role, ?string $roleLevel): bool
    {
        return self::isAdmin($role, $roleLevel);
    }

    public static function isLocked(?string $role, ?string $roleLevel): bool
    {
        return self::isExecutive($role, $roleLevel);
    }

    /**
     * Why this role change is refused, or null when it may proceed.
     */
    public static function roleChangeBlock(
        string|int $actorId,
        string|int $targetId,
        bool $targetLocked,
        bool $targetIsAdmin,
        bool $demoting,
        int $adminCount,
    ): ?string {
        if ($targetLocked) {
            return 'The executive role cannot be changed.';
        }

        if ($demoting && (string) $actorId === (string) $targetId) {
            return 'You cannot remove your own administrator role.';
        }

        if ($demoting && $targetIsAdmin && $adminCount <= 1) {
            return 'Someone has to keep the keys. Promote another member before removing this one.';
        }

        return null;
    }
}
