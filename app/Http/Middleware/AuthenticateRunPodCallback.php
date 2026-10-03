<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateRunPodCallback
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = trim((string) config('services.runpod.callback_token'));
        $provided = trim((string) $request->header('X-RunPod-Callback-Token', ''));

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $next($request);
    }
}
