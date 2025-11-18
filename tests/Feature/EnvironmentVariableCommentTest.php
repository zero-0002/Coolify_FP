<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->application = Application::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->actingAs($this->user);
});

test('environment variable can be created with comment', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'comment' => 'This is a test environment variable',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($env->comment)->toBe('This is a test environment variable');
    expect($env->key)->toBe('TEST_VAR');
    expect($env->value)->toBe('test_value');
});

test('environment variable comment is optional', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($env->comment)->toBeNull();
    expect($env->key)->toBe('TEST_VAR');
});

test('environment variable comment can be updated', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'comment' => 'Initial comment',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $env->comment = 'Updated comment';
    $env->save();

    $env->refresh();
    expect($env->comment)->toBe('Updated comment');
});

test('environment variable comment is preserved when updating value', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'initial_value',
        'comment' => 'Important variable for testing',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $env->value = 'new_value';
    $env->save();

    $env->refresh();
    expect($env->value)->toBe('new_value');
    expect($env->comment)->toBe('Important variable for testing');
});

test('environment variable comment is copied to preview environment', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'comment' => 'Test comment',
        'is_preview' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // The model's booted() method should create a preview version
    $previewEnv = EnvironmentVariable::where('key', 'TEST_VAR')
        ->where('resourceable_id', $this->application->id)
        ->where('is_preview', true)
        ->first();

    expect($previewEnv)->not->toBeNull();
    expect($previewEnv->comment)->toBe('Test comment');
});

test('parseEnvFormatToArray preserves values without inline comments', function () {
    $input = "KEY1=value1\nKEY2=value2";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => 'value1', 'comment' => null],
        'KEY2' => ['value' => 'value2', 'comment' => null],
    ]);
});

test('developer view format does not break with comment-like values', function () {
    // Values that contain # but shouldn't be treated as comments when quoted
    $env1 = EnvironmentVariable::create([
        'key' => 'HASH_VAR',
        'value' => 'value_with_#_in_it',
        'comment' => 'Contains hash symbol',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($env1->value)->toBe('value_with_#_in_it');
    expect($env1->comment)->toBe('Contains hash symbol');
});
