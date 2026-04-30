<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;

describe('Sensitive model fields are hidden by default', function () {
    test('ServerSetting hides sentinel and logdrain secrets', function () {
        $hidden = (new ServerSetting)->getHidden();

        expect($hidden)->toContain(
            'sentinel_token',
            'sentinel_custom_url',
            'logdrain_newrelic_license_key',
            'logdrain_axiom_api_key',
            'logdrain_custom_config',
            'logdrain_custom_config_parser',
        );
    });

    test('Server hides logdrain api keys', function () {
        $hidden = (new Server)->getHidden();

        expect($hidden)->toContain(
            'logdrain_axiom_api_key',
            'logdrain_newrelic_license_key',
        );
    });

    test('Application hides webhook secrets, dockerfile, compose, and labels', function () {
        $hidden = (new Application)->getHidden();

        expect($hidden)->toContain(
            'http_basic_auth_password',
            'manual_webhook_secret_github',
            'manual_webhook_secret_gitlab',
            'manual_webhook_secret_bitbucket',
            'manual_webhook_secret_gitea',
            'dockerfile',
            'docker_compose',
            'docker_compose_raw',
            'custom_labels',
        );
    });

    test('EnvironmentVariable hides value and real_value', function () {
        $hidden = (new EnvironmentVariable)->getHidden();

        expect($hidden)->toContain('value', 'real_value');
    });

    test('Service hides docker_compose and docker_compose_raw', function () {
        $hidden = (new Service)->getHidden();

        expect($hidden)->toContain('docker_compose', 'docker_compose_raw');
    });

    test('StandalonePostgresql hides password, init_scripts, db urls', function () {
        $hidden = (new StandalonePostgresql)->getHidden();

        expect($hidden)->toContain(
            'postgres_password',
            'init_scripts',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneMysql hides passwords and db urls', function () {
        $hidden = (new StandaloneMysql)->getHidden();

        expect($hidden)->toContain(
            'mysql_password',
            'mysql_root_password',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneMariadb hides passwords and db urls', function () {
        $hidden = (new StandaloneMariadb)->getHidden();

        expect($hidden)->toContain(
            'mariadb_password',
            'mariadb_root_password',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneMongodb hides root password and db urls', function () {
        $hidden = (new StandaloneMongodb)->getHidden();

        expect($hidden)->toContain(
            'mongo_initdb_root_password',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneRedis hides password and db urls', function () {
        $hidden = (new StandaloneRedis)->getHidden();

        expect($hidden)->toContain(
            'redis_password',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneClickhouse hides password and db urls', function () {
        $hidden = (new StandaloneClickhouse)->getHidden();

        expect($hidden)->toContain(
            'clickhouse_admin_password',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneKeydb hides password and db urls', function () {
        $hidden = (new StandaloneKeydb)->getHidden();

        expect($hidden)->toContain(
            'keydb_password',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneDragonfly hides password and db urls', function () {
        $hidden = (new StandaloneDragonfly)->getHidden();

        expect($hidden)->toContain(
            'dragonfly_password',
            'internal_db_url',
            'external_db_url',
        );
    });
});

describe('Sensitive fields are absent from toArray() by default', function () {
    test('ServerSetting::toArray() excludes sentinel_custom_url by default', function () {
        $setting = new ServerSetting;
        $setting->setRawAttributes([
            'server_id' => 1,
            'sentinel_custom_url' => 'https://secret.example.com',
            'wildcard_domain' => 'public.example.com',
        ], sync: true);

        $array = $setting->toArray();

        expect($array)->not->toHaveKey('sentinel_custom_url');
        expect($array)->toHaveKey('wildcard_domain');
    });

    test('ServerSetting::toArray() includes sentinel_custom_url after makeVisible', function () {
        $setting = new ServerSetting;
        $setting->setRawAttributes([
            'server_id' => 1,
            'sentinel_custom_url' => 'https://secret.example.com',
        ], sync: true);

        $setting->makeVisible(['sentinel_custom_url']);
        $array = $setting->toArray();

        expect($array['sentinel_custom_url'])->toBe('https://secret.example.com');
    });
});
