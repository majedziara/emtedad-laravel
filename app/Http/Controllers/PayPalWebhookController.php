<?php

namespace App\Http\Controllers;

use App\Services\PayPalWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayPalWebhookController extends ApiController
{
    public function __invoke(Request $request, PayPalWebhookService $service): JsonResponse
    {
        $service->receive($request);

        return $this->success(null, __('payments.webhook_received'), 202);
    }
}
