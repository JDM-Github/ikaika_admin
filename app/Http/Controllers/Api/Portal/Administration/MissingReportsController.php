<?php

namespace App\Http\Controllers\Api\Portal\Administration;

use App\Http\Controllers\Controller;
use App\Support\Portal\PortalSubmittedReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MissingReportsController extends Controller
{
    public function __construct(private readonly PortalSubmittedReports $reports) {}

    // Every member's filed days, for the screen that works out who has not reported.
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->reports->filedDaysByMember($request));
    }
}
