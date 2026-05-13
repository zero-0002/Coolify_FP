<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function configurationChangedTestApplication(array $attributes = []): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create(array_merge([
        'environment_id' => $environment->id,
        'status' => 'running:healthy',
        'build_command' => 'npm run build',
    ], $attributes));
}

function configurationChangedDeployment(Application $application): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => (string) $application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'status' => 'finished',
        'commit' => 'HEAD',
    ]);
}

it('stores deployment configuration snapshot and clears pending changes', function () {
    $application = configurationChangedTestApplication();
    $deployment = configurationChangedDeployment($application);

    $application->markDeploymentConfigurationApplied($deployment);

    expect($deployment->refresh()->configuration_hash)->not->toBeNull()
        ->and($deployment->configuration_snapshot)->toBeArray()
        ->and($application->refresh()->pendingDeploymentConfigurationDiff()->isChanged())->toBeFalse();
});

it('stores a diff between successful deployments', function () {
    $application = configurationChangedTestApplication();
    $firstDeployment = configurationChangedDeployment($application);
    $application->markDeploymentConfigurationApplied($firstDeployment);

    $application->update(['build_command' => 'pnpm build']);
    $secondDeployment = configurationChangedDeployment($application->refresh());
    $application->markDeploymentConfigurationApplied($secondDeployment);

    expect($secondDeployment->refresh()->configuration_diff['count'])->toBe(1)
        ->and(data_get($secondDeployment->configuration_diff, 'changes.0.label'))->toBe('Build command');
});

it('falls back to legacy configuration hash when no deployment snapshot exists', function () {
    $application = configurationChangedTestApplication();
    $application->isConfigurationChanged(save: true);

    expect($application->refresh()->pendingDeploymentConfigurationDiff()->isChanged())->toBeFalse();

    $application->update(['build_command' => 'pnpm build']);

    expect($application->refresh()->pendingDeploymentConfigurationDiff()->isLegacyFallback())->toBeTrue()
        ->and($application->pendingDeploymentConfigurationDiff()->isChanged())->toBeTrue();
});
