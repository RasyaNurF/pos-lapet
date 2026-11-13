<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CurrencyRateRequest;
use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ExchangeRateController extends Controller
{
    public function __invoke(CurrencyRateRequest $request, ExchangeRateService $rates): JsonResponse
    {
        try {
            $quote = $rates->quote($request->validated('currency'));

            return response()->json([...$quote, 'sample_amount' => 1234567 * $quote['rate']]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }
}
