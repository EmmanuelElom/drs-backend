<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'in:admin,user'],
            'all' => ['nullable', 'boolean'],
        ]);

        $query = User::query()
            ->when($data['search'] ?? null, function ($query, string $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($data['role'] ?? null, fn ($query, string $role) => $query->where('role', $role))
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return response()->json([
                'data' => $query->get()->map(fn (User $user) => $this->serializeUser($user))->values(),
            ]);
        }

        $perPage = (int) ($data['per_page'] ?? 10);
        $page = (int) ($data['page'] ?? 1);
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (User $user) => $this->serializeUser($user))
                ->values(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'in:admin,user'],
        ]);

        $user = User::query()->create([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
        ]);

        $this->auditLogger->fromRequest(
            action: 'user_created',
            request: $request,
            targetUser: $user,
            details: sprintf('Created user %s with role %s.', $user->username, $user->role)
        );

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $this->serializeUser($user),
        ], 201);
    }

    public function show(Request $request, User $user)
    {
        $this->authorize('view', $user);

        return response()->json([
            'data' => $this->serializeUser($user),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $isAdmin = $request->user()->role === 'admin';
        $isSelf = $request->user()->id === $user->id;

        $data = $request->validate([
            'username' => ['sometimes', 'required', 'string', 'max:255', 'unique:users,username,' . $user->id],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
            'role' => [$isAdmin && ! $isSelf ? 'sometimes' : 'prohibited', 'in:admin,user'],
        ]);

        $updates = [];

        if (array_key_exists('username', $data)) {
            $updates['username'] = $data['username'];
        }

        if (array_key_exists('email', $data)) {
            $updates['email'] = $data['email'];
        }

        if (array_key_exists('role', $data)) {
            $updates['role'] = $data['role'];
        }

        if (! empty($data['password'])) {
            $updates['password'] = Hash::make($data['password']);
        }

        $user->forceFill($updates)->save();

        $action = $request->user()->id === $user->id ? 'profile_updated' : 'user_updated';
        $details = $request->user()->id === $user->id
            ? sprintf('Updated own profile for %s.', $user->username)
            : sprintf('Updated user %s.', $user->username);

        $this->auditLogger->fromRequest(
            action: $action,
            request: $request,
            targetUser: $user,
            details: $details
        );

        if (! empty($data['password'])) {
            $this->auditLogger->fromRequest(
                action: 'password_changed',
                request: $request,
                targetUser: $user,
                details: $request->user()->id === $user->id
                    ? 'Changed own password.'
                    : sprintf('Changed password for %s.', $user->username)
            );
        }

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $this->serializeUser($user),
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        $this->authorize('delete', $user);

        abort_if($request->user()->id === $user->id, 403, 'Cannot delete your own account.');

        $deletedUsername = $user->username;

        $this->auditLogger->fromRequest(
            action: 'user_deleted',
            request: $request,
            targetUser: $user,
            details: sprintf('Deleted user %s.', $deletedUsername)
        );

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
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
            'updatedAt' => optional($user->updated_at)->toISOString(),
        ];
    }
}
