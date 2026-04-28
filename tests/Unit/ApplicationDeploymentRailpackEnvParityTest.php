<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Server;

it('generates escaped railpack env args from resolved values and includes install command', function () {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('getAttribute')->with('install_command')->andReturn('npm ci && npm run postinstall');

    $nodeVersion = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $nodeVersion->forceFill([
        'key' => 'RAILPACK_NODE_VERSION',
        'is_literal' => false,
        'is_multiline' => false,
    ]);
    $nodeVersion->shouldReceive('getResolvedValueWithServer')->once()->with(Mockery::type(Server::class))->andReturn('22');

    $literalValue = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $literalValue->forceFill([
        'key' => 'RAILPACK_CUSTOM_FLAG',
        'is_literal' => true,
        'is_multiline' => false,
    ]);
    $literalValue->shouldReceive('getResolvedValueWithServer')->once()->with(Mockery::type(Server::class))->andReturn("'hello world'");

    $jsonValue = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $jsonValue->forceFill([
        'key' => 'RAILPACK_JSON',
        'is_literal' => false,
        'is_multiline' => false,
    ]);
    $jsonValue->shouldReceive('getResolvedValueWithServer')->once()->with(Mockery::type(Server::class))->andReturn('{"token":"abc"}');

    $nullValue = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $nullValue->forceFill([
        'key' => 'RAILPACK_NULL',
        'is_literal' => false,
        'is_multiline' => false,
    ]);
    $nullValue->shouldReceive('getResolvedValueWithServer')->once()->with(Mockery::type(Server::class))->andReturn(null);

    $application->shouldReceive('getAttribute')
        ->with('railpack_environment_variables')
        ->andReturn(collect([$nodeVersion, $literalValue, $jsonValue, $nullValue]));

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $application);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 0);

    $mainServerProperty = $reflection->getProperty('mainServer');
    $mainServerProperty->setAccessible(true);
    $mainServerProperty->setValue($job, Mockery::mock(Server::class));

    $method = $reflection->getMethod('generate_railpack_env_variables');
    $method->setAccessible(true);
    $variables = $method->invoke($job);

    $envArgsProperty = $reflection->getProperty('env_railpack_args');
    $envArgsProperty->setAccessible(true);
    $envArgs = $envArgsProperty->getValue($job);

    expect($variables->all())->toBe([
        'RAILPACK_NODE_VERSION' => '22',
        'RAILPACK_CUSTOM_FLAG' => 'hello world',
        'RAILPACK_JSON' => '{"token":"abc"}',
        'RAILPACK_INSTALL_CMD' => 'npm ci && npm run postinstall',
    ]);
    expect($envArgs)->toContain("--env 'RAILPACK_NODE_VERSION=22'");
    expect($envArgs)->toContain("--env 'RAILPACK_CUSTOM_FLAG=hello world'");
    expect($envArgs)->toContain("--env 'RAILPACK_JSON={\"token\":\"abc\"}'");
    expect($envArgs)->toContain("--env 'RAILPACK_INSTALL_CMD=npm ci && npm run postinstall'");
    expect($envArgs)->not->toContain('RAILPACK_NULL');
});

it('uses preview railpack environment variables for preview deployments', function () {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('getAttribute')->with('install_command')->andReturn(null);

    $previewValue = Mockery::mock(EnvironmentVariable::class)->makePartial();
    $previewValue->forceFill([
        'key' => 'RAILPACK_PREVIEW_ONLY',
        'is_literal' => false,
        'is_multiline' => false,
    ]);
    $previewValue->shouldReceive('getResolvedValueWithServer')->once()->with(Mockery::type(Server::class))->andReturn('preview-value');

    $application->shouldReceive('getAttribute')
        ->with('railpack_environment_variables_preview')
        ->andReturn(collect([$previewValue]));

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $applicationProperty->setValue($job, $application);

    $pullRequestProperty = $reflection->getProperty('pull_request_id');
    $pullRequestProperty->setAccessible(true);
    $pullRequestProperty->setValue($job, 42);

    $mainServerProperty = $reflection->getProperty('mainServer');
    $mainServerProperty->setAccessible(true);
    $mainServerProperty->setValue($job, Mockery::mock(Server::class));

    $method = $reflection->getMethod('generate_railpack_env_variables');
    $method->setAccessible(true);
    $variables = $method->invoke($job);

    expect($variables->all())->toBe([
        'RAILPACK_PREVIEW_ONLY' => 'preview-value',
    ]);
});
