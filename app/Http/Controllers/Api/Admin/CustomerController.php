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
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->where('role', 'customer')
            ->withCount(['wishlistItems']);

        if ($search = trim((string) $request->input('q', $request->input('search', '')))) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $customers = $query->latest('id')->paginate($perPage);

        $customers->getCollection()->transform(
            fn (User $customer): User => $this->enrichCustomerActivity($customer),
        );

        return response()->json([
            'data' => $customers,
            'summary' => [
                'total' => User::query()->where('role', 'customer')->count(),
                'subscribed' => User::query()
                    ->where('role', 'customer')
                    ->where('marketing_opt_in', true)
                    ->count(),
                'with_orders' => User::query()
                    ->where('role', 'customer')
                    ->whereHas('orders', fn ($query) => $query->where('status', '!=', 'incomplete'))
                    ->count(),
                'active_wishlist' => User::query()
                    ->where('role', 'customer')
                    ->whereHas('wishlistItems')
                    ->count(),
            ],
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

        $hasPassword = ! empty($validated['password']);

        $customer = User::query()->create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'marketing_opt_in' => $validated['marketing_opt_in'] ?? false,
            'password' => $validated['password'] ?? Str::random(16),
            'password_set_at' => $hasPassword ? now() : null,
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

    protected function resolveCustomer(mixed $param): User
    {
        if ($param instanceof User && $param->exists && $param->id) {
            return $param;
        }

        $id = is_numeric($param) ? (int) $param : null;
        if (! $id) {
            $routeParam = request()->route('customer');
            $id = is_numeric($routeParam) ? (int) $routeParam : null;
        }

        return User::query()
            ->where('role', 'customer')
            ->findOrFail($id);
    }

    public function update(Request $request, mixed $customer): JsonResponse
    {
        $targetCustomer = $this->resolveCustomer($customer);

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
                Rule::unique('users', 'email')->ignore($targetCustomer->id),
            ],
            'address' => ['nullable', 'string', 'max:500'],
            'marketing_opt_in' => ['boolean'],
        ]);

        $validated['phone'] = BangladeshPhone::normalizeToLocal($validated['phone']);

        $this->ensureUniqueCustomerPhone($validated['phone'], $targetCustomer->id);

        $targetCustomer->update($validated);

        $targetCustomer->loadCount(['wishlistItems']);
        $this->enrichCustomerActivity($targetCustomer);

        return response()->json([
            'message' => 'Customer updated successfully.',
            'data' => $targetCustomer,
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
        return Order::query()->where('status', '!=', 'incomplete')->where(function ($query) use ($customer): void {
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
