<?php

namespace App\Http\Controllers\Admin;

use App\Enum\PermissionEnum;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Admin\ReportRequest;
use App\Services\DashboardService;
use App\Services\DonationReportService;
use Illuminate\Http\JsonResponse;

class DashboardController extends ApiController
{
    public function __construct(private readonly DashboardService $dashboard, private readonly DonationReportService $reports) {}

    public function index(ReportRequest $request): JsonResponse
    {
        return $this->success($this->dashboard->overview($request->validated(), $request->user()->can(PermissionEnum::DONATIONS_VIEW->value)))
            ->header('Cache-Control', 'no-store, private');
    }

    public function donations(ReportRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return $this->success(['context' => $this->reports->context($filters)] + $this->reports->series($filters))
            ->header('Cache-Control', 'no-store, private');
    }
}
