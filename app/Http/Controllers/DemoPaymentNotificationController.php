<?php

namespace App\Http\Controllers;

use App\Actions\ProcessFeePaymentNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoPaymentNotificationController extends Controller
{
    public function __invoke(Request $request, ProcessFeePaymentNotification $action): JsonResponse
    {
        return response()->json(['received' => true, 'result' => $action->handle($request)]);
    }
}
