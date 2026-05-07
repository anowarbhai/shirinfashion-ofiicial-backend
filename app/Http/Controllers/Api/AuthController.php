<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SmsOtp;
use App\Support\BangladeshPhone;
use App\Services\JwtService;
use App\Services\AdminAuditLogger;
use App\Services\SmsOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected JwtService $jwtService,
        protected SmsOtpService $smsOtpService,
        protected AdminAuditLogger $auditLogger,
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $payload['phone'] = BangladeshPhone::normalizeToLocal($payload['phone']);

        if (User::query()->where('phone', $payload['phone'])->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['This phone number is already registered.'],
            ]);
        }

        $user = User::create([
            'name' => $payload['name'],
            'phone' => $payload['phone'],
            'email' => $payload['email'] ?? null,
            'password' => $payload['password'],
            'role' => 'customer',
        ]);

        return response()->json([
            'message' => 'Account created successfully.',
            'data' => [
                'token' => $this->jwtService->issueToken($user),
                'user' => $user,
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $user = $this->attemptCustomer($request);

        if ($this->smsOtpService->isEnabled('customer_login')) {
            if (! $user->phone) {
                return response()->json([
                    'message' => 'This customer account does not have a phone number for OTP verification.',
                ], 422);
            }

            $otp = $this->smsOtpService->issue('customer_login', $user->phone, $user, [
                'name' => $user->name,
            ]);

            return response()->json([
                'message' => 'OTP sent successfully. Please verify to continue.',
                'data' => [
                    'requires_otp' => true,
                    ...$otp,
                ],
            ]);
        }

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'requires_otp' => false,
                'token' => $this->jwtService->issueToken($user),
                'user' => $user,
            ],
        ]);
    }

    public function adminLogin(Request $request): JsonResponse
    {
        $user = $this->attemptAdmin($request);

        if (! $user->isAdmin()) {
            return response()->json([
                'message' => 'This account does not have admin access.',
            ], 403);
        }

        if ($this->smsOtpService->isEnabled('admin_login')) {
            if (! $user->phone) {
                return response()->json([
                    'message' => 'This admin account does not have a phone number for OTP verification.',
                ], 422);
            }

            $otp = $this->smsOtpService->issue('admin_login', $user->phone, $user, [
                'name' => $user->name,
            ]);

            return response()->json([
                'message' => 'Admin OTP sent successfully. Please verify to continue.',
                'data' => [
                    'requires_otp' => true,
                    ...$otp,
                ],
            ]);
        }

        $this->auditLogger->log($request, 'auth.login', "{$user->name} logged in.", $user, [], $user);

        return response()->json([
            'message' => 'Admin login successful.',
            'data' => [
                'requires_otp' => false,
                'token' => $this->jwtService->issueToken($user),
                'user' => $user,
            ],
        ]);
    }

    public function verifyCustomerLoginOtp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'otp_session_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $verification = $this->smsOtpService->verify(
            'customer_login',
            $payload['otp_session_token'],
            $payload['code'],
        );

        $user = $this->resolveOtpUser($payload['otp_session_token'], 'customer_login');

        if (! $user) {
            return response()->json([
                'message' => 'Unable to resolve the customer account for this OTP.',
            ], 422);
        }

        $this->smsOtpService->consumeVerified('customer_login', $payload['otp_session_token'], $user->phone);

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'requires_otp' => false,
                'token' => $this->jwtService->issueToken($user),
                'user' => $user,
            ],
        ]);
    }

    public function verifyAdminLoginOtp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'otp_session_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $this->smsOtpService->verify(
            'admin_login',
            $payload['otp_session_token'],
            $payload['code'],
        );

        $user = $this->resolveOtpUser($payload['otp_session_token'], 'admin_login');

        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'message' => 'Unable to resolve the admin account for this OTP.',
            ], 422);
        }

        $this->smsOtpService->consumeVerified('admin_login', $payload['otp_session_token'], $user->phone);

        $this->auditLogger->log($request, 'auth.login', "{$user->name} logged in.", $user, ['otp' => true], $user);

        return response()->json([
            'message' => 'Admin login successful.',
            'data' => [
                'requires_otp' => false,
                'token' => $this->jwtService->issueToken($user),
                'user' => $user,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->user()?->isAdmin()) {
            $this->auditLogger->log(
                $request,
                'auth.logout',
                "{$request->user()->name} logged out.",
                $request->user(),
                [],
                $request->user(),
            );
        }

        return response()->json([
            'message' => 'JWT logout acknowledged. Discard the token client-side.',
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($request->user()->id),
            ],
            'address' => ['nullable', 'string', 'max:3000'],
            'avatar_url' => ['nullable', 'string', 'max:1000000'],
        ]);

        $payload['phone'] = BangladeshPhone::normalizeToLocal($payload['phone']);

        /** @var User $user */
        $user = $request->user();
        $before = $user->only(['name', 'email', 'phone', 'address', 'avatar_url']);

        if (
            User::query()
                ->where('role', $user->role)
                ->whereIn('phone', $this->phoneLookupVariants($payload['phone']))
                ->whereKeyNot($user->id)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'phone' => ['This phone number is already in use.'],
            ]);
        }

        $user->update($payload);
        $updated = $user->fresh();

        if ($updated->isAdmin()) {
            $this->auditLogger->log(
                $request,
                'account.updated',
                "{$updated->name} updated their admin account.",
                $updated,
                ['before' => $before, 'after' => $updated->only(['name', 'email', 'phone', 'address', 'avatar_url'])],
                $updated,
            );
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => $updated,
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'avatar' => ['required', 'image', 'max:2048'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->deleteStoredAvatar($user->avatar_url);

        $directory = $user->isAdmin() ? 'avatars/admins' : 'avatars/customers';
        $path = $payload['avatar']->store($directory, 'public');

        $user->update([
            'avatar_url' => url(Storage::url($path)),
        ]);

        return response()->json([
            'message' => 'Profile photo uploaded successfully.',
            'data' => $user->fresh(),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($payload['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is incorrect.'],
            ]);
        }

        $user->update([
            'password' => $payload['password'],
        ]);

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }

    protected function attemptCustomer(Request $request): User
    {
        $payload = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $phone = BangladeshPhone::normalizeToLocal($payload['phone']);
        $user = User::query()
            ->where('role', 'customer')
            ->whereIn('phone', $this->phoneLookupVariants($phone))
            ->first();

        if (! $user || ! Hash::check($payload['password'], $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['The provided credentials are invalid.'],
            ]);
        }

        return $user;
    }

    protected function attemptAdmin(Request $request): User
    {
        $payload = $request->validate([
            'identifier' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identifier = trim((string) ($payload['identifier'] ?? $payload['email'] ?? ''));

        if ($identifier === '') {
            throw ValidationException::withMessages([
                'identifier' => ['Please enter your admin email or phone number.'],
            ]);
        }

        $adminQuery = User::query()
            ->where(function ($query): void {
                $query->where('role', 'admin')
                    ->orWhereNotNull('admin_role_id');
            });

        $user = (clone $adminQuery)
            ->where('email', $identifier)
            ->first();

        if (! $user) {
            $normalizedPhone = $this->normalizePhoneForLookup($identifier);

            if ($normalizedPhone) {
                $user = (clone $adminQuery)
                    ->whereIn('phone', $this->phoneLookupVariants($normalizedPhone))
                    ->first();
            }
        }

        if (! $user || ! Hash::check($payload['password'], $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => ['The provided credentials are invalid.'],
            ]);
        }

        return $user;
    }

    protected function deleteStoredAvatar(?string $avatarUrl): void
    {
        if (
            ! $avatarUrl ||
            (
                ! str_contains($avatarUrl, '/storage/avatars/customers/') &&
                ! str_contains($avatarUrl, '/storage/avatars/admins/')
            )
        ) {
            return;
        }

        $path = parse_url($avatarUrl, PHP_URL_PATH);

        if (! is_string($path)) {
            return;
        }

        $storagePath = ltrim(str_replace('/storage/', '', $path), '/');

        if ($storagePath !== '') {
            Storage::disk('public')->delete($storagePath);
        }
    }

    protected function resolveOtpUser(string $sessionToken, string $purpose): ?User
    {
        $otp = SmsOtp::query()
            ->where('session_token', $sessionToken)
            ->where('purpose', $purpose)
            ->first();

        if (! $otp) {
            return null;
        }

        if ($otp->user_id) {
            return User::find($otp->user_id);
        }

        return User::query()->where('phone', $otp->phone)->first();
    }

    protected function normalizePhoneForLookup(string $value): ?string
    {
        try {
            return BangladeshPhone::normalizeToLocal($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Keep admin/customer login spaces independent while still catching old
     * local/international rows that may exist from earlier phone handling.
     *
     * @return array<int, string>
     */
    protected function phoneLookupVariants(string $phone): array
    {
        $local = BangladeshPhone::normalizeToLocal($phone);

        return array_values(array_unique([
            $local,
            '880'.substr($local, 1),
            '+880'.substr($local, 1),
        ]));
    }
}
