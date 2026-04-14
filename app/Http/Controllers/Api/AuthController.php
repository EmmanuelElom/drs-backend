<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('username', $data['username'])->first();

        if (!$user || !\Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        $token = Str::random(80);

        $user->forceFill([
            'api_token_hash' => hash('sha256', $token),
            'api_token_last_used_at' => now(),
        ])->save();

        $this->auditLogger->record(
            action: 'login',
            performedBy: $user,
            details: 'User logged in.',
            ipAddress: $request->ip()
        );

        return response()->json([
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->serializeUser($request->user()),
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
            'user' => $this->serializeUser($user),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        $this->auditLogger->record(
            action: 'logout',
            performedBy: $user,
            details: 'User logged out.',
            ipAddress: $request->ip()
        );

        $user->forceFill([
            'api_token_hash' => null,
            'api_token_last_used_at' => null,
        ])->save();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'createdAt' => optional($user->created_at)->toISOString(),
        ];
    }
}
