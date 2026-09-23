<?php

namespace App\Http\Controllers\Api\Portal\Administration;

use App\Http\Controllers\Controller;
use App\Support\Portal\PortalAllActions;
use App\Support\Portal\PortalAllActionsPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AllActionsController extends Controller
{
    public function __construct(private readonly PortalAllActions $actions) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->actions->listAll($request));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(PortalAllActionsPresenter::withObjectPayload($this->actions->showAll($id)));
    }
}
