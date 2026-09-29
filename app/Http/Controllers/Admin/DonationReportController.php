<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Admin\ReportRequest;
use App\Http\Resources\DonationReportResource;
use App\Http\Resources\PaginatedResource;
use App\Services\DonationReportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DonationReportController extends ApiController
{
    public function __construct(private readonly DonationReportService $reports) {}

    public function index(ReportRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return $this->success([
            'context' => $this->reports->context($filters),
            'summary' => $this->reports->summary($filters),
            'donations' => new PaginatedResource($this->reports->donations($filters), DonationReportResource::class),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function cases(ReportRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $cases = $this->reports->cases($filters);

        return $this->success([
            'context' => $this->reports->context($filters),
            'items' => $cases->items(),
            'pagination' => [
                'current_page' => $cases->currentPage(),
                'last_page' => $cases->lastPage(),
                'per_page' => $cases->perPage(),
                'total' => $cases->total(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function export(ReportRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $rows = $this->reports->exportRows($filters);
        $filename = 'emtedad-donations-' . $filters['environment'] . '-' . $filters['date_from'] . '-' . $filters['date_to'] . '.csv';

        return response()->streamDownload(fn() => $this->reports->writeCsv($rows, $filters), $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
