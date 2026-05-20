<?php

use App\Livewire\Project\Database\Dragonfly\General as DragonflyGeneral;
use App\Livewire\Project\Database\Keydb\General as KeydbGeneral;
use App\Livewire\Project\Database\Mariadb\General as MariadbGeneral;
use App\Livewire\Project\Database\Mongodb\General as MongodbGeneral;
use App\Livewire\Project\Database\Mysql\General as MysqlGeneral;
use App\Livewire\Project\Database\Postgresql\General as PostgresqlGeneral;
use App\Livewire\Project\Database\Redis\General as RedisGeneral;
use App\Livewire\Project\Database\Redis\StatusInfo as RedisStatusInfo;
use App\Livewire\Server\Sentinel;
use App\Livewire\Server\Show;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

dataset('database-general-forms-without-broadcasts', [
    // Redis splits status-derived display into a sibling component; the form itself
    // takes no broadcast listeners. Other DBs use the narrower refreshStatus pattern below.
    RedisGeneral::class,
]);

dataset('database-general-forms-with-narrow-refresh', [
    // Form listens to status broadcasts but routes them to refreshStatus, which only
    // writes display-only properties (URLs, cert expiry) — never input-bound text fields.
    PostgresqlGeneral::class,
    MysqlGeneral::class,
    MariadbGeneral::class,
    MongodbGeneral::class,
    KeydbGeneral::class,
    DragonflyGeneral::class,
]);

dataset('database-status-info-components', [
    RedisStatusInfo::class,
]);

it('does not subscribe the form to status broadcasts when display lives in a sibling', function (string $componentClass) {
    // Regression guard for coolify#6062 / #6354 / #9695:
    // For DBs whose status-derived display moved into a sibling component, the form
    // itself must not subscribe to status broadcasts at all.
    $listeners = resolveLivewireListeners(app($componentClass));

    expect($listeners)
        ->not->toHaveKey("echo-private:user.{$this->user->id},DatabaseStatusChanged")
        ->not->toHaveKey("echo-private:team.{$this->team->id},ServiceChecked")
        ->not->toHaveKey("echo-private:team.{$this->team->id},ServiceStatusChanged");
})->with('database-general-forms-without-broadcasts');

it('routes status broadcasts to refreshStatus, never to a handler that re-syncs inputs', function (string $componentClass) {
    // Regression guard for coolify#6062 / #6354 / #9695:
    // The form may listen to broadcasts, but only to a narrow handler (refreshStatus)
    // that touches display-only properties. Routing to `refresh` or `$refresh` would
    // re-sync every input property from the DB and wipe in-progress typing.
    $listeners = resolveLivewireListeners(app($componentClass));

    $databaseStatusKey = "echo-private:user.{$this->user->id},DatabaseStatusChanged";
    $serviceCheckedKey = "echo-private:team.{$this->team->id},ServiceChecked";

    expect($listeners[$databaseStatusKey] ?? null)->toBe('refreshStatus')
        ->and($listeners[$serviceCheckedKey] ?? null)->toBe('refreshStatus');
})->with('database-general-forms-with-narrow-refresh');

function resolveLivewireListeners(object $component): array
{
    // Livewire's HandlesEvents trait declares getListeners() as protected,
    // so subclasses that override it as public are callable directly, but
    // subclasses that rely on $listeners are not. Reflection handles both.
    $method = new ReflectionMethod($component, 'getListeners');
    $method->setAccessible(true);

    return (array) $method->invoke($component);
}

it('auto-refreshes status-info sibling on database status broadcasts', function (string $componentClass) {
    // Status-derived display (connection URLs, SSL gate hint, cert expiry) lives in a sibling
    // Livewire component so it can re-render on broadcasts without touching the form's DOM.
    $listeners = resolveLivewireListeners(app($componentClass));

    expect($listeners)
        ->toHaveKey("echo-private:user.{$this->user->id},DatabaseStatusChanged")
        ->toHaveKey("echo-private:team.{$this->team->id},ServiceChecked");
})->with('database-status-info-components');

it('reloads the mysql database model when refresh is called directly so ssl controls follow the latest status', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $database = StandaloneMysql::create([
        'name' => 'test-mysql',
        'image' => 'mysql:8',
        'mysql_root_password' => 'password',
        'mysql_user' => 'coolify',
        'mysql_password' => 'password',
        'mysql_database' => 'coolify',
        'status' => 'exited:unhealthy',
        'enable_ssl' => true,
        'is_log_drain_enabled' => false,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $component = Livewire::test(MysqlGeneral::class, ['database' => $database])
        ->assertDontSee('Database should be stopped to change this settings.');

    $database->fill(['status' => 'running:healthy'])->save();

    $component->call('refresh')
        ->assertSee('Database should be stopped to change this settings.');
});

it('does not clobber server form text inputs when sentinel restarts', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'persisted-server-name',
    ]);

    $component = Livewire::test(Sentinel::class, ['server_uuid' => $server->uuid])
        ->set('sentinelToken', 'user-was-typing-this-token');

    $component->call('handleSentinelRestarted', ['serverUuid' => $server->uuid]);

    expect($component->get('sentinelToken'))->toBe('user-was-typing-this-token');
});

it('does not clobber server form text inputs when server validation completes', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'persisted-server-name',
    ]);

    $component = Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->set('name', 'user-was-typing-here')
        ->set('ip', '203.0.113.42');

    $component->call('handleServerValidated', ['serverUuid' => $server->uuid]);

    expect($component->get('name'))->toBe('user-was-typing-here')
        ->and($component->get('ip'))->toBe('203.0.113.42');
});

it('preserves typed input on the postgres form when refreshStatus runs', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $database = StandalonePostgresql::create([
        'name' => 'persisted-name',
        'image' => 'postgres:16',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'status' => 'exited:unhealthy',
        'enable_ssl' => false,
        'is_log_drain_enabled' => false,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $component = Livewire::test(PostgresqlGeneral::class, ['database' => $database])
        ->set('name', 'user-was-typing-here')
        ->set('portsMappings', '5433:5432');

    $component->call('refreshStatus');

    expect($component->get('name'))->toBe('user-was-typing-here')
        ->and($component->get('portsMappings'))->toBe('5433:5432');
});

it('shows the redis ssl gate hint after the sibling is refreshed', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $database = StandaloneRedis::create([
        'name' => 'test-redis',
        'image' => 'redis:7',
        'redis_password' => 'password',
        'redis_username' => 'default',
        'status' => 'exited:unhealthy',
        'enable_ssl' => true,
        'is_log_drain_enabled' => false,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $component = Livewire::test(RedisStatusInfo::class, ['database' => $database])
        ->assertDontSee('Database should be stopped to change this settings.');

    $database->fill(['status' => 'running:healthy'])->save();

    $component->call('refresh')
        ->assertSee('Database should be stopped to change this settings.');
});
