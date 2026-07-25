<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin landing screen. Access is enforced by `permission:dashboard.view`
 * in routes/web.php; every figure is produced by DashboardService, so this
 * controller stays a pure transport layer.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    /**
     * First paint: stat cards, feeds and the default chart window.
     */
    public function index(Request $request): Response
    {
        $range = $this->dashboard->normaliseRange($request->integer('days') ?: null);

        $overview = $this->dashboard->overview();

        return Inertia::render('Admin/Dashboard', [
            'stats' => $overview['stats'],
            'activity' => $overview['activity'],
            'upcoming' => $overview['upcoming'],
            'generatedAt' => $overview['generated_at'],
            'charts' => $this->dashboard->charts($range),
            'range' => $range,
            'ranges' => DashboardService::RANGES,
        ]);
    }

    /**
     * Range switch. Returns just the chart payload as JSON so the page swaps
     * datasets without an Inertia visit or a full re-render.
     */
    public function chart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', Rule::in(DashboardService::RANGES)],
        ]);

        $range = $this->dashboard->normaliseRange(
            isset($validated['days']) ? (int) $validated['days'] : null
        );

        return response()->json($this->dashboard->charts($range));
    }
}
