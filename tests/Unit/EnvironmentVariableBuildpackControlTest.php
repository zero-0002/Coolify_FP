<?php

use App\Models\EnvironmentVariable;

it('flags NIXPACKS_ keys as buildpack control variables', function () {
    $env = new EnvironmentVariable;
    $env->key = 'NIXPACKS_NODE_VERSION';

    expect($env->is_buildpack_control)->toBeTrue();
});

it('flags RAILPACK_ keys as buildpack control variables', function () {
    $env = new EnvironmentVariable;
    $env->key = 'RAILPACK_NODE_VERSION';

    expect($env->is_buildpack_control)->toBeTrue();
});

it('does not flag user-defined keys as buildpack control variables', function () {
    $env = new EnvironmentVariable;
    $env->key = 'MY_BUILD_VAR';

    expect($env->is_buildpack_control)->toBeFalse();
});

it('does not flag empty key as buildpack control variable', function () {
    $env = new EnvironmentVariable;

    expect($env->is_buildpack_control)->toBeFalse();
});

it('lists is_buildpack_control in appends and drops legacy is_nixpacks', function () {
    $env = new EnvironmentVariable;

    expect($env->getAppends())->toContain('is_buildpack_control');
    expect($env->getAppends())->not->toContain('is_nixpacks');
});
