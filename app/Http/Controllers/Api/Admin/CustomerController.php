<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use App\Support\BangladeshPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(): JsonResponse
    {
        $customers = User::query()
            ->where('role', 'customer')
            ->withCount(['wishlistItems'])
            ->latest()
            ->paginate(20);

        $customers->getCollection()->transform(
            fn (User $customer): User => $this->enrichCustomerActivity($customer),
        );

        return response()->json([
            'data' => $customers,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'address' => ['nullable', 'string', 'max:500'],
            'marketing_opt_in' => ['boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $validated['phone'] = BangladeshPhone::normalizeToLocal($validated['phone']);

        $this->ensureUniqueCustomerPhone($validated['phone']);

        $customer = User::query()->create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'marketing_opt_in' => $validated['marketing_opt_in'] ?? false,
            'password' => $validated['password'] ?? Str::random(16),
            'role' => 'customer',
            'status' => 'active',
        ]);

        $customer->loadCount(['wishlistItems']);
        $this->enrichCustomerActivity($customer);

        return response()->json([
            'message' => 'Customer created successfully.',
            'data' => $customer,
        ], 201);
    }

    public function show(User $customer): JsonResponse
    {
        abort_unless($customer->role === 'customer', 404);

        $customer->loadCount(['wishlistItems']);
        $this->enrichCustomerActivity($customer);

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

        $validated['phone'] = BangladeshPhone::normalizeToLocal($validated['phone']);

        $this->ensureUniqueCustomerPhone($validated['phone'], $customer->id);

        $customer->update($validated);

        $customer->loadCount(['wishlistItems']);
        $this->enrichCustomerActivity($customer);

        return response()->json([
            'message' => 'Customer updated successfully.',
            'data' => $customer,
        ]);
    }

    public function destroy(User $customer): JsonResponse
    {
        abort_unless($customer->role === 'customer', 404);

        $customer->delete();

        return response()->json([
            'message' => 'Customer deleted successfully.',
        ]);
    }

    protected function enrichCustomerActivity(User $customer): User
    {
        $orderQuery = $this->customerOrderQuery($customer);
        $orders = $this->customerOrderQuery($customer)
            ->latest('placed_at')
            ->limit(5)
            ->get();

        $customer->setAttribute('orders_count', (clone $orderQuery)->count());
        $customer->setAttribute('total_spent', (float) (clone $orderQuery)->sum('grand_total'));
        $customer->setAttribute('reviews_count', $this->customerReviewQuery($customer)->count());
        $customer->setRelation('orders', $orders);

        return $customer;
    }

    protected function customerOrderQuery(User $customer)
    {
        return Order::query()->where(function ($query) use ($customer): void {
            $query->where('user_id', $customer->id);

            $phones = $this->phoneVariants($customer->phone);

            if ($phones !== []) {
                $query->orWhereIn('phone', $phones);
            }

            if ($customer->email) {
                $query->orWhere('email', $customer->email);
            }
        });
    }

    protected function customerReviewQuery(User $customer)
    {
        return Review::query()->where(function ($query) use ($customer): void {
            $query->where('user_id', $customer->id);

            $phones = $this->phoneVariants($customer->phone);

            if ($phones !== []) {
                $query->orWhereIn('author_phone', $phones);
            }

            if ($customer->email) {
                $query->orWhere('author_email', $customer->email);
            }
        });
    }

    protected function phoneVariants(?string $phone): array
    {
        if (! $phone) {
            return [];
        }

        $variants = [$phone];

        try {
            $local = BangladeshPhone::normalizeToLocal($phone);
            $international = BangladeshPhone::normalizeToInternational($phone);
            $variants[] = $local;
            $variants[] = $international;
            $variants[] = '+'.$international;
        } catch (\Throwable) {
            $digits = preg_replace('/\D+/', '', $phone);

            if ($digits) {
                $variants[] = $digits;
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    protected function ensureUniqueCustomerPhone(string $phone, ?int $ignoreUserId = null): void
    {
        $query = User::query()
            ->where('role', 'customer')
            ->whereIn('phone', $this->phoneVariants($phone));

        if ($ignoreUserId) {
            $query->whereKeyNot($ignoreUserId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['This phone number is already in use.'],
            ]);
        }
    }
}
