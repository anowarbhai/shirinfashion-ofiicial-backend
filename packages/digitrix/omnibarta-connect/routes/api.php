<?php

use Digitrix\OmniBarta\Http\Controllers\OrderStatusController;
use Illuminate\Support\Facades\Route;

Route::post('/api/omnibarta/orders/{order}/status', OrderStatusController::class)
    ->name('omnibarta.orders.status');
