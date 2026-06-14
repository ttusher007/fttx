<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates external API consumers via a key + secret pair. Credentials may
 * be sent as `X-Api-Key` / `X-Api-Secret` headers, or combined in a Bearer
 * token shaped "key:secret". An optional ability argument scopes the endpoint.
 */
class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        [$key, $secret] = $this->credentials($request);

        if (! $key || ! $secret) {
            return $this->deny('Missing API credentials.', 401);
        }

        $client = ApiClient::where('key', $key)->first();

        if (! $client || ! $client->is_active || ! $client->verifySecret($secret)) {
            return $this->deny('Invalid API credentials.', 401);
        }

        if ($ability && ! $client->hasAbility($ability)) {
            return $this->deny("This key is not authorised for '{$ability}'.", 403);
        }

        $client->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ])->saveQuietly();

        // Expose the client to downstream (rate limiter, controllers).
        $request->attributes->set('api_client', $client);

        return $next($request);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function credentials(Request $request): array
    {
        $key = $request->header('X-Api-Key');
        $secret = $request->header('X-Api-Secret');

        if (! $key && $bearer = $request->bearerToken()) {
            [$key, $secret] = array_pad(explode(':', $bearer, 2), 2, null);
        }

        return [$key, $secret];
    }

    private function deny(string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
