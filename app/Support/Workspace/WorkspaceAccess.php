<?php

namespace App\Support\Workspace;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalRole;

/**
 * Who may open the workspace, and what it lets them do once they are in.
 *
 * The workspace points at every table in all three databases, so "any signed-in
 * employee" was never the right audience -- the portal is the tool for a member.
 * Three tiers, each a superset of the one below:
 *
 *   Executive / Admin  browse, read private columns, edit, save shared views
 *   ProjectAdmin       browse, no private columns, no edits
 *   User               no workspace at all
 *
 * A tier is a set of grants rather than a role check scattered through callers, so
 * the page can state what the signed-in employee has instead of failing silently.
 */
final class WorkspaceAccess
{
    public const TIER_EXECUTIVE = 'executive';

    public const TIER_ADMIN = 'admin';

    public const TIER_PROJECT_ADMIN = 'project-admin';

    public const TIER_MEMBER = 'member';

    private function __construct(
        private readonly string $tier,
        private readonly int $actorId,
        private readonly string $actorIdNo,
    ) {}

    public static function of(Employee $actor): self
    {
        return new self(
            self::tierOf($actor->role, $actor->role_level),
            (int) $actor->id,
            (string) $actor->id_no,
        );
    }

    private static function tierOf(?string $role, ?string $roleLevel): string
    {
        if (PortalRole::isExecutive($role, $roleLevel)) {
            return self::TIER_EXECUTIVE;
        }

        if (PortalRole::normalize($role) === PortalRole::normalize(PortalRole::ADMIN)) {
            return self::TIER_ADMIN;
        }

        if (PortalRole::normalize($role) === PortalRole::normalize(PortalRole::PROJECT_ADMIN)) {
            return self::TIER_PROJECT_ADMIN;
        }

        return self::TIER_MEMBER;
    }

    public function tier(): string
    {
        return $this->tier;
    }

    public function actorId(): int
    {
        return $this->actorId;
    }

    public function actorIdNo(): string
    {
        return $this->actorIdNo;
    }

    public function canBrowse(): bool
    {
        return $this->tier !== self::TIER_MEMBER;
    }

    /**
     * Government IDs, bank details and home contact details.
     */
    public function canReadPrivate(): bool
    {
        return $this->tier === self::TIER_EXECUTIVE || $this->tier === self::TIER_ADMIN;
    }

    public function canEdit(): bool
    {
        return $this->canReadPrivate();
    }

    /**
     * A view everyone in the workspace sees. Anyone may keep a private one.
     */
    public function canShareViews(): bool
    {
        return $this->canReadPrivate();
    }

    /**
     * Stop a member at the door with a reason, rather than an empty rail they cannot
     * explain.
     */
    public function assertCanBrowse(): void
    {
        if (! $this->canBrowse()) {
            abort(403, 'The data workspace is for administrators. Your work lives in the portal.');
        }
    }

    public function assertCanEdit(): void
    {
        $this->assertCanBrowse();

        if (! $this->canEdit()) {
            abort(403, 'Editing the databases directly is limited to administrators.');
        }
    }

    /**
     * What the page shows in the account badge, so the boundary is stated up front.
     *
     * @return array{tier: string, label: string, browse: bool, edit: bool, private: bool, share_views: bool}
     */
    public function grants(): array
    {
        return [
            'tier' => $this->tier,
            'label' => match ($this->tier) {
                self::TIER_EXECUTIVE => 'Executive',
                self::TIER_ADMIN => 'Admin',
                self::TIER_PROJECT_ADMIN => 'Project admin',
                default => 'Member',
            },
            'browse' => $this->canBrowse(),
            'edit' => $this->canEdit(),
            'private' => $this->canReadPrivate(),
            'share_views' => $this->canShareViews(),
        ];
    }
}
