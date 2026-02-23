<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) env('API_KEY', '');

        if ($expected !== '') {
            $provided = (string) $request->header('X-API-KEY', '');

            if ($provided === '' || ! hash_equals($expected, $provided)) {
                return response()->json([
                    'message' => 'Unauthorized',
                ], 401);
            }
        }

        return $next($request);
    }
}
