<?php

namespace App\Http\Controllers\Api\Portal\User;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAudit;
use App\Support\Portal\PortalHomeLocations;
use App\Support\Portal\PortalLogAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HomeController extends Controller
{
    public function __construct(
        private readonly PortalHomeLocations $homes,
        private readonly PortalAudit $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['section' => 'user', 'resource' => 'home', 'data' => $this->homes->mine($this->actor($request))]);
    }

    // The person's own home, by GPS or a pin on the map; the coordinates are logged only as "home set".
    public function update(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'method' => ['required', 'string', Rule::in(PortalHomeLocations::OWN_SOURCES)],
        ]);
        $this->homes->setOwn((int) $actor->getKey(), (float) $data['latitude'], (float) $data['longitude'], $data['method']);
        $this->audit->record($actor, PortalLogAction::PATCH, 'user.home', '{UserName|You} set {UserName|your} home location', (string) $actor->getKey(), $request);

        return response()->json(['section' => 'user', 'resource' => 'home', 'data' => $this->homes->mine($actor)]);
    }

    private function actor(Request $request): Employee
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee || $request->attributes->has('portalShare')) {
            abort(403, 'Only a signed-in employee has a home location.');
        }

        return $actor;
    }
}
