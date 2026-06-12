<?php

use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    // Create an API token for the user
    $this->token = $this->user->createToken('test-token', ['*'], $this->team->id);
    $this->bearerToken = $this->token->plainTextToken;

    // Create a private key for the team
    $this->privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => 'test-private-key-content',
        'team_id' => $this->team->id,
    ]);
});

/**
 * Generate a temporary 2048-bit RSA private key for GitHub Apps API tests.
 *
 * Generated in-process so tests do not depend on external files or secrets;
 * the key is used only as a signing fixture and is never persisted.
 *
 * @return string PEM-encoded RSA private key
 */
function validGithubAppsApiPrivateKey(): string
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($key, $privateKey);

    return $privateKey;
}

describe('GET /api/v1/github-apps', function () {
    test('returns 401 when not authenticated', function () {
        $response = $this->getJson('/api/v1/github-apps');

        $response->assertStatus(401);
    });

    test('returns empty array when no github apps exist', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/github-apps');

        $response->assertStatus(200);
        $response->assertJson([]);
    });

    test('returns team github apps', function () {
        // Create a GitHub app for the team
        $githubApp = GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 12345,
            'installation_id' => 67890,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'webhook_secret' => 'test-webhook-secret',
            'private_key_id' => $this->privateKey->id,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
            'is_public' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/github-apps');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'name' => 'Test GitHub App',
            'app_id' => 12345,
        ]);
    });

    test('does not return sensitive data', function () {
        // Create a GitHub app
        GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 12345,
            'installation_id' => 67890,
            'client_id' => 'test-client-id',
            'client_secret' => 'secret-should-be-hidden',
            'webhook_secret' => 'webhook-secret-should-be-hidden',
            'private_key_id' => $this->privateKey->id,
            'team_id' => $this->team->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/github-apps');

        $response->assertStatus(200);
        $json = $response->json();

        // Ensure sensitive data is not present
        expect($json[0])->not->toHaveKey('client_secret');
        expect($json[0])->not->toHaveKey('webhook_secret');
    });

    test('returns system-wide github apps', function () {
        // Create a system-wide GitHub app
        $systemApp = GithubApp::create([
            'name' => 'System GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 11111,
            'installation_id' => 22222,
            'client_id' => 'system-client-id',
            'client_secret' => 'system-secret',
            'webhook_secret' => 'system-webhook',
            'private_key_id' => $this->privateKey->id,
            'team_id' => $this->team->id,
            'is_system_wide' => true,
        ]);

        // Create another team and user
        $otherTeam = Team::factory()->create();
        $otherUser = User::factory()->create();
        $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
        $otherToken = $otherUser->createToken('other-token', ['*'], $otherTeam->id);

        // System-wide apps should be visible to other teams
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$otherToken->plainTextToken,
        ])->getJson('/api/v1/github-apps');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'name' => 'System GitHub App',
            'is_system_wide' => true,
        ]);
    });

    test('does not return other teams github apps', function () {
        // Create a GitHub app for this team
        GithubApp::create([
            'name' => 'Team 1 App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 11111,
            'installation_id' => 22222,
            'client_id' => 'team1-client-id',
            'client_secret' => 'team1-secret',
            'webhook_secret' => 'team1-webhook',
            'private_key_id' => $this->privateKey->id,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
        ]);

        // Create another team with a GitHub app
        $otherTeam = Team::factory()->create();
        $otherPrivateKey = PrivateKey::create([
            'name' => 'Other Key',
            'private_key' => 'other-key',
            'team_id' => $otherTeam->id,
        ]);
        GithubApp::create([
            'name' => 'Team 2 App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 33333,
            'installation_id' => 44444,
            'client_id' => 'team2-client-id',
            'client_secret' => 'team2-secret',
            'webhook_secret' => 'team2-webhook',
            'private_key_id' => $otherPrivateKey->id,
            'team_id' => $otherTeam->id,
            'is_system_wide' => false,
        ]);

        // Request from first team should only see their app
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/github-apps');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => 'Team 1 App']);
        $response->assertJsonMissing(['name' => 'Team 2 App']);
    });

    test('returns correct response structure', function () {
        GithubApp::create([
            'name' => 'Test App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'app_id' => 12345,
            'installation_id' => 67890,
            'client_id' => 'client-id',
            'client_secret' => 'secret',
            'webhook_secret' => 'webhook',
            'private_key_id' => $this->privateKey->id,
            'team_id' => $this->team->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/github-apps');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            [
                'id',
                'uuid',
                'name',
                'api_url',
                'html_url',
                'custom_user',
                'custom_port',
                'app_id',
                'installation_id',
                'client_id',
                'private_key_id',
                'team_id',
                'type',
            ],
        ]);
    });
});

