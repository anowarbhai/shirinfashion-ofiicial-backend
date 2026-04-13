<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateWithJwt
{
    public function __construct(
        protected JwtService $jwtService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'message' => 'Authentication token is required.',
            ], 401);
        }

        try {
            $payload = $this->jwtService->decode($token);
            $user = User::findOrFail($payload->sub);
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('auth_user', $user);
        } catch (Throwable) {
            return response()->json([
                'message' => 'Authentication token is invalid or expired.',
            ], 401);
        }

        return $next($request);
    }
}
