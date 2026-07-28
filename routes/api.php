<?php

use App\Http\Controllers\DemoPaymentNotificationController;
use Illuminate\Support\Facades\Route;

Route::post('/payments/demo/notifications', DemoPaymentNotificationController::class)->middleware('throttle:payment-notifications')->name('payments.demo.notify');

// Session-authenticated v1 endpoints are registered in web.php with CSRF protection.
