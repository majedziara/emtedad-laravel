<?php

namespace App\Http\Controllers;

use App\Http\Requests\Donations\DonationIndexRequest;
use App\Http\Requests\Donations\StoreDonationRequest;
use App\Http\Resources\DonationResource;
use App\Http\Resources\PaginatedResource;
use App\Models\Donation;
use App\Services\DonationService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DonationController extends ApiController
{
    public function __construct(private readonly DonationService $donations, private readonly PaymentService $payments) {}

    public function store(StoreDonationRequest $request): JsonResponse
    {
        $payment = $this->donations->create($request->validated(), $request->user());
        $this->payments->createOrder($payment);

        return $this->donation($payment->donation->fresh(['payments', 'humanitarianCase']));
    }

    public function storeGuest(StoreDonationRequest $request): JsonResponse
    {
        $payment = $this->donations->create($request->validated());
        $this->payments->createOrder($payment);

        return $this->donation($payment->donation->fresh(['payments', 'humanitarianCase']));
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        return $this->donation($this->donations->owned($publicId, $request->user()));
    }

    public function showGuest(Request $request, string $publicId): JsonResponse
    {
        return $this->donation($this->donations->guest($publicId, $request->header('X-Donation-Token')));
    }

    public function capture(Request $request, string $publicId): JsonResponse
    {
        return $this->captureDonation($this->donations->owned($publicId, $request->user()));
    }

    public function captureGuest(Request $request, string $publicId): JsonResponse
    {
        return $this->captureDonation($this->donations->guest($publicId, $request->header('X-Donation-Token')));
    }

    public function index(DonationIndexRequest $request): JsonResponse
    {
        return $this->success(new PaginatedResource($this->donations->index($request->validated(), $request->user()), DonationResource::class))->header('Cache-Control', 'no-store, private');
    }

    public function adminIndex(DonationIndexRequest $request): JsonResponse
    {
        return $this->success(new PaginatedResource($this->donations->index($request->validated()), DonationResource::class))->header('Cache-Control', 'no-store, private');
    }

    public function adminShow(string $publicId): JsonResponse
    {
        return $this->donation(Donation::with(['payments', 'humanitarianCase'])->where('public_id', $publicId)->firstOrFail());
    }

    private function captureDonation(Donation $donation): JsonResponse
    {
        $payment = $donation->payments()->where('provider', 'paypal')->whereNotNull('request_fingerprint')->firstOrFail();
        $this->payments->reconcile($payment, captureApproved: true);

        return $this->donation($donation->fresh(['payments', 'humanitarianCase']));
    }

    private function donation(Donation $donation): JsonResponse
    {
        return $this->success(new DonationResource($donation))->header('Cache-Control', 'no-store, private');
    }
}
