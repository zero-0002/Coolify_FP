<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\Crypt\EC;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    $ecKey = EC::createKey('Ed25519');
    $privateKeyContent = $ecKey->toString('OpenSSH');

    $privateKey = PrivateKey::create([
        'name' => 'test-key',
        'private_key' => $privateKeyContent,
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    ServerSetting::create([
        'server_id' => $this->server->id,
        'wildcard_domain' => 'http://127.0.0.1.sslip.io',
    ]);

    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->uuid(),
    ]);
});

test('applicationParser populates docker_compose_domains for KEY-based SERVICE_FQDN variables', function () {
    $dockerCompose = <<<'YAML'
services:
  backend:
    image: myapp/backend:latest
    environment:
      - SERVICE_FQDN_BACKEND_8000=${BACKEND_URL}
  frontend:
    image: myapp/frontend:latest
    environment:
      - SERVICE_FQDN_FRONTEND=${FRONTEND_URL}
YAML;

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $dockerCompose,
        'fqdn' => null,
        'docker_compose_domains' => null,
    ]);

    applicationParser($application);

    $application->refresh();

    $domains = json_decode($application->docker_compose_domains, true);

    expect($domains)->not->toBeNull()
        ->and($domains)->toBeArray()
        ->and($domains)->toHaveKey('backend')
        ->and($domains['backend'])->toHaveKey('domain')
        ->and($domains['backend']['domain'])->not->toBeEmpty();
});

test('applicationParser populates docker_compose_domains for KEY-based SERVICE_FQDN without port', function () {
    $dockerCompose = <<<'YAML'
services:
  frontend:
    image: myapp/frontend:latest
    environment:
      - SERVICE_FQDN_FRONTEND=${FRONTEND_URL}
YAML;

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $dockerCompose,
        'fqdn' => null,
        'docker_compose_domains' => null,
    ]);

    applicationParser($application);

    $application->refresh();

    $domains = json_decode($application->docker_compose_domains, true);

    expect($domains)->not->toBeNull()
        ->and($domains)->toHaveKey('frontend')
        ->and($domains['frontend'])->toHaveKey('domain');
});

test('applicationParser does not populate docker_compose_domains for non-dockercompose build_pack', function () {
    $dockerCompose = <<<'YAML'
services:
  backend:
    image: myapp/backend:latest
    environment:
      - SERVICE_FQDN_BACKEND_8000=${BACKEND_URL}
YAML;

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockerfile',
        'docker_compose_raw' => $dockerCompose,
        'fqdn' => null,
        'docker_compose_domains' => null,
    ]);

    applicationParser($application);

    $application->refresh();

    // For non-dockercompose, docker_compose_domains should remain empty
    $domains = json_decode($application->docker_compose_domains, true);
    expect($domains)->toBeNull();
});
