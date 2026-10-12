<?php

namespace App\Support\Workspace;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalRole;
use App\Support\Portal\PortalRules;
use Illuminate\Http\Request;

/**
 * Who may open the workspace, and what it lets them do once they are in.
 *
 * The workspace points at every table in all three databases, so "any signed-in
 * employee" was never the right audience -- the portal is the tool for a member.
 * Each grant is a power (2026-10-01), read once from the session's active rules:
 *
 *   workspace          browse
 *   workspace.private  read private columns
 *   workspace.edit     edit cells
 *   workspace.views    save views everyone sees
 *
 * Admins and executives hold all four by preset and Developer mode holds every rule;
 * a member holds none unless given one (the retired ProjectAdmin role reads as a
 * member). The tier is kept only for the badge's label; it decides nothing.
 */
final class WorkspaceAccess
{
    public const TIER_EXECUTIVE = 'executive';

    public const TIER_ADMIN = 'admin';

    public const TIER_PROJECT_ADMIN = 'project-admin';

    public const TIER_MEMBER = 'member';

    public const RULE = 'workspace';

    public const RULE_PRIVATE = 'workspace.private';

    public const RULE_EDIT = 'workspace.edit';

    public const RULE_VIEWS = 'workspace.views';

    /**
     * @param  array<string, ?string>  $rules
     */
    private function __construct(
        private readonly string $tier,
        private readonly int $actorId,
        private readonly string $actorIdNo,
        private readonly array $rules,
    ) {}

    public static function of(Employee $actor, Request $request): self
    {
        return new self(
            self::tierOf($actor->role, $actor->role_level),
            (int) $actor->id,
            (string) $actor->id_no,
            PortalRules::active($actor, $request),
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
        return array_key_exists(self::RULE, $this->rules);
    }

    /**
     * Government IDs, bank details and home contact details.
     */
    public function canReadPrivate(): bool
    {
        return $this->canBrowse() && array_key_exists(self::RULE_PRIVATE, $this->rules);
    }

    public function canEdit(): bool
    {
        return $this->canBrowse() && array_key_exists(self::RULE_EDIT, $this->rules);
    }

    /**
     * A view everyone in the workspace sees. Anyone may keep a private one.
     */
    public function canShareViews(): bool
    {
        return $this->canBrowse() && array_key_exists(self::RULE_VIEWS, $this->rules);
    }

    /**
     * Stop a member at the door with a reason, rather than an empty rail they cannot
     * explain.
     */
    public function assertCanBrowse(): void
    {
        if (! $this->canBrowse()) {
            abort(403, 'The data workspace needs the Data Workspace power. Your work lives in the portal.');
        }
    }

    public function assertCanEdit(): void
    {
        $this->assertCanBrowse();

        if (! $this->canEdit()) {
            abort(403, 'Editing the databases directly needs the Edit cells power.');
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
