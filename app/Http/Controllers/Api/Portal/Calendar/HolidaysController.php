<?php

namespace App\Http\Controllers\Api\Portal\Calendar;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalHolidays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidaysController extends Controller
{
    public function __construct(private readonly PortalHolidays $holidays) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->holidays->list($request));
    }
}
