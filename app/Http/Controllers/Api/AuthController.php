<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SmsOtp;
use App\Support\BangladeshPhone;
use App\Services\JwtService;
use App\Services\AdminAuditLogger;
use App\Services\AdminSettingsService;
use App\Services\SmsOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        protected JwtService $jwtService,
        protected SmsOtpService $smsOtpService,
        protected AdminAuditLogger $auditLogger,
        protected AdminSettingsService $settings,
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'otp_session_token' => ['nullable', 'string'],
            'otp_code' => ['nullable', 'string', 'size:6'],
        ]);

        $payload['phone'] = BangladeshPhone::normalizeToLocal($payload['phone']);

        if ($this->customerPhoneExists($payload['phone'])) {
            throw ValidationException::withMessages([
                'phone' => ['This phone number is already registered.'],
            ]);
        }

        if ($this->smsOtpService->isEnabled('customer_register')) {
            if (empty($payload['otp_session_token']) || empty($payload['otp_code'])) {
                $otp = $this->smsOtpService->issue('customer_register', $payload['phone'], null, [
                    'name' => $payload['name'],
                ]);

                return response()->json([
                    'message' => 'OTP sent successfully. Please verify your phone number.',
                    'data' => [
                        'requires_otp' => true,
                        ...$otp,
                    ],
                ]);
            }

            $this->verifyAndConsumeOtp(
                'customer_register',
                $payload['otp_session_token'],
                $payload['otp_code'],
                $payload['phone'],
            );
        }

        $user = User::create([
            'name' => $payload['name'],
            'phone' => $payload['phone'],
            'email' => $payload['email'] ?? null,
            'password' => $payload['password'],
            'password_set_at' => now(),
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

    public function googleAuth(Request $request): JsonResponse
    {
        $this->ensureGoogleAuthEnabled();

        $payload = $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        $profile = $this->verifyGoogleIdToken($payload['id_token']);
        $user = $this->findGoogleCustomer($profile);

        if ($user && $user->phone) {
            $this->attachGoogleProfile($user, $profile);

            return response()->json([
                'message' => 'Google login successful.',
                'data' => [
                    'requires_phone' => false,
                    'token' => $this->jwtService->issueToken($user->fresh()),
                    'user' => $user->fresh(),
                ],
            ]);
        }

        return response()->json([
            'message' => 'Add your phone number to finish Google sign in.',
            'data' => [
                'requires_phone' => true,
                'google_completion_token' => $this->makeGoogleCompletionToken($profile),
                'profile' => [
                    'name' => $profile['name'],
                    'email' => $profile['email'],
                    'picture' => $profile['picture'] ?? null,
                ],
            ],
        ]);
    }

    public function completeGooglePhone(Request $request): JsonResponse
    {
        $this->ensureGoogleAuthEnabled();

        $payload = $request->validate([
            'google_completion_token' => ['required', 'string'],
            'phone' => ['required', 'string', 'max:30'],
            'otp_session_token' => ['nullable', 'string'],
            'otp_code' => ['nullable', 'string', 'size:6'],
        ]);

        $profile = $this->readGoogleCompletionToken($payload['google_completion_token']);
        $phone = BangladeshPhone::normalizeToLocal($payload['phone']);
        $user = $this->findGoogleCustomer($profile);

        if ($user && $user->phone && ! in_array($user->phone, $this->phoneLookupVariants($phone), true)) {
            throw ValidationException::withMessages([
                'phone' => ['This Google account is already connected to another phone number.'],
            ]);
        }

        if ($this->customerPhoneExists($phone, $user?->id)) {
            throw ValidationException::withMessages([
                'phone' => ['This phone number is already registered.'],
            ]);
        }

        if ($this->smsOtpService->isEnabled('customer_register')) {
            if (empty($payload['otp_session_token']) || empty($payload['otp_code'])) {
                $otp = $this->smsOtpService->issue('customer_register', $phone, $user, [
                    'name' => $profile['name'],
                ]);

                return response()->json([
                    'message' => 'OTP sent successfully. Please verify your phone number.',
                    'data' => [
                        'requires_otp' => true,
                        'requires_phone' => true,
                        'google_completion_token' => $payload['google_completion_token'],
                        ...$otp,
                    ],
                ]);
            }

            $this->verifyAndConsumeOtp(
                'customer_register',
                $payload['otp_session_token'],
                $payload['otp_code'],
                $phone,
            );
        }

        $user = $user ?: User::create([
            'name' => $profile['name'],
            'email' => $profile['email'],
            'google_id' => $profile['google_id'],
            'phone' => $phone,
            'avatar_url' => $profile['picture'] ?? null,
            'email_verified_at' => now(),
            'password' => Str::random(48),
            'role' => 'customer',
        ]);

        $user->forceFill([
            'name' => $user->name ?: $profile['name'],
            'email' => $user->email ?: $profile['email'],
            'google_id' => $profile['google_id'],
            'phone' => $phone,
            'avatar_url' => $user->avatar_url ?: ($profile['picture'] ?? null),
            'email_verified_at' => $user->email_verified_at ?: now(),
        ])->save();

        return response()->json([
            'message' => 'Google login successful.',
            'data' => [
                'requires_phone' => false,
                'requires_otp' => false,
                'token' => $this->jwtService->issueToken($user->fresh()),
                'user' => $user->fresh(),
            ],
        ]);
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
                'user' => $this->adminUserPayload($user),
            ],
        ]);
    }

    public function adminGoogleAuth(Request $request): JsonResponse
    {
        $this->ensureGoogleAuthEnabled();

        $payload = $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        $profile = $this->verifyGoogleIdToken($payload['id_token']);
        $user = $this->findGoogleAdmin($profile);
        $this->attachGoogleProfile($user, $profile);
        $user = $user->fresh();

        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'message' => 'This Google account does not have admin access.',
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
                'google' => true,
            ]);

            return response()->json([
                'message' => 'Admin OTP sent successfully. Please verify to continue.',
                'data' => [
                    'requires_otp' => true,
                    ...$otp,
                ],
            ]);
        }

        $this->auditLogger->log($request, 'auth.login', "{$user->name} logged in with Google.", $user, ['google' => true], $user);

        return response()->json([
            'message' => 'Admin Google login successful.',
            'data' => [
                'requires_otp' => false,
                'token' => $this->jwtService->issueToken($user),
                'user' => $this->adminUserPayload($user),
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
                'user' => $this->adminUserPayload($user),
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
        if ($request->user()?->isAdmin()) {
            return response()->json([
                'data' => $this->adminUserPayload($request->user()),
            ]);
        }

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

        try {
            $this->deleteStoredAvatar($user->getRawOriginal('avatar_url'));

            $directory = $user->isAdmin() ? 'avatars/admins' : 'avatars/customers';
            $disk = (string) config('filesystems.media', 'public');
            $file = $payload['avatar'];
            $filename = Str::uuid()->toString().'-'.preg_replace('/[^A-Za-z0-9.\-_]/', '-', $file->getClientOriginalName());
            $path = $directory.'/'.$filename;
            $stream = fopen($file->getRealPath(), 'rb');

            $stored = $stream !== false && Storage::disk($disk)->put($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $stored) {
                return response()->json([
                    'message' => 'Unable to store profile photo. Please check storage disk permissions or S3 credentials.',
                ], 422);
            }

            $avatarUrl = Storage::disk($disk)->url($path);

            $user->update([
                'avatar_url' => preg_match('#^https?://#i', $avatarUrl) === 1 ? $avatarUrl : url($avatarUrl),
            ]);
        } catch (Throwable $exception) {
            Log::error('Profile avatar upload failed.', [
                'user_id' => $user->id,
                'disk' => config('filesystems.media', 'public'),
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to store profile photo. Please check storage disk permissions or S3 credentials.',
            ], 500);
        }

        return response()->json([
            'message' => 'Profile photo uploaded successfully.',
            'data' => $user->fresh(),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hasPassword = (bool) $user->has_password;

        $payload = $request->validate([
            'current_password' => [$hasPassword ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($hasPassword && ! Hash::check($payload['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is incorrect.'],
            ]);
        }

        $user->update([
            'password' => $payload['password'],
            'password_set_at' => now(),
        ]);

        return response()->json([
            'message' => $hasPassword ? 'Password updated successfully.' : 'Password set successfully.',
            'data' => $user->fresh(),
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

    /**
     * @return array<string, mixed>
     */
    protected function adminUserPayload(User $user): array
    {
        $role = $user->adminRole()->select('id', 'name', 'slug', 'is_active')->first();
        $permissionSlugs = $user->admin_role_id === null && $user->role === 'admin'
            ? ['system.everything']
            : $user->adminPermissionSlugs()->values()->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'address' => $user->address,
            'role' => $user->role,
            'admin_role_id' => $user->admin_role_id,
            'admin_role' => $role,
            'status' => $user->status,
            'avatar_url' => $user->avatar_url,
            'permission_slugs' => $permissionSlugs,
        ];
    }

    protected function deleteStoredAvatar(?string $avatarUrl): void
    {
        if (! $avatarUrl) {
            return;
        }

        $path = preg_match('#^https?://#i', $avatarUrl) === 1
            ? parse_url($avatarUrl, PHP_URL_PATH)
            : $avatarUrl;

        if (! is_string($path)) {
            return;
        }

        $storagePath = str_contains($path, '/storage/')
            ? ltrim(substr($path, strpos($path, '/storage/') + 9), '/')
            : ltrim($path, '/');

        if (
            ! str_starts_with($storagePath, 'avatars/customers/')
            && ! str_starts_with($storagePath, 'avatars/admins/')
        ) {
            return;
        }

        $disks = array_unique(array_filter([
            'public',
            (string) config('filesystems.media', 'public'),
            's3',
        ]));

        foreach ($disks as $disk) {
            if (array_key_exists($disk, config('filesystems.disks', []))) {
                Storage::disk($disk)->delete($storagePath);
            }
        }
    }

    /**
     * @return array{google_id: string, email: string, name: string, picture?: string|null}
     */
    protected function verifyGoogleIdToken(string $idToken): array
    {
        $clientId = $this->googleClientId();

        if ($clientId === '') {
            throw ValidationException::withMessages([
                'id_token' => ['Google login is not configured.'],
            ]);
        }

        $response = Http::timeout(8)->acceptJson()->get(
            'https://oauth2.googleapis.com/tokeninfo',
            ['id_token' => $idToken],
        );

        if (! $response->ok()) {
            throw ValidationException::withMessages([
                'id_token' => ['Google sign in could not be verified.'],
            ]);
        }

        $data = $response->json();

        if (! in_array(($data['aud'] ?? null), $this->googleAllowedClientIds(), true) || empty($data['sub']) || empty($data['email'])) {
            throw ValidationException::withMessages([
                'id_token' => ['Google sign in token is not valid for this website.'],
            ]);
        }

        if (($data['email_verified'] ?? 'false') !== 'true' && ($data['email_verified'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'id_token' => ['Please verify your Google email before signing in.'],
            ]);
        }

        return [
            'google_id' => (string) $data['sub'],
            'email' => Str::lower((string) $data['email']),
            'name' => trim((string) ($data['name'] ?? $data['email'])),
            'picture' => isset($data['picture']) ? (string) $data['picture'] : null,
        ];
    }

    protected function ensureGoogleAuthEnabled(): void
    {
        $settings = $this->settings->getGroup('customer_auth');

        if (! (bool) ($settings['google_login_enabled'] ?? false) || $this->googleClientId() === '') {
            throw ValidationException::withMessages([
                'id_token' => ['Google login is disabled.'],
            ]);
        }
    }

    protected function googleClientId(): string
    {
        $settings = $this->settings->getGroup('customer_auth');

        return trim((string) ($settings['google_client_id'] ?? ''));
    }

    /**
     * @return array<int, string>
     */
    protected function googleAllowedClientIds(): array
    {
        $settings = $this->settings->getGroup('customer_auth');

        return array_values(array_unique(array_filter([
            trim((string) ($settings['google_client_id'] ?? '')),
            trim((string) ($settings['google_android_client_id'] ?? '')),
        ])));
    }

    /**
     * @param  array{google_id: string, email: string, name: string, picture?: string|null}  $profile
     */
    protected function findGoogleCustomer(array $profile): ?User
    {
        $user = User::query()
            ->where('google_id', $profile['google_id'])
            ->orWhere('email', $profile['email'])
            ->first();

        if (! $user) {
            return null;
        }

        if ($user->role !== 'customer') {
            throw ValidationException::withMessages([
                'id_token' => ['This Google account is already used for an admin account.'],
            ]);
        }

        return $user;
    }

    /**
     * @param  array{google_id: string, email: string, name: string, picture?: string|null}  $profile
     */
    protected function findGoogleAdmin(array $profile): User
    {
        $user = User::query()
            ->where(function ($query) use ($profile): void {
                $query->where('google_id', $profile['google_id'])
                    ->orWhere('email', $profile['email']);
            })
            ->where(function ($query): void {
                $query->where('role', 'admin')
                    ->orWhereNotNull('admin_role_id');
            })
            ->first();

        if (! $user || ! $user->isAdmin()) {
            throw ValidationException::withMessages([
                'id_token' => ['This Google account is not connected to an active admin account.'],
            ]);
        }

        return $user;
    }

    /**
     * @param  array{google_id: string, email: string, name: string, picture?: string|null}  $profile
     */
    protected function attachGoogleProfile(User $user, array $profile): void
    {
        $user->forceFill([
            'google_id' => $user->google_id ?: $profile['google_id'],
            'email' => $user->email ?: $profile['email'],
            'avatar_url' => $user->avatar_url ?: ($profile['picture'] ?? null),
            'email_verified_at' => $user->email_verified_at ?: now(),
        ])->save();
    }

    /**
     * @param  array{google_id: string, email: string, name: string, picture?: string|null}  $profile
     */
    protected function makeGoogleCompletionToken(array $profile): string
    {
        return Crypt::encryptString(json_encode([
            ...$profile,
            'expires_at' => now()->addMinutes(15)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{google_id: string, email: string, name: string, picture?: string|null}
     */
    protected function readGoogleCompletionToken(string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'google_completion_token' => ['Google sign in session has expired. Please try again.'],
            ]);
        }

        if (! is_array($payload) || ($payload['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages([
                'google_completion_token' => ['Google sign in session has expired. Please try again.'],
            ]);
        }

        if (empty($payload['google_id']) || empty($payload['email']) || empty($payload['name'])) {
            throw ValidationException::withMessages([
                'google_completion_token' => ['Google sign in session is invalid. Please try again.'],
            ]);
        }

        return [
            'google_id' => (string) $payload['google_id'],
            'email' => Str::lower((string) $payload['email']),
            'name' => (string) $payload['name'],
            'picture' => isset($payload['picture']) ? (string) $payload['picture'] : null,
        ];
    }

    protected function verifyAndConsumeOtp(string $purpose, string $sessionToken, string $code, string $phone): void
    {
        try {
            $this->smsOtpService->verify($purpose, $sessionToken, $code, $phone);
            $this->smsOtpService->consumeVerified($purpose, $sessionToken, $phone);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'otp_code' => [$exception->getMessage()],
            ]);
        }
    }

    protected function customerPhoneExists(string $phone, ?int $ignoreUserId = null): bool
    {
        return User::query()
            ->where('role', 'customer')
            ->whereIn('phone', $this->phoneLookupVariants($phone))
            ->when($ignoreUserId, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->exists();
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
