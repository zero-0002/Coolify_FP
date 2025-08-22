<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\Server::class => \App\Policies\ServerPolicy::class,
        \App\Models\PrivateKey::class => \App\Policies\PrivateKeyPolicy::class,
        \App\Models\StandaloneDocker::class => \App\Policies\StandaloneDockerPolicy::class,
        \App\Models\SwarmDocker::class => \App\Policies\SwarmDockerPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
