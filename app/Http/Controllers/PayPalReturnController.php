<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class PayPalReturnController extends ApiController
{
    public function returned(): JsonResponse
    {
        // A browser return is not proof of payment and never changes a donation.
        return $this->success(['payment_confirmed' => false], __('payments.return_received'))->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function cancelled(): JsonResponse
    {
        // The browser can revisit this URL even after a successful capture.
        return $this->success(['payment_confirmed' => false], __('payments.cancel_received'))->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
