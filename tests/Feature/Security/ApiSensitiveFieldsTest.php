<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function makeApiToken(User $user, Team $team, array $abilities): string
{
    session(['currentTeam' => $team]);
    $token = $user->createToken('sensitive-test', $abilities);
    DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update([
        'team_id' => $team->id,
    ]);

    return $token->plainTextToken;
}

function makeTeamUser(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);

    return [$team, $user];
}

beforeEach(function () {
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings;
    $settings->id = 0;
    $settings->save();

    [$this->team, $this->user] = makeTeamUser();

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->forceFill([
        'sentinel_token' => encrypt('super-secret-sentinel-token'),
        'sentinel_custom_url' => 'https://sentinel.internal',
        'logdrain_axiom_api_key' => encrypt('axiom-key-secret'),
        'logdrain_newrelic_license_key' => encrypt('newrelic-key-secret'),
        'logdrain_custom_config' => 'custom-config-data',
        'logdrain_custom_config_parser' => 'custom-parser-data',
    ])->saveQuietly();
});

describe('GET /api/v1/servers sensitive field gating', function () {
    test('read token does not leak sentinel or logdrain fields', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/servers');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('sentinel_token');
        expect($body)->not->toContain('sentinel_custom_url');
        expect($body)->not->toContain('logdrain_axiom_api_key');
        expect($body)->not->toContain('logdrain_newrelic_license_key');
        expect($body)->not->toContain('logdrain_custom_config');
    });

    test('read sensitive token sees sentinel and logdrain fields', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/servers');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('sentinel_token');
        expect($body)->toContain('sentinel_custom_url');
        expect($body)->toContain('logdrain_axiom_api_key');
    });

    test('root token sees sentinel and logdrain fields', function () {
        $token = makeApiToken($this->user, $this->team, ['root']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/servers');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('sentinel_token');
    });
});

describe('GET /api/v1/applications nested-relation scrubbing', function () {
    beforeEach(function () {
        $this->project = Project::factory()->create(['team_id' => $this->team->id]);
        $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
        $destination = $this->server->standaloneDockers()->firstOrFail();

        $this->application = Application::create([
            'name' => 'sensitive-test-app',
            'git_repository' => 'https://github.com/test/test',
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'environment_id' => $this->environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
    });

    test('read token does not leak sentinel_token via destination.server.settings', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/applications');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('"sentinel_token":');
        expect($body)->not->toContain('"sentinel_custom_url":');
        expect($body)->not->toContain('"logdrain_axiom_api_key":');
    });

    test('read-sensitive token sees nested sentinel_token via destination.server.settings', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/applications');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('"sentinel_token":');
        expect($body)->toContain('"sentinel_custom_url":');
    });
});

describe('GET /api/v1/databases sensitive field gating', function () {
    beforeEach(function () {
        $this->project = Project::factory()->create(['team_id' => $this->team->id]);
        $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
        $destination = $this->server->standaloneDockers()->firstOrFail();

        $this->database = StandalonePostgresql::create([
            'name' => 'sensitive-db',
            'description' => 'test',
            'postgres_user' => 'postgres',
            'postgres_password' => encrypt('super-secret-db-password'),
            'postgres_db' => 'app',
            'image' => 'postgres:16-alpine',
            'environment_id' => $this->environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
    });

    test('read token does not leak postgres_password or db urls', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/databases');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('postgres_password');
        expect($body)->not->toContain('internal_db_url');
        expect($body)->not->toContain('external_db_url');
    });

    test('read sensitive token sees postgres_password and nested sentinel_token', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/databases');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('"postgres_password":');
        expect($body)->toContain('"internal_db_url":');
        expect($body)->toContain('"sentinel_token":');
    });

    test('project database list can eager load nested destination server settings', function () {
        $databases = $this->project->databases(['destination.server.settings']);
        $database = $databases->firstWhere('id', $this->database->id);

        expect($database)->not->toBeNull()
            ->and($database->relationLoaded('destination'))->toBeTrue()
            ->and($database->destination->relationLoaded('server'))->toBeTrue()
            ->and($database->destination->server->relationLoaded('settings'))->toBeTrue();
    });
});
