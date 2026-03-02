<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Application Rollback', function () {
    beforeEach(function () {
        $team = Team::factory()->create();
        $project = Project::create([
            'team_id' => $team->id,
            'name' => 'Test Project',
            'uuid' => (string) str()->uuid(),
        ]);
        $environment = Environment::create([
            'project_id' => $project->id,
            'name' => 'rollback-test-env',
            'uuid' => (string) str()->uuid(),
        ]);
        $server = Server::factory()->create(['team_id' => $team->id]);

        $this->application = Application::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $server->id,
            'git_commit_sha' => 'HEAD',
        ]);
    });

    test('setGitImportSettings uses passed commit instead of application git_commit_sha', function () {
        ApplicationSetting::create([
            'application_id' => $this->application->id,
            'is_git_shallow_clone_enabled' => false,
        ]);

        $rollbackCommit = 'abc123def456abc123def456abc123def456abc1';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
            commit: $rollbackCommit
        );

        expect($result)->toContain($rollbackCommit);
    });

    test('setGitImportSettings with shallow clone fetches specific commit', function () {
        ApplicationSetting::create([
            'application_id' => $this->application->id,
            'is_git_shallow_clone_enabled' => true,
        ]);

        $rollbackCommit = 'abc123def456abc123def456abc123def456abc1';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
            commit: $rollbackCommit
        );

        expect($result)
            ->toContain('git fetch --depth=1 origin')
            ->toContain($rollbackCommit);
    });

    test('setGitImportSettings falls back to git_commit_sha when no commit passed', function () {
        $this->application->update(['git_commit_sha' => 'def789abc012def789abc012def789abc012def7']);

        ApplicationSetting::create([
            'application_id' => $this->application->id,
            'is_git_shallow_clone_enabled' => false,
        ]);

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
        );

        expect($result)->toContain('def789abc012def789abc012def789abc012def7');
    });

    test('setGitImportSettings does not append checkout when commit is HEAD', function () {
        ApplicationSetting::create([
            'application_id' => $this->application->id,
            'is_git_shallow_clone_enabled' => false,
        ]);

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
        );

        expect($result)->not->toContain('advice.detachedHead=false checkout');
    });
});
