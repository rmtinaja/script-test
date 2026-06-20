<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-to-server auth for the Discord bot. The bot is a machine client
 * (no user session), so it presents a shared secret in the `X-Api-Key`
 * header, compared in constant time against config('saerp_replica.api_key').
 *
 * Fail-closed: if no key is configured, every request is rejected.
 */
class BotApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('saerp_replica.api_key');
        $provided = (string) $request->header('X-Api-Key', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        return $next($request);
    }
}
