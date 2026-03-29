<?php

namespace App\Actions\Service;

use App\Models\ServiceApplication;
use App\Support\ServiceComposeUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpdateServiceApplicationFromApi
{
    public function execute(ServiceApplication $serviceApplication, Request $request, string $teamId): ?JsonResponse
    {
        $forceDomainOverride = $request->boolean('force_domain_override');

        if ($request->has('url')) {
            $urlRaw = $request->input('url');
            if ($urlRaw !== null && ! is_string($urlRaw)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['url' => 'The url must be a string.'],
                ], 422);
            }

            $parsed = ServiceComposeUrl::validateUrlString(
                is_string($urlRaw) ? $urlRaw : null,
                $forceDomainOverride
            );

            if (count($parsed['errors']) > 0) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $parsed['errors'],
                ], 422);
            }

            if ($parsed['normalized'] !== null) {
                $containerUrls = str($parsed['normalized'])
                    ->explode(',')
                    ->map(fn ($url) => str(trim((string) $url))->lower());

                $result = checkIfDomainIsAlreadyUsedViaAPI($containerUrls, $teamId, $serviceApplication->uuid);
                if (isset($result['error'])) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [$result['error']],
                    ], 422);
                }

                if ($result['hasConflicts'] && ! $forceDomainOverride) {
                    return response()->json([
                        'message' => 'Domain conflicts detected. Use force_domain_override=true to proceed.',
                        'conflicts' => $result['conflicts'],
                        'warning' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.',
                    ], 409);
                }
            }

            $serviceApplication->fqdn = $parsed['normalized'];
        }

        if ($request->has('human_name')) {
            $serviceApplication->human_name = $request->input('human_name');
        }

        if ($request->has('description')) {
            $serviceApplication->description = $request->input('description');
        }

        if ($request->has('image')) {
            $serviceApplication->image = $request->input('image');
        }

        if ($request->has('exclude_from_status')) {
            $serviceApplication->exclude_from_status = $request->boolean('exclude_from_status');
        }

        if ($request->has('is_gzip_enabled')) {
            $serviceApplication->is_gzip_enabled = $request->boolean('is_gzip_enabled');
        }

        if ($request->has('is_stripprefix_enabled')) {
            $serviceApplication->is_stripprefix_enabled = $request->boolean('is_stripprefix_enabled');
        }

        if ($request->has('is_log_drain_enabled')) {
            $enabled = $request->boolean('is_log_drain_enabled');
            $server = $serviceApplication->service->destination->server;
            if ($enabled && ! $server->isLogDrainEnabled()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'is_log_drain_enabled' => 'Log drain is not enabled on the server for this service.',
                    ],
                ], 422);
            }
            $serviceApplication->is_log_drain_enabled = $enabled;
        }

        $serviceApplication->save();
        $serviceApplication->refresh();

        updateCompose($serviceApplication);

        return null;
    }
}
