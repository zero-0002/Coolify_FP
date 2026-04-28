<?php

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Database\Seeders\DevelopmentRailpackExamplesSeeder;
use Database\Seeders\GithubAppSeeder;
use Database\Seeders\PrivateKeySeeder;
use Database\Seeders\ProjectSeeder;
use Database\Seeders\ServerSeeder;
use Database\Seeders\StandaloneDockerSeeder;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedRailpackExamplePrerequisites(): void
{
    test()->seed([
        UserSeeder::class,
        TeamSeeder::class,
        PrivateKeySeeder::class,
        ServerSeeder::class,
        ProjectSeeder::class,
        StandaloneDockerSeeder::class,
        GithubAppSeeder::class,
    ]);
}

it('can seed the railpack examples directly on a clean development database', function () {
    config()->set('app.env', 'local');

    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    expect(Team::query()->find(0))->not->toBeNull();
    expect(PrivateKey::query()->find(1))->not->toBeNull();
    expect(Server::query()->find(0))->not->toBeNull();
    expect(StandaloneDocker::query()->find(0))->not->toBeNull();
    expect(GithubApp::query()->find(0))->not->toBeNull();
    expect(Project::query()->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)->exists())->toBeTrue();
    expect(Application::query()->count())->toBe(count(DevelopmentRailpackExamplesSeeder::examples()));
});

it('seeds the railpack examples in development mode', function () {
    config()->set('app.env', 'local');

    seedRailpackExamplePrerequisites();
    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    $project = Project::query()
        ->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)
        ->first();

    expect($project)
        ->not->toBeNull()
        ->and($project->name)->toBe('Railpack Examples')
        ->and($project->environments)->toHaveCount(1)
        ->and($project->environments->first()->uuid)->toBe(DevelopmentRailpackExamplesSeeder::ENVIRONMENT_UUID);

    $applications = $project->applications()->with('settings')->orderBy('uuid')->get();

    expect($applications)->toHaveCount(count(DevelopmentRailpackExamplesSeeder::examples()));
    expect($applications->every(fn (Application $application) => $application->build_pack === 'railpack'))->toBeTrue();
    expect($applications->every(fn (Application $application) => $application->git_repository === DevelopmentRailpackExamplesSeeder::GIT_REPOSITORY))->toBeTrue();
    expect($applications->every(fn (Application $application) => $application->git_branch === DevelopmentRailpackExamplesSeeder::GIT_BRANCH))->toBeTrue();

    $nestjs = $applications->firstWhere('uuid', 'railpack-nestjs');
    $angularStatic = $applications->firstWhere('uuid', 'railpack-angular-static');
    $eleventyStatic = $applications->firstWhere('uuid', 'railpack-eleventy-static');

    expect($nestjs)
        ->not->toBeNull()
        ->and($nestjs->base_directory)->toBe('/node/nestjs')
        ->and($nestjs->ports_exposes)->toBe('3000')
        ->and($nestjs->build_command)->toBe('npm run build')
        ->and($nestjs->start_command)->toBe('npm run start:prod')
        ->and($nestjs->settings->is_static)->toBeFalse();

    expect($angularStatic)
        ->not->toBeNull()
        ->and($angularStatic->publish_directory)->toBe('/dist/static/browser')
        ->and($angularStatic->ports_exposes)->toBe('80')
        ->and($angularStatic->settings->is_static)->toBeTrue()
        ->and($angularStatic->settings->is_spa)->toBeTrue();

    expect($eleventyStatic)
        ->not->toBeNull()
        ->and($eleventyStatic->publish_directory)->toBe('/_site')
        ->and($eleventyStatic->settings->is_static)->toBeTrue()
        ->and($eleventyStatic->settings->is_spa)->toBeFalse();
});

it('skips the railpack examples outside development mode', function () {
    config()->set('app.env', 'testing');

    seedRailpackExamplePrerequisites();
    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    expect(Project::query()->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)->exists())->toBeFalse();
    expect(Application::query()->where('uuid', 'railpack-nextjs-ssr')->exists())->toBeFalse();
});

it('is idempotent when run multiple times', function () {
    config()->set('app.env', 'local');

    seedRailpackExamplePrerequisites();
    $this->seed(DevelopmentRailpackExamplesSeeder::class);
    $this->seed(DevelopmentRailpackExamplesSeeder::class);

    $project = Project::query()
        ->where('uuid', DevelopmentRailpackExamplesSeeder::PROJECT_UUID)
        ->first();

    expect($project)->not->toBeNull();
    expect($project->applications()->count())->toBe(count(DevelopmentRailpackExamplesSeeder::examples()));
});
