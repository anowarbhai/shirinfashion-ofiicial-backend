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
        $orders = $this->customerOrderQuery($customer)
            ->latest('placed_at')
            ->limit(5)
            ->get();

        $customer->setAttribute('orders_count', $this->customerOrderQuery($customer)->count());
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
}
