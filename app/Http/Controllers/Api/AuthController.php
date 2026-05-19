<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly JwtService $jwtService
    )
    {
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('username', $data['username'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->auditLogger->recordAnonymous(
                action: 'login_failed',
                details: sprintf('Failed login attempt for username %s.', $data['username']),
                ipAddress: $request->ip()
            );

            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $payload = $this->jwtService->tokenResponse($user);

        $user->forceFill([
            'api_token_last_used_at' => now(),
        ])->saveQuietly();

        $this->auditLogger->record(
            action: 'login',
            performedBy: $user,
            details: 'User logged in.',
            ipAddress: $request->ip()
        );

        return response()->json($payload);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $user = User::query()->create([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'user',
            'jwt_token_version' => 0,
        ]);

        $this->auditLogger->record(
            action: 'registration',
            performedBy: $user,
            targetUser: $user,
            details: 'User registered.',
            ipAddress: $request->ip()
        );

        return response()->json($this->jwtService->tokenResponse($user), 201);
    }

    public function refresh(Request $request)
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $payload = $this->jwtService->refresh($token);

        if (! $payload) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = User::query()->find($payload['claims']['sub']);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $this->auditLogger->record(
            action: 'token_refreshed',
            performedBy: $user,
            details: 'User refreshed JWT access token.',
            ipAddress: $request->ip()
        );

        return response()->json([
            'user' => $this->jwtService->userPayload($user),
            'token' => $payload['token'],
            'tokenType' => 'Bearer',
            'expiresIn' => $payload['expiresIn'],
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->jwtService->userPayload($request->user()),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'username' => ['sometimes', 'required', 'string', 'max:255', 'unique:users,username,' . $user->id],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
        ]);

        if (array_key_exists('username', $data)) {
            $user->username = $data['username'];
        }

        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
        }

        if (! empty($data['password'])) {
            $user->password = $data['password'];
            $user->jwt_token_version = (int) ($user->jwt_token_version ?? 0) + 1;
        }

        $user->save();

        $this->auditLogger->record(
            action: 'profile_updated',
            performedBy: $user,
            targetUser: $user,
            details: 'Updated own profile.',
            ipAddress: $request->ip()
        );

        if (! empty($data['password'])) {
            $this->auditLogger->record(
                action: 'password_changed',
                performedBy: $user,
                targetUser: $user,
                details: 'Changed own password.',
                ipAddress: $request->ip()
            );
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $this->jwtService->userPayload($user->fresh()),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($request->bearerToken()) {
            $this->jwtService->invalidateCurrentToken($request);
        }

        $this->auditLogger->record(
            action: 'logout',
            performedBy: $user,
            details: 'User logged out.',
            ipAddress: $request->ip()
        );

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
