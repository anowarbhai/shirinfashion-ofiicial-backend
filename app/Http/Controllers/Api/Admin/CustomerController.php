<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::query()
                ->where('role', 'customer')
                ->with([
                    'orders' => fn ($query) => $query->latest('placed_at'),
                ])
                ->withCount(['orders', 'wishlistItems'])
                ->latest()
                ->paginate(20),
        ]);
    }

    public function show(User $customer): JsonResponse
    {
        abort_unless($customer->role === 'customer', 404);

        $customer->load([
            'orders' => fn ($query) => $query->latest('placed_at'),
        ])->loadCount(['orders', 'wishlistItems', 'reviews']);

        return response()->json([
            'data' => $customer,
        ]);
    }

    public function update(Request $request, User $customer): JsonResponse
    {
        abort_unless($customer->role === 'customer', 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:30',
                Rule::unique('users', 'phone')->ignore($customer->id),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($customer->id),
            ],
            'address' => ['nullable', 'string', 'max:500'],
            'marketing_opt_in' => ['boolean'],
        ]);

        $customer->update($validated);

        $customer->load([
            'orders' => fn ($query) => $query->latest('placed_at'),
        ])->loadCount(['orders', 'wishlistItems', 'reviews']);

        return response()->json([
            'message' => 'Customer updated successfully.',
            'data' => $customer,
        ]);
    }
}
