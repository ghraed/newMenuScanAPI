<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSharedToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        [$tokenId, $plainToken] = $this->parseToken($bearerToken);

        $token = PersonalAccessToken::query()
            ->with('user.restaurant')
            ->when($tokenId !== null, fn ($query) => $query->whereKey($tokenId))
            ->get()
            ->first(function (PersonalAccessToken $candidate) use ($plainToken): bool {
                return hash_equals($candidate->token, hash('sha256', $plainToken));
            });

        if (! $token || $token->tokenable_type !== \App\Models\User::class || ! $token->user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            return response()->json([
                'message' => 'Token expired.',
            ], 401);
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
