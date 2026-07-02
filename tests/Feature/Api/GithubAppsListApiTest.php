<?php

use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');

    InstanceSettings::query()->delete();
    $settings = new InstanceSettings;
    $settings->id = 0;
    $settings->save();

    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    // Create an API token for the user
    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    // Create a private key for the team
    $this->privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);
});

function createGithubAppsApiToken($context, array $abilities): string
{
    session(['currentTeam' => $context->team]);

    return $context->user->createToken('github-apps-test-token', $abilities)->plainTextToken;
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

    test('does not return sensitive data for read tokens', function () {
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

        $readToken = createGithubAppsApiToken($this, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readToken,
        ])->getJson('/api/v1/github-apps');

        $response->assertStatus(200);
        $json = $response->json();

        // Ensure sensitive data is not present
        expect($json[0])->not->toHaveKey('client_secret');
        expect($json[0])->not->toHaveKey('webhook_secret');
    });

    test('returns sensitive data for read sensitive tokens', function () {
        GithubApp::create([
            'name' => 'Sensitive GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 12345,
            'installation_id' => 67890,
            'client_id' => 'test-client-id',
            'client_secret' => 'secret-should-be-visible',
            'webhook_secret' => 'webhook-secret-should-be-visible',
            'private_key_id' => $this->privateKey->id,
            'team_id' => $this->team->id,
        ]);

        $sensitiveToken = createGithubAppsApiToken($this, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$sensitiveToken,
        ])->getJson('/api/v1/github-apps');

        $response->assertSuccessful();
        $response->assertJsonFragment([
            'client_secret' => 'secret-should-be-visible',
            'webhook_secret' => 'webhook-secret-should-be-visible',
        ]);
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
        session(['currentTeam' => $otherTeam]);
        $otherToken = $otherUser->createToken('other-token', ['*']);

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
        GithubApp::create([
            'name' => 'Team 2 App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 33333,
            'installation_id' => 44444,
            'client_id' => 'team2-client-id',
            'client_secret' => 'team2-secret',
            'webhook_secret' => 'team2-webhook',
            'private_key_id' => $this->privateKey->id,
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
