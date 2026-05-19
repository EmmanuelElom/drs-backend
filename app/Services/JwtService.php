<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class JwtService
{
    public function issue(User $user, array $additionalClaims = []): array
    {
        $now = now()->timestamp;
        $expiresIn = $this->accessTtlSeconds();
        $refreshUntil = $now + $this->refreshTtlSeconds();

        $claims = array_merge([
            'iss' => $this->issuer(),
            'aud' => $this->audience(),
            'iat' => $now,
            'nbf' => $now - $this->clockSkew(),
            'exp' => $now + $expiresIn,
            'refresh_until' => $refreshUntil,
            'sub' => (string) $user->getAuthIdentifier(),
            'jti' => (string) Str::uuid(),
            'ver' => (int) ($user->jwt_token_version ?? 0),
        ], $additionalClaims);

        return [
            'token' => $this->encode($claims),
            'claims' => $claims,
            'expiresIn' => $expiresIn,
        ];
    }

    public function refresh(string $token): ?array
    {
        $claims = $this->decode($token, true);

        if (! $claims) {
            return null;
        }

        if (($claims['refresh_until'] ?? 0) < now()->timestamp) {
            return null;
        }

        $user = $this->userFromClaims($claims);

        if (! $user) {
            return null;
        }

        $this->blacklistClaims($claims);

        return $this->issue($user);
    }

    public function resolveUserFromRequest(Request $request): ?User
    {
        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        $claims = $this->decode($token);

        if (! $claims || $this->isBlacklisted($claims['jti'] ?? null)) {
            return null;
        }

        return $this->userFromClaims($claims);
    }

    public function invalidateToken(string $token): bool
    {
        $claims = $this->decode($token, true);

        if (! $claims) {
            return false;
        }

        $this->blacklistClaims($claims);

        return true;
    }

    public function invalidateCurrentToken(Request $request): bool
    {
        $token = $request->bearerToken();

        if (! $token) {
            return false;
        }

        return $this->invalidateToken($token);
    }

    public function decode(string $token, bool $allowExpired = false): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $signature] = $parts;
        $header = $this->jsonDecode($this->base64UrlDecode($encodedHeader));
        $payload = $this->jsonDecode($this->base64UrlDecode($encodedPayload));

        if (! is_array($header) || ! is_array($payload)) {
            return null;
        }

        if (($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->secret(), true)
        );

        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $now = now()->timestamp;

        if (($payload['nbf'] ?? $now) > ($now + $this->clockSkew())) {
            return null;
        }

        if (! $allowExpired && (($payload['exp'] ?? 0) < $now)) {
            return null;
        }

        return $payload;
    }

    public function tokenResponse(User $user): array
    {
        $issued = $this->issue($user);

        return [
            'user' => $this->userPayload($user),
            'token' => $issued['token'],
            'tokenType' => 'Bearer',
            'expiresIn' => $issued['expiresIn'],
        ];
    }

    public function userPayload(User $user): array
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

    private function userFromClaims(array $claims): ?User
    {
        $userId = $claims['sub'] ?? null;

        if (! $userId) {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()->find($userId);

        if (! $user) {
            return null;
        }

        if ((int) ($claims['ver'] ?? -1) !== (int) ($user->jwt_token_version ?? 0)) {
            return null;
        }

        $user->forceFill([
            'api_token_last_used_at' => now(),
        ])->saveQuietly();

        return $user;
    }

    private function blacklistClaims(array $claims): void
    {
        $jti = $claims['jti'] ?? null;
        $exp = (int) ($claims['exp'] ?? now()->timestamp);

        if (! $jti) {
            return;
        }

        $ttl = max(60, $exp - now()->timestamp);
        Cache::store($this->blacklistStore())->put($this->blacklistKey($jti), true, $ttl);
    }

    private function isBlacklisted(?string $jti): bool
    {
        if (! $jti) {
            return true;
        }

        return Cache::store($this->blacklistStore())->has($this->blacklistKey($jti));
    }

    private function blacklistKey(string $jti): string
    {
        return 'jwt:blacklist:' . $jti;
    }

    private function encode(array $claims): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $encodedHeader = $this->base64UrlEncode($this->jsonEncode($header));
        $encodedPayload = $this->base64UrlEncode($this->jsonEncode($claims));
        $signature = $this->base64UrlEncode(
            hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->secret(), true)
        );

        return $encodedHeader . '.' . $encodedPayload . '.' . $signature;
    }

    private function jsonEncode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function jsonDecode(string $value): ?array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }

    private function secret(): string
    {
        $secret = (string) config('jwt.secret', config('app.key', 'change-me'));

        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $secret;
    }

    private function issuer(): string
    {
        return (string) config('jwt.issuer', config('app.url'));
    }

    private function audience(): string
    {
        return (string) config('jwt.audience', config('app.frontend_url'));
    }

    private function accessTtlSeconds(): int
    {
        return max(60, ((int) config('jwt.access_ttl', 60)) * 60);
    }

    private function refreshTtlSeconds(): int
    {
        return max($this->accessTtlSeconds(), ((int) config('jwt.refresh_ttl', 20160)) * 60);
    }

    private function clockSkew(): int
    {
        return max(0, (int) config('jwt.clock_skew', 30));
    }

    private function blacklistStore(): string
    {
        return (string) config('jwt.blacklist_store', config('cache.default'));
    }
}
