<?php

namespace App\Http\Controllers\Api\Portal\Remarks;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalRemarkFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Every "Polish" box in the portal comes through here: the note up, tidied into what that box asks for
 * (a work log, or plain prose). Nothing is filed and nothing is stored -- the answer only fills the box,
 * which the person can still edit.
 */
class RemarksController extends Controller
{
    public function __construct(private readonly PortalRemarkFormatter $formatter) {}

    public function format(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        $validated = $request->validate([
            'context' => ['required', 'string', Rule::in(PortalRemarkFormatter::contexts())],
            'remark' => ['required', 'string', 'max:500'],
            // Only a log carries a day; prose has no date to copy.
            'day' => [
                Rule::requiredIf(fn (): bool => PortalRemarkFormatter::needsDay((string) $request->input('context'))),
                'date_format:Y-m-d',
            ],
        ]);

        $day = isset($validated['day'])
            ? Carbon::createFromFormat('Y-m-d', $validated['day'])->format('F j, Y')
            : null;

        try {
            $remark = $this->formatter->format($validated['context'], $validated['remark'], $day);
        } catch (RuntimeException $error) {
            abort(503, $error->getMessage());
        }

        return response()->json([
            'section' => 'remarks',
            'resource' => 'format',
            'data' => ['remark' => $remark],
        ]);
    }

    /**
     * The same tidy-up for a box that holds several notes at once (an approver's rows on the review
     * panel): one call for up to ten of them, answered in the same order. A slot that could not be
     * tidied comes back null, and its row is left exactly as it was typed.
     */
    public function formatMany(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        $validated = $request->validate([
            'context' => ['required', 'string', Rule::in(PortalRemarkFormatter::batchContexts())],
            'remarks' => ['required', 'array', 'min:1', 'max:10'],
            'remarks.*' => ['required', 'string', 'max:500'],
        ]);

        try {
            $remarks = $this->formatter->formatMany($validated['context'], $validated['remarks']);
        } catch (RuntimeException $error) {
            abort(503, $error->getMessage());
        }

        return response()->json([
            'section' => 'remarks',
            'resource' => 'format-many',
            'data' => ['remarks' => $remarks],
        ]);
    }
}
