<?php

namespace App\Http\Controllers\Web\Reports;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureReportAccess;
use App\Models\Clinic;
use App\Services\V1\Reports\ClinicPeriodReportService;
use App\Services\V1\Reports\ReportCalendar;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The doctor's report for a day, a week or a month. Read-only: everything it
 * shows comes from ClinicPeriodReportService, and which periods exist from
 * ReportCalendar.
 */
class ReportController extends Controller
{
    public function index(Request $request, ReportCalendar $calendar): RedirectResponse
    {
        $yesterday = $calendar->days($this->clinic($request))[0];

        return redirect()->route('reports.show', ['type' => 'day', 'value' => $yesterday['value']]);
    }

    public function show(
        Request $request,
        string $type,
        string $value,
        ReportCalendar $calendar,
        ClinicPeriodReportService $reports,
    ): View {
        return $this->page($this->clinic($request), $type, $value, $calendar, $reports, preview: false);
    }

    /**
     * Any clinic's report, for a super admin checking it before — or after —
     * switching a clinic on. Not reachable by any clinic account.
     */
    public function preview(
        Request $request,
        Clinic $clinic,
        string $type,
        string $value,
        ReportCalendar $calendar,
        ClinicPeriodReportService $reports,
    ): Response {
        abort_unless($request->user()?->isSuperAdmin() ?? false, 403);

        return EnsureReportAccess::private(response($this->page($clinic, $type, $value, $calendar, $reports, preview: true)));
    }

    private function page(
        Clinic $clinic,
        string $type,
        string $value,
        ReportCalendar $calendar,
        ClinicPeriodReportService $reports,
        bool $preview,
    ): View {
        $period = $calendar->resolve($clinic, $type, $value);

        abort_if($period === null, 404);

        return view('reports.show', [
            'clinic' => $clinic,
            'type' => $type,
            'value' => $value,
            'report' => $reports->for($clinic, $period),
            'days' => $calendar->days($clinic),
            'periods' => $calendar->periods($clinic),
            'preview' => $preview,
            'link' => fn (string $linkType, string $linkValue): string => $preview
                ? route('reports.preview', ['clinic' => $clinic->id, 'type' => $linkType, 'value' => $linkValue])
                : route('reports.show', ['type' => $linkType, 'value' => $linkValue]),
        ]);
    }

    private function clinic(Request $request): Clinic
    {
        return $request->attributes->get('clinic');
    }
}
