<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'coolify-'.Str::lower(Str::random(8)),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
});

// Regression test: $request->input('force') ?? false coerced the string "false" to bool true,
// causing every API deploy with force=false to run with --no-cache.
test('force=false query string param does not set force_rebuild', function () {
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
        ->postJson('/api/v1/deploy?uuid='.$this->application->uuid.'&force=false');

    $response->assertSuccessful();

    $deployment = $this->application->deployment_queue()->latest('id')->first();
    expect($deployment)->not()->toBeNull();
    expect($deployment->force_rebuild)->toBeFalse();
});

test('force=true query string param sets force_rebuild', function () {
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
        ->postJson('/api/v1/deploy?uuid='.$this->application->uuid.'&force=true');

    $response->assertSuccessful();

    $deployment = $this->application->deployment_queue()->latest('id')->first();
    expect($deployment)->not()->toBeNull();
    expect($deployment->force_rebuild)->toBeTrue();
});

test('omitting force param does not set force_rebuild', function () {
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
        ->postJson('/api/v1/deploy?uuid='.$this->application->uuid);

    $response->assertSuccessful();

    $deployment = $this->application->deployment_queue()->latest('id')->first();
    expect($deployment)->not()->toBeNull();
    expect($deployment->force_rebuild)->toBeFalse();
});
