<?php

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;

describe('mass assignment protection', function () {

    test('no API-exposed model uses unguarded $guarded = []', function () {
        $models = [
            Application::class,
            Service::class,
            User::class,
            Team::class,
            Server::class,
            StandalonePostgresql::class,
            StandaloneRedis::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass;
            $guarded = $model->getGuarded();
            $fillable = $model->getFillable();

            // Model must NOT have $guarded = [] (empty guard = no protection)
            // It should either have non-empty $guarded OR non-empty $fillable
            $hasProtection = $guarded !== ['*'] ? count($guarded) > 0 : true;
            $hasProtection = $hasProtection || count($fillable) > 0;

            expect($hasProtection)
                ->toBeTrue("Model {$modelClass} has no mass assignment protection (empty \$guarded and empty \$fillable)");
        }
    });

    test('Application model blocks mass assignment of identity fields', function () {
        $application = new Application;

        expect($application->isFillable('id'))->toBeFalse('id should not be fillable');
        expect($application->isFillable('uuid'))->toBeFalse('uuid should not be fillable');
        expect($application->isFillable('created_at'))->toBeFalse('created_at should not be fillable');
        expect($application->isFillable('updated_at'))->toBeFalse('updated_at should not be fillable');
        expect($application->isFillable('deleted_at'))->toBeFalse('deleted_at should not be fillable');
    });

    test('Application model allows mass assignment of user-facing fields', function () {
        $application = new Application;
        $userFields = ['name', 'description', 'git_repository', 'git_branch', 'build_pack', 'install_command', 'build_command', 'start_command', 'ports_exposes', 'health_check_path', 'health_check_enabled', 'limits_memory', 'status'];

        foreach ($userFields as $field) {
            expect($application->isFillable($field))
                ->toBeTrue("Application model should allow mass assignment of '{$field}'");
        }
    });

    test('Application model allows mass assignment of relationship fields needed for create()', function () {
        $application = new Application;
        $relationFields = ['environment_id', 'destination_id', 'destination_type', 'source_id', 'source_type', 'private_key_id', 'repository_project_id'];

        foreach ($relationFields as $field) {
            expect($application->isFillable($field))
                ->toBeTrue("Application model should allow mass assignment of '{$field}' for internal create() calls");
        }
    });

    test('Application fill ignores non-fillable fields', function () {
        $application = new Application;
        $application->fill([
            'name' => 'test-app',
            'team_id' => 999,
        ]);

        expect($application->name)->toBe('test-app');
        expect($application->team_id)->toBeNull();
    });

    test('Server model has $fillable and no conflicting $guarded', function () {
        $server = new Server;
        $fillable = $server->getFillable();
        $guarded = $server->getGuarded();

        expect($fillable)->not->toBeEmpty('Server model should have explicit $fillable');
        expect($guarded)->not->toBe([], 'Server model should not have $guarded = [] overriding $fillable');
    });

    test('Server model blocks mass assignment of dangerous fields', function () {
        $server = new Server;

        expect($server->isFillable('id'))->toBeFalse();
        expect($server->isFillable('uuid'))->toBeFalse();
        expect($server->isFillable('created_at'))->toBeFalse();
    });

    test('User model blocks mass assignment of auth-sensitive fields', function () {
        $user = new User;

        expect($user->isFillable('id'))->toBeFalse('User id should not be fillable');
        expect($user->isFillable('email_verified_at'))->toBeFalse('email_verified_at should not be fillable');
        expect($user->isFillable('remember_token'))->toBeFalse('remember_token should not be fillable');
        expect($user->isFillable('two_factor_secret'))->toBeFalse('two_factor_secret should not be fillable');
        expect($user->isFillable('two_factor_recovery_codes'))->toBeFalse('two_factor_recovery_codes should not be fillable');
    });

    test('User model allows mass assignment of profile fields', function () {
        $user = new User;

        expect($user->isFillable('name'))->toBeTrue();
        expect($user->isFillable('email'))->toBeTrue();
        expect($user->isFillable('password'))->toBeTrue();
    });

    test('Team model blocks mass assignment of internal fields', function () {
        $team = new Team;

        expect($team->isFillable('id'))->toBeFalse();
        expect($team->isFillable('personal_team'))->toBeFalse('personal_team should not be fillable');
    });

    test('Service model blocks mass assignment of identity fields', function () {
        $service = new Service;

        expect($service->isFillable('id'))->toBeFalse();
        expect($service->isFillable('uuid'))->toBeFalse();
    });

    test('Service model allows mass assignment of relationship fields needed for create()', function () {
        $service = new Service;

        expect($service->isFillable('environment_id'))->toBeTrue();
        expect($service->isFillable('destination_id'))->toBeTrue();
        expect($service->isFillable('destination_type'))->toBeTrue();
        expect($service->isFillable('server_id'))->toBeTrue();
    });

    test('standalone database models block mass assignment of identity and relationship fields', function () {
        $models = [
            StandalonePostgresql::class,
            StandaloneRedis::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass;

            expect($model->isFillable('id'))
                ->toBeFalse("{$modelClass} should not allow mass assignment of 'id'");
            expect($model->isFillable('uuid'))
                ->toBeFalse("{$modelClass} should not allow mass assignment of 'uuid'");
            expect($model->isFillable('environment_id'))
                ->toBeFalse("{$modelClass} should not allow mass assignment of 'environment_id'");
            expect($model->isFillable('destination_id'))
                ->toBeFalse("{$modelClass} should not allow mass assignment of 'destination_id'");
            expect($model->isFillable('destination_type'))
                ->toBeFalse("{$modelClass} should not allow mass assignment of 'destination_type'");
        }
    });

    test('standalone database models allow mass assignment of config fields', function () {
        $model = new StandalonePostgresql;
        expect($model->isFillable('name'))->toBeTrue();
        expect($model->isFillable('postgres_user'))->toBeTrue();
        expect($model->isFillable('postgres_password'))->toBeTrue();
        expect($model->isFillable('image'))->toBeTrue();
        expect($model->isFillable('limits_memory'))->toBeTrue();

        $model = new StandaloneRedis;
        expect($model->isFillable('redis_conf'))->toBeTrue();

        $model = new StandaloneMysql;
        expect($model->isFillable('mysql_root_password'))->toBeTrue();

        $model = new StandaloneMongodb;
        expect($model->isFillable('mongo_initdb_root_username'))->toBeTrue();
    });

    test('standalone database models allow mass assignment of public_port_timeout', function () {
        $models = [
            StandalonePostgresql::class,
            StandaloneRedis::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass;
            expect($model->isFillable('public_port_timeout'))
                ->toBeTrue("{$modelClass} should allow mass assignment of 'public_port_timeout'");
        }
    });

    test('standalone database models allow mass assignment of SSL fields where applicable', function () {
        // Models with enable_ssl
        $sslModels = [
            StandalonePostgresql::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneRedis::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
        ];

        foreach ($sslModels as $modelClass) {
            $model = new $modelClass;
            expect($model->isFillable('enable_ssl'))
                ->toBeTrue("{$modelClass} should allow mass assignment of 'enable_ssl'");
        }

        // Clickhouse has no SSL columns
        expect((new StandaloneClickhouse)->isFillable('enable_ssl'))->toBeFalse();

        // Models with ssl_mode
        $sslModeModels = [
            StandalonePostgresql::class,
            StandaloneMysql::class,
            StandaloneMongodb::class,
        ];

        foreach ($sslModeModels as $modelClass) {
            $model = new $modelClass;
            expect($model->isFillable('ssl_mode'))
                ->toBeTrue("{$modelClass} should allow mass assignment of 'ssl_mode'");
        }
    });
});
