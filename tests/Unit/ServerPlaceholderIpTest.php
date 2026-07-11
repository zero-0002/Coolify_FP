<?php

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createServerForPlaceholderIpTest(string $ip): Server
{
    $team = Team::create([
        'name' => 'Test Team',
        'personal_team' => false,
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

    return Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => $ip,
    ]);
}

it('detects placeholder IPs', function () {
    $server = createServerForPlaceholderIpTest(Server::PLACEHOLDER_IP);

    foreach ([Server::PLACEHOLDER_IP, '0.0.0.0', '::'] as $ip) {
        $server->update(['ip' => $ip]);

        expect($server->fresh()->hasPlaceholderIp())->toBeTrue();
    }
});

it('does not treat a real IP as a placeholder', function () {
    $server = createServerForPlaceholderIpTest('203.0.113.5');

    expect($server->hasPlaceholderIp())->toBeFalse();
});

it('backfills a placeholder IP with the real address', function () {
    $server = createServerForPlaceholderIpTest(Server::PLACEHOLDER_IP);

    expect($server->backfillPlaceholderIp('203.0.113.5'))->toBeTrue();
    expect($server->fresh()->ip)->toBe('203.0.113.5');
});

it('does not overwrite a real IP when backfilling', function () {
    $server = createServerForPlaceholderIpTest('203.0.113.5');

    expect($server->backfillPlaceholderIp('198.51.100.9'))->toBeFalse();
    expect($server->fresh()->ip)->toBe('203.0.113.5');
});

it('ignores a missing IP when backfilling', function () {
    $server = createServerForPlaceholderIpTest(Server::PLACEHOLDER_IP);

    expect($server->backfillPlaceholderIp(null))->toBeFalse();
    expect($server->fresh()->ip)->toBe(Server::PLACEHOLDER_IP);
});

it('skips servers with a placeholder IP in scheduled jobs', function () {
    $server = createServerForPlaceholderIpTest(Server::PLACEHOLDER_IP);

    expect($server->skipServer())->toBeTrue();
});
