<?php

use App\Models\StandalonePostgresql;

it('defaults to an enabled healthcheck when nothing is configured', function () {
    $database = new StandalonePostgresql;

    expect($database->isHealthcheckEnabled())->toBeTrue();
});

it('builds the compose healthcheck block from the model timing fields', function () {
    $database = new StandalonePostgresql([
        'health_check_interval' => 30,
        'health_check_timeout' => 7,
        'health_check_retries' => 4,
        'health_check_start_period' => 12,
    ]);

    $config = $database->healthCheckConfiguration(['CMD', 'pg_isready']);

    expect($config)->toBe([
        'test' => ['CMD', 'pg_isready'],
        'interval' => '30s',
        'timeout' => '7s',
        'retries' => 4,
        'start_period' => '12s',
    ]);
});

it('falls back to safe defaults when timing fields are missing', function () {
    $database = new StandalonePostgresql;

    $config = $database->healthCheckConfiguration(['CMD', 'pg_isready']);

    expect($config['interval'])->toBe('15s')
        ->and($config['timeout'])->toBe('5s')
        ->and($config['retries'])->toBe(5)
        ->and($config['start_period'])->toBe('5s');
});

it('reports the healthcheck as disabled when the flag is false', function () {
    $database = new StandalonePostgresql(['health_check_enabled' => false]);

    expect($database->isHealthcheckEnabled())->toBeFalse();
});
