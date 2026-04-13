<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::query()
                ->where('role', 'customer')
                ->withCount(['orders', 'wishlistItems'])
                ->latest()
                ->paginate(20),
        ]);
    }
}
