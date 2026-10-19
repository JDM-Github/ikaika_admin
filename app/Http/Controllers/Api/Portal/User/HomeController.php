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
            'method' => ['required', 'string', Rule::in(PortalHomeLocations::PICKED_SOURCES)],
        ]);
        $this->homes->setOwn((int) $actor->getKey(), (float) $data['latitude'], (float) $data['longitude'], $data['method']);
        $this->audit->record($actor, PortalLogAction::PATCH, 'user.home', '{UserName|You} set {UserName|Your} home location', (string) $actor->getKey(), $request);

        return response()->json(['section' => 'user', 'resource' => 'home', 'data' => $this->homes->mine($actor)]);
    }

    // The address at their own pin, for Profile's Use my pin to fill the address from (2026-10-19, user).
    public function address(Request $request): JsonResponse
    {
        $address = $this->homes->addressAtHome($this->actor($request));
        if ($address === null) {
            abort(404, 'We could not read an address at your pin. Move it on the map, or type your address in.');
        }

        return response()->json(['section' => 'user', 'resource' => 'home', 'data' => $address]);
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
