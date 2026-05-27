<?php

namespace App\Actions\Server;

use App\Models\Application;
use App\Models\Server;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\SwarmDocker;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ResourcesCheck
{
    use AsAction;

    public function handle()
    {
        $seconds = config('constants.sentinel.resource_stale_seconds', 300);
        $staleServerIds = $this->staleSentinelServerIds($seconds);

        if ($staleServerIds->isEmpty()) {
            return;
        }

        [$standaloneDockerIds, $swarmDockerIds] = $this->destinationIdsForServers($staleServerIds);

        try {
            Application::where(fn ($query) => $this->scopeDestination($query, $standaloneDockerIds, $swarmDockerIds))
                ->where('status', 'not like', 'exited%')
                ->update(['status' => 'exited']);

            ServiceApplication::whereHas('service', fn ($query) => $query->whereIn('server_id', $staleServerIds))
                ->where('status', 'not like', 'exited%')
                ->update(['status' => 'exited']);

            ServiceDatabase::whereHas('service', fn ($query) => $query->whereIn('server_id', $staleServerIds))
                ->where('status', 'not like', 'exited%')
                ->update(['status' => 'exited']);

            collect([
                StandalonePostgresql::class,
                StandaloneRedis::class,
                StandaloneMongodb::class,
                StandaloneMysql::class,
                StandaloneMariadb::class,
                StandaloneKeydb::class,
                StandaloneDragonfly::class,
                StandaloneClickhouse::class,
            ])->each(function (string $databaseClass) use ($standaloneDockerIds, $swarmDockerIds) {
                $databaseClass::query()
                    ->where(fn ($query) => $this->scopeDestination($query, $standaloneDockerIds, $swarmDockerIds))
                    ->where('status', 'not like', 'exited%')
                    ->update(['status' => 'exited']);
            });
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }

    private function staleSentinelServerIds(int $seconds): Collection
    {
        return Server::query()
            ->whereNotNull('sentinel_updated_at')
            ->where('sentinel_updated_at', '<', now()->subSeconds($seconds))
            ->whereHas('settings', fn ($query) => $query->where('is_sentinel_enabled', true))
            ->pluck('id');
    }

    private function destinationIdsForServers(Collection $serverIds): array
    {
        return [
            StandaloneDocker::whereIn('server_id', $serverIds)->pluck('id'),
            SwarmDocker::whereIn('server_id', $serverIds)->pluck('id'),
        ];
    }

    private function scopeDestination($query, Collection $standaloneDockerIds, Collection $swarmDockerIds): void
    {
        $query->where(function ($query) use ($standaloneDockerIds) {
            $query->where('destination_type', StandaloneDocker::class)
                ->whereIn('destination_id', $standaloneDockerIds);
        })->orWhere(function ($query) use ($swarmDockerIds) {
            $query->where('destination_type', SwarmDocker::class)
                ->whereIn('destination_id', $swarmDockerIds);
        });
    }
}
