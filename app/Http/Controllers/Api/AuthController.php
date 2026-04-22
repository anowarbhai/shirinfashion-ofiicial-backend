<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SmsOtp;
use App\Services\JwtService;
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

    public function logout(): JsonResponse
    {
        return response()->json([
            'message' => 'JWT logout acknowledged. Discard the token client-side.',
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:30',
                Rule::unique('users', 'phone')->ignore($request->user()->id),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($request->user()->id),
            ],
            'address' => ['nullable', 'string', 'max:3000'],
            'avatar_url' => ['nullable', 'string', 'max:1000000'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->update($payload);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => $user->fresh(),
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

        $user = User::where('phone', $payload['phone'])->first();

        if (! $user || $user->role !== 'customer' || ! Hash::check($payload['password'], $user->password)) {
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

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

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
}
