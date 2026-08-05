<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (request()->wantsJson()) {
        return response()->json([
            'name' => config('app.name', 'Shirin Beauty Atelier'),
            'status' => 'online',
            'installed' => true,
            'api_health' => url('/api/health'),
            'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
        ]);
    }

    return view('welcome');
});

Route::get('/install', function () {
    return view('installer');
});
