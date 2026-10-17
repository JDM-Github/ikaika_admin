<?php

namespace App\Http\Controllers\Api\Portal\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalRemarkFormatter;
use App\Support\Portal\PortalSubmittedReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class SubmittedController extends Controller
{
    public function __construct(
        private readonly PortalSubmittedReports $reports,
        private readonly PortalRemarkFormatter $formatter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reports->list($actor, $request));
    }

    // The member's own late reports and where each one's approval stands.
    public function late(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reports->ownLate($actor, $request));
    }

    // The member's own daily reports that waited for review, and the decisions on them.
    public function reviews(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reports->ownReviews($actor, $request));
    }

    public function days(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reports->days($actor, $request));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reports->create($actor, $request), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reports->replace($actor, $id, $request));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        $this->reports->destroy($actor, $id, $request);

        return response()->json(null, 204);
    }

    // The Report remarks card's Polish button: the person's own note, tidied into the house template.
    // Nothing is filed here -- the answer only fills the box the person can still edit.
    public function formatRemarks(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:500'],
            'day' => ['required', 'date_format:Y-m-d'],
        ]);

        $day = Carbon::createFromFormat('Y-m-d', $validated['day'])->format('F j, Y');

        try {
            $remark = $this->formatter->format($validated['remark'], $day);
        } catch (RuntimeException $error) {
            abort(503, $error->getMessage());
        }

        return response()->json([
            'section' => 'reports',
            'resource' => 'remarks',
            'data' => ['remark' => $remark],
        ]);
    }
}
