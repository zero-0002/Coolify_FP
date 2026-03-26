<?php

namespace App\Http\Middleware;

use App\Models\InstanceSettings;
use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\Url\Url;

class TrustHosts extends Middleware
{
    /**
     * Handle the incoming request.
     *
     * Skip host validation for certain routes:
     * - Terminal auth routes (called by realtime container)
     * - API routes (use token-based authentication, not host validation)
     * - Webhook endpoints (use cryptographic signature validation)
     */
    public function handle(Request $request, $next)
    {
        // Skip host validation for these routes
        if ($request->is(
            'terminal/auth',
            'terminal/auth/ips',
            'api/*',
            'webhooks/*'
        )) {
            return $next($request);
        }

        // Eagerly call hosts() to populate the cache (fixes circular dependency
        // where handle() checked cache before hosts() could populate it via
        // Cache::remember, causing host validation to never activate)
        $this->hosts();

        // Skip host validation if no FQDN is configured (initial setup)
        $fqdnHost = Cache::get('instance_settings_fqdn_host');
        if ($fqdnHost === '' || $fqdnHost === null) {
            return $next($request);
        }

        // Validate the request host against trusted hosts explicitly.
        // We check manually instead of relying on Symfony's lazy getHost() validation,
        // which can be bypassed if getHost() was already called earlier in the pipeline.
        $trustedHosts = array_filter($this->hosts());

        // Collect all hosts to validate: the actual Host header, plus X-Forwarded-Host
        // if present. We must check X-Forwarded-Host here because this middleware runs
        // BEFORE TrustProxies, which would later apply X-Forwarded-Host to the request.
        $hostsToValidate = [strtolower(trim($request->getHost()))];

        $forwardedHost = $request->headers->get('X-Forwarded-Host');
        if ($forwardedHost) {
            // X-Forwarded-Host can be a comma-separated list; validate the first (client-facing) value.
            // Strip port if present (e.g. "coolify.example.com:443" → "coolify.example.com")
            // to match the trusted hosts list which stores hostnames without ports.
            $forwardedHostValue = strtolower(trim(explode(',', $forwardedHost)[0]));
            $forwardedHostValue = preg_replace('/:\d+$/', '', $forwardedHostValue);
            $hostsToValidate[] = $forwardedHostValue;
        }

        foreach ($hostsToValidate as $hostToCheck) {
            if (! $this->isHostTrusted($hostToCheck, $trustedHosts)) {
                return response('Bad Host', 400);
            }
        }

        return $next($request);
    }

    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        $trustedHosts = [];

        // Trust the configured FQDN from InstanceSettings (cached to avoid DB query on every request)
        // Use empty string as sentinel value instead of null so negative results are cached
        $fqdnHost = Cache::remember('instance_settings_fqdn_host', 300, function () {
            try {
                $settings = InstanceSettings::get();
                if ($settings && $settings->fqdn) {
                    $url = Url::fromString($settings->fqdn);
                    $host = $url->getHost();

                    return $host ?: '';
                }
            } catch (\Exception $e) {
                // If instance settings table doesn't exist yet (during installation),
                // return empty string (sentinel) so this result is cached
            }

            return '';
        });

        // Convert sentinel value back to null for consumption
        $fqdnHost = $fqdnHost !== '' ? $fqdnHost : null;

        if ($fqdnHost) {
            $trustedHosts[] = $fqdnHost;
        }

        // Trust the APP_URL host itself (not just subdomains)
        $appUrl = config('app.url');
        if ($appUrl) {
            try {
                $appUrlHost = parse_url($appUrl, PHP_URL_HOST);
                if ($appUrlHost && ! in_array($appUrlHost, $trustedHosts, true)) {
                    $trustedHosts[] = $appUrlHost;
                }
            } catch (\Exception $e) {
                // Ignore parse errors
            }
        }

        // Trust all subdomains of APP_URL as fallback
        $trustedHosts[] = $this->allSubdomainsOfApplicationUrl();

        // Always trust loopback addresses so local access works even when FQDN is configured
        foreach (['localhost', '127.0.0.1', '[::1]'] as $localHost) {
            if (! in_array($localHost, $trustedHosts, true)) {
                $trustedHosts[] = $localHost;
            }
        }

        return array_filter($trustedHosts);
    }

    /**
     * Check if a host matches the trusted hosts list.
     *
     * Regex patterns (from allSubdomainsOfApplicationUrl, starting with ^)
     * are matched with preg_match. Literal hostnames use exact comparison
     * only — they are NOT passed to preg_match, which would treat unescaped
     * dots as wildcards and match unanchored substrings.
     *
     * @param  array<int, string>  $trustedHosts
     */
    protected function isHostTrusted(string $host, array $trustedHosts): bool
    {
        foreach ($trustedHosts as $pattern) {
            if (str_starts_with($pattern, '^')) {
                if (@preg_match('{'.$pattern.'}i', $host)) {
                    return true;
                }
            } elseif ($host === $pattern) {
                return true;
            }
        }

        return false;
    }
}
