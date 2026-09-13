<?php

namespace App\Http\Controllers\Api\Portal\Administration;

use App\Http\Controllers\Controller;
use App\Support\Portal\PortalUserLogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AllLogsController extends Controller
{
    public function __construct(private readonly PortalUserLogs $logs) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->logs->listAll($request));
    }
}
