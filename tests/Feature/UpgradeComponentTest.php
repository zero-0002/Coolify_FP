<?php

use App\Livewire\Upgrade;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

it('initializes latest version during mount from cached versions data', function () {
    config(['constants.coolify.version' => '4.0.0-beta.998']);

    Cache::shouldReceive('remember')
        ->once()
        ->with('coolify:versions:all', 3600, Mockery::type(\Closure::class))
        ->andReturn([
            'coolify' => [
                'v4' => [
                    'version' => '4.0.0-beta.999',
                ],
            ],
        ]);

    Livewire::test(Upgrade::class)
        ->assertSet('currentVersion', '4.0.0-beta.998')
        ->assertSet('latestVersion', '4.0.0-beta.999')
        ->set('isUpgradeAvailable', true)
        ->assertSee('4.0.0-beta.998')
        ->assertSee('4.0.0-beta.999');
});

it('falls back to 0.0.0 during mount when cached versions data is unavailable', function () {
    Cache::shouldReceive('remember')
        ->once()
        ->with('coolify:versions:all', 3600, Mockery::type(\Closure::class))
        ->andReturn(null);

    Livewire::test(Upgrade::class)
        ->assertSet('latestVersion', '0.0.0');
});
