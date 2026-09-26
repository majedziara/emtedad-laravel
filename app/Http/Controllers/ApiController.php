<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class ApiController extends Controller
{
    protected function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message ?? __('api.success'), 'data' => $data], $status);
    }
}
