<?php

use App\Helpers\SshMultiplexingHelper;
use App\Jobs\CleanupStaleMultiplexedConnections;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Tests for the per-server lock that prevents concurrent workers from each
 * spawning their own SSH master, which leaves orphaned non-master ssh
 * connections that ControlPersist never reaps (memory leak).
 */
uses(RefreshDatabase::class);

function makeMuxServer(): Server
{
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $privateKey = PrivateKey::create([
        'name' => 'mux-test-key-'.uniqid(),
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n".
            "b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW\n".
            "QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk\n".
            "hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA\n".
            "AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV\n".
            "uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==\n".
            '-----END OPENSSH PRIVATE KEY-----',
        'team_id' => $team->id,
    ]);

    return Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
}

it('establishes a master with ssh -fN and never the orphan-prone ssh -fNM', function () {
    config(['constants.ssh.mux_enabled' => true]);
    $server = makeMuxServer();

    Process::fake([
        '*-O check*' => Process::result(exitCode: 1), // no existing master
        '*-fN *' => Process::result(exitCode: 0),      // establish succeeds
    ]);

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeTrue();

    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -fN ')
        && ! str_contains($process->command, 'ssh -fNM'));
});

it('reuses an existing healthy master without spawning a new one', function () {
    config([
        'constants.ssh.mux_enabled' => true,
        'constants.ssh.mux_health_check_enabled' => true,
    ]);
    $server = makeMuxServer();

    Process::fake([
        '*-O check*' => Process::result(exitCode: 0),
        '*health_check_ok*' => Process::result(output: 'health_check_ok', exitCode: 0),
    ]);

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeTrue();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'ssh -fN'));
});

it('does not spawn a master when the per-server lock is already held', function () {
    config([
        'constants.ssh.mux_enabled' => true,
        'constants.ssh.mux_lock_timeout' => 0,
    ]);
    $server = makeMuxServer();

    Process::fake([
        '*-O check*' => Process::result(exitCode: 1), // forces the slow path
    ]);

    // Simulate another worker on the same host holding the lock for this server.
    $lockKey = 'ssh_mux_lock_'.(gethostname() ?: 'unknown').'_'.$server->uuid;
    $held = Cache::lock($lockKey, 30);
    expect($held->get())->toBeTrue();

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeFalse();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'ssh -fN '));

    $held->release();
});

it('returns false and runs no ssh when multiplexing is disabled', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $server = makeMuxServer();

    Process::fake();

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeFalse();

    Process::assertNothingRan();
});

it('kills only old orphaned ssh masters whose control socket no longer exists', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    $muxDir = storage_path('app/ssh/mux');
    File::ensureDirectoryExists($muxDir);

    $liveSocket = $muxDir.'/mux_live_'.uniqid();
    $orphanSocket = $muxDir.'/mux_orphan_'.uniqid();
    $youngSocket = $muxDir.'/mux_young_'.uniqid();
    File::put($liveSocket, 'x'); // live master owns its socket file; the others do not

    // Columns: pid ppid etimes args
    Process::fake([
        'ps*' => Process::result(output: "111 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$liveSocket} root@1.2.3.4\n".
            "222 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$orphanSocket} root@1.2.3.4\n".
            "333 1 30 ssh -fN -o ControlMaster=auto -o ControlPath={$youngSocket} root@1.2.3.4\n"),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupOrphanedSshProcesses');
    $method->setAccessible(true);
    $method->invoke($job);

    // Old orphan: killed.
    Process::assertRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '222'));
    // Live master (socket exists): spared.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '111'));
    // Young process (may be mid-establish): spared despite missing socket.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '333'));

    File::delete($liveSocket);
});

it('kills only old orphaned cloudflared proxies whose parent ssh is gone', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    // pid 100 = live ssh master; 200 = its legit child; 300 = old orphan;
    // 400 = young orphan (parent ssh may still be starting up).
    Process::fake([
        'ps*' => Process::result(output: "100 1 5000 ssh -fN -o ControlMaster=auto root@1.2.3.4\n".
            "200 100 5000 cloudflared access ssh --hostname host.example.com\n".
            "300 2176 5000 cloudflared access ssh --hostname host.example.com\n".
            "400 2176 30 cloudflared access ssh --hostname host.example.com\n".
            "2176 1 9000 /usr/bin/some-supervisor\n"),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupOrphanedCloudflaredProcesses');
    $method->setAccessible(true);
    $method->invoke($job);

    // Old orphan (parent not ssh): killed.
    Process::assertRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '300'));
    // Legit proxy (parent ssh alive): spared.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '200'));
    // Young orphan: spared.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '400'));
});

it('dry-run mode logs orphans but kills nothing when reaping is disabled', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => false]);
    $muxDir = storage_path('app/ssh/mux');
    File::ensureDirectoryExists($muxDir);

    $orphanSocket = $muxDir.'/mux_orphan_'.uniqid(); // no file: a real old orphan

    Process::fake([
        'ps*' => Process::result(output: "222 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$orphanSocket} root@1.2.3.4\n"),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupOrphanedSshProcesses');
    $method->setAccessible(true);
    $method->invoke($job);

    // Orphan detected, but dry-run: nothing killed.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill'));
});

it('removes mux files for non-existent servers when reaping is enabled', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    Storage::fake('ssh-mux');
    $file = 'mux_ghost'.uniqid();
    Storage::disk('ssh-mux')->put($file, 'x');
    Process::fake(); // the `ssh -O exit` close command

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupNonExistentServerConnections');
    $method->setAccessible(true);
    $method->invoke($job);

    expect(Storage::disk('ssh-mux')->exists($file))->toBeFalse();
});

it('keeps mux files for non-existent servers in dry-run mode', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => false]);
    Storage::fake('ssh-mux');
    $file = 'mux_ghost'.uniqid();
    Storage::disk('ssh-mux')->put($file, 'x');
    Process::fake();

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupNonExistentServerConnections');
    $method->setAccessible(true);
    $method->invoke($job);

    expect(Storage::disk('ssh-mux')->exists($file))->toBeTrue();
    Process::assertNothingRan();
});
