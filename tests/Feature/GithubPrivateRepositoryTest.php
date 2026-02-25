<?php

use App\Livewire\Project\New\GithubPrivateRepository;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->rsaKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($this->rsaKey, $pemKey);

    $this->privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => $pemKey,
        'team_id' => $this->team->id,
    ]);

    $this->githubApp = GithubApp::create([
        'name' => 'Test GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 12345,
        'installation_id' => 67890,
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'webhook_secret' => 'test-webhook-secret',
        'private_key_id' => $this->privateKey->id,
        'team_id' => $this->team->id,
        'is_system_wide' => false,
    ]);
});

function fakeGithubHttp(array $repositories): void
{
    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, [
            'Date' => now()->toRfc7231String(),
        ]),
        'https://api.github.com/app/installations/67890/access_tokens' => Http::response([
            'token' => 'fake-installation-token',
        ], 201),
        'https://api.github.com/installation/repositories*' => Http::response([
            'total_count' => count($repositories),
            'repositories' => $repositories,
        ], 200),
    ]);
}

describe('GitHub Private Repository Component', function () {
    test('loadRepositories fetches and displays repositories', function () {
        $repos = [
            ['id' => 1, 'name' => 'alpha-repo', 'owner' => ['login' => 'testuser']],
            ['id' => 2, 'name' => 'beta-repo', 'owner' => ['login' => 'testuser']],
        ];

        fakeGithubHttp($repos);

        Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app'])
            ->assertSet('current_step', 'github_apps')
            ->call('loadRepositories', $this->githubApp->id)
            ->assertSet('current_step', 'repository')
            ->assertSet('total_repositories_count', 2)
            ->assertSet('selected_repository_id', 1);
    });

    test('loadRepositories can be called again to refresh the repository list', function () {
        $initialRepos = [
            ['id' => 1, 'name' => 'alpha-repo', 'owner' => ['login' => 'testuser']],
        ];

        fakeGithubHttp($initialRepos);

        $component = Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app'])
            ->call('loadRepositories', $this->githubApp->id)
            ->assertSet('total_repositories_count', 1);

        // Simulate new repos becoming available after changing access on GitHub
        $updatedRepos = [
            ['id' => 1, 'name' => 'alpha-repo', 'owner' => ['login' => 'testuser']],
            ['id' => 2, 'name' => 'beta-repo', 'owner' => ['login' => 'testuser']],
            ['id' => 3, 'name' => 'gamma-repo', 'owner' => ['login' => 'testuser']],
        ];

        fakeGithubHttp($updatedRepos);

        $component
            ->call('loadRepositories', $this->githubApp->id)
            ->assertSet('total_repositories_count', 3)
            ->assertSet('current_step', 'repository');
    });

    test('refresh button is visible when repositories are loaded', function () {
        $repos = [
            ['id' => 1, 'name' => 'alpha-repo', 'owner' => ['login' => 'testuser']],
        ];

        fakeGithubHttp($repos);

        Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app'])
            ->call('loadRepositories', $this->githubApp->id)
            ->assertSeeHtml('title="Refresh Repository List"');
    });

    test('refresh button is not visible before repositories are loaded', function () {
        Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app'])
            ->assertDontSeeHtml('title="Refresh Repository List"');
    });
});