describe('GitHub app API url normalization', function () {
    test('normalizes ghe dot com api url when creating github apps', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->postJson('/api/v1/github-apps', [
            'name' => 'GHE App',
            'organization' => '/octocorp/',
            'html_url' => 'https://octocorp.ghe.com',
            'app_id' => 12345,
            'installation_id' => 67890,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'webhook_secret' => 'test-webhook-secret',
            'private_key_uuid' => $this->privateKey->uuid,
        ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'organization' => 'octocorp',
                'api_url' => 'https://api.octocorp.ghe.com',
                'html_url' => 'https://octocorp.ghe.com',
            ]);
    });

    test('normalizes ghe dot com api url when updating github apps', function () {
        $githubApp = GithubApp::create([
            'name' => 'GHE App',
            'api_url' => 'https://github.company.internal/api/v3',
            'html_url' => 'https://github.company.internal',
            'app_id' => 12345,
            'installation_id' => 67890,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'webhook_secret' => 'test-webhook-secret',
            'private_key_id' => $this->privateKey->id,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
            'is_public' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->patchJson("/api/v1/github-apps/{$githubApp->id}", [
            'html_url' => 'https://octocorp.ghe.com',
            'api_url' => 'https://octocorp.ghe.com/api/v3',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.api_url', 'https://api.octocorp.ghe.com');
    });

    test('rejects invalid organization when creating github apps', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->postJson('/api/v1/github-apps', [
            'name' => 'GHE App',
            'organization' => 'octo/corp',
            'api_url' => 'https://api.octocorp.ghe.com',
            'html_url' => 'https://octocorp.ghe.com',
            'app_id' => 12345,
            'installation_id' => 67890,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'webhook_secret' => 'test-webhook-secret',
            'private_key_uuid' => $this->privateKey->uuid,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['organization']);
    });

    test('loads repositories and branches through normalized ghe dot com api url', function () {
        $this->privateKey->update([
            'private_key' => validGithubAppsApiPrivateKey(),
        ]);

        $githubApp = GithubApp::create([
            'name' => 'GHE App',
            'api_url' => 'https://api.octocorp.ghe.com',
            'html_url' => 'https://octocorp.ghe.com',
            'app_id' => 12345,
            'installation_id' => 67890,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'webhook_secret' => 'test-webhook-secret',
            'private_key_id' => $this->privateKey->id,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
            'is_public' => false,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.octocorp.ghe.com/zen' => Http::response('Keep it logically awesome.', 200, [
                'Date' => now()->toRfc7231String(),
            ]),
            'https://api.octocorp.ghe.com/app/installations/67890/access_tokens' => Http::response([
                'token' => 'installation-token',
            ]),
            'https://api.octocorp.ghe.com/installation/repositories*' => Http::response([
                'repositories' => [
                    ['name' => 'repo', 'full_name' => 'octocorp/repo'],
                ],
            ]),
            'https://api.octocorp.ghe.com/repos/octocorp/repo/branches' => Http::response([
                ['name' => 'main'],
            ]),
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson("/api/v1/github-apps/{$githubApp->id}/repositories")
            ->assertSuccessful()
            ->assertJsonPath('repositories.0.name', 'repo');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson("/api/v1/github-apps/{$githubApp->id}/repositories/octocorp/repo/branches")
            ->assertSuccessful()
            ->assertJsonPath('branches.0.name', 'main');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.octocorp.ghe.com/installation/repositories?per_page=100&page=1');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.octocorp.ghe.com/repos/octocorp/repo/branches');
    });
});
