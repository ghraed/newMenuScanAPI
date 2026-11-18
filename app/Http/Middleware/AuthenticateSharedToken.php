<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSharedToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return $this->reject('missing_bearer');
        }

        [$tokenId, $plainToken] = $this->parseToken($bearerToken);

        $token = PersonalAccessToken::query()
            ->with('user.restaurant')
            ->when($tokenId !== null, fn ($query) => $query->whereKey($tokenId))
            ->get()
            ->first(function (PersonalAccessToken $candidate) use ($plainToken): bool {
                return hash_equals($candidate->token, hash('sha256', $plainToken));
            });

        if (! $token) {
            $candidate = $tokenId !== null ? PersonalAccessToken::query()->find($tokenId) : null;

            return $this->reject('invalid_token', 'Unauthenticated.', [
                'token_id' => $tokenId,
                'plain_length' => strlen($plainToken),
                'token_record_found' => (bool) $candidate,
                'database' => $candidate?->getConnection()->getDatabaseName(),
            ]);
        }

        if ($token->tokenable_type !== \App\Models\User::class || ! $token->user) {
            return $this->reject('invalid_token', 'Unauthenticated.', [
                'token_id' => $tokenId,
                'token_record_found' => true,
                'token_type_valid' => $token->tokenable_type === \App\Models\User::class,
                'token_user_found' => (bool) $token->user,
            ]);
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            return $this->reject('expired_token', 'Token expired.');
        }

        $token->forceFill([
            'last_used_at' => now(),
        ])->save();

        $user = $token->user;
        $request->attributes->set('accessToken', $token);
        $request->setUserResolver(static fn () => $user);
        app('auth')->guard()->setUser($user);

        return $next($request);
    }

    private function reject(string $reason, string $message = 'Unauthenticated.', array $context = []): Response
    {
        Log::notice('Scan API authentication rejected', ['reason' => $reason] + $context);

        return response()->json(['message' => $message], 401)
            ->header('X-Scan-Auth-Failure', $reason);
    }

    /**
     * @return array{0: int|null, 1: string}
     */
    private function parseToken(string $bearerToken): array
    {
        if (! str_contains($bearerToken, '|')) {
            return [null, $bearerToken];
        }

        [$id, $plainToken] = explode('|', $bearerToken, 2);

        return [is_numeric($id) ? (int) $id : null, $plainToken];
    }
}
