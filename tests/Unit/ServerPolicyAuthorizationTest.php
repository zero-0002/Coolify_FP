<?php

use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Policies\ServerPolicy;
use Illuminate\Database\Eloquent\Relations\Pivot;

function userWithServerRole(int $teamId, string $role): User
{
    $team = new Team;
    $team->setRawAttributes(['id' => $teamId], true);
    $team->setRelation('pivot', new Pivot(['role' => $role]));

    $user = new User;
    $user->setRelation('teams', collect([$team]));
    $user->setRelation('pivot', new Pivot(['role' => $role]));

    return $user;
}

function serverPolicyServer(int $teamId): Server
{
    $server = new Server;
    $server->setRawAttributes(['team_id' => $teamId], true);

    return $server;
}

test('server members cannot update or manage servers', function () {
    $policy = new ServerPolicy;
    $member = userWithServerRole(1, 'member');
    $server = serverPolicyServer(1);

    expect($policy->update($member, $server))->toBeFalse()
        ->and($policy->create($member))->toBeFalse()
        ->and($policy->delete($member, $server))->toBeFalse()
        ->and($policy->manageProxy($member, $server))->toBeFalse()
        ->and($policy->manageSentinel($member, $server))->toBeFalse()
        ->and($policy->manageCaCertificate($member, $server))->toBeFalse()
        ->and($policy->viewSecurity($member, $server))->toBeFalse();
});

test('server admins can update and manage servers in their team', function (string $role) {
    $policy = new ServerPolicy;
    $admin = userWithServerRole(1, $role);
    $server = serverPolicyServer(1);

    expect($policy->update($admin, $server))->toBeTrue()
        ->and($policy->create($admin))->toBeTrue()
        ->and($policy->delete($admin, $server))->toBeTrue()
        ->and($policy->manageProxy($admin, $server))->toBeTrue()
        ->and($policy->manageSentinel($admin, $server))->toBeTrue()
        ->and($policy->manageCaCertificate($admin, $server))->toBeTrue()
        ->and($policy->viewSecurity($admin, $server))->toBeTrue();
})->with(['admin', 'owner']);

test('server admins cannot update servers outside their team', function () {
    $policy = new ServerPolicy;
    $admin = userWithServerRole(2, 'admin');
    $server = serverPolicyServer(1);

    expect($policy->update($admin, $server))->toBeFalse()
        ->and($policy->delete($admin, $server))->toBeFalse()
        ->and($policy->manageProxy($admin, $server))->toBeFalse();
});
