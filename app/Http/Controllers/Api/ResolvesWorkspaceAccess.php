<?php

namespace App\Http\Controllers\Api;

use App\Modules\Portal\Models\Employee;
use App\Support\Workspace\WorkspaceAccess;
use Illuminate\Http\Request;

/**
 * The signed-in employee, as a set of workspace grants.
 *
 * Browsing is gated here rather than by the portal.admin middleware, which reports a
 * refusal to the whole admin roster by email -- a mistyped table name is not an
 * incident worth mailing anyone about.
 */
trait ResolvesWorkspaceAccess
{
    protected function access(Request $request): WorkspaceAccess
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        $access = WorkspaceAccess::of($actor);
        $access->assertCanBrowse();

        return $access;
    }
}
