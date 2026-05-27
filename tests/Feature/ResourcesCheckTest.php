<?php

use App\Actions\Server\ResourcesCheck;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('does not mark resources exited when sentinel is still reporting for the server', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 12, 0, 0, 'UTC'));
    config(['constants.sentinel.resource_stale_seconds' => 300]);

    $application = resourcesCheckApplication([
        'last_online_at' => now()->subHour(),
        'status' => 'running:healthy',
    ], [
        'sentinel_updated_at' => now()->subMinute(),
    ]);

    ResourcesCheck::run();

    $application->refresh();

    expect($application->status)->toBe('running:healthy');
});

it('marks resources exited when their sentinel server is stale', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 12, 0, 0, 'UTC'));
    config(['constants.sentinel.resource_stale_seconds' => 300]);

    $application = resourcesCheckApplication([
        'last_online_at' => now()->subHour(),
        'status' => 'running:healthy',
    ], [
        'sentinel_updated_at' => now()->subMinutes(10),
    ]);

    ResourcesCheck::run();

    $application->refresh();

    expect($application->status)->toBe('exited:unhealthy');
});

function resourcesCheckApplication(array $applicationAttributes = [], array $serverAttributes = []): Application
{
    $lastOnlineAt = $applicationAttributes['last_online_at'] ?? null;
    unset($applicationAttributes['last_online_at']);

    $team = Team::factory()->create();
    $server = Server::factory()->create(array_merge([
        'team_id' => $team->id,
    ], $serverAttributes));
    $server->settings()->update(['is_sentinel_enabled' => true]);

    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $application = Application::factory()->create(array_merge([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ], $applicationAttributes));

    if ($lastOnlineAt !== null) {
        $application->forceFill(['last_online_at' => $lastOnlineAt])->saveQuietly();
    }

    return $application;
}
