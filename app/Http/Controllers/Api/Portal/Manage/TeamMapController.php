<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Support\Portal\PortalTeamMap;
use Illuminate\Http\JsonResponse;

class TeamMapController extends Controller
{
    public function __construct(private readonly PortalTeamMap $map) {}

    public function index(): JsonResponse
    {
        return response()->json($this->map->list());
    }
}
