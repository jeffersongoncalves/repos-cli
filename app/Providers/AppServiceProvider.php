<?php

namespace App\Providers;

use App\Services\AuthService;
use App\Services\GitOperationsService;
use App\Services\HostClientFactory;
use Illuminate\Support\ServiceProvider;
use JeffersonGoncalves\LaravelZero\SelfUpdate\PharUpdater;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }

    public function register(): void
    {
        $this->app->singleton(AuthService::class);
        $this->app->singleton(HostClientFactory::class);
        $this->app->singleton(GitOperationsService::class);

        $this->app->singleton(PharUpdater::class, fn () => new PharUpdater(
            githubRepo: 'jeffersongoncalves/repos-cli',
            assetName: 'repos.phar',
            tempPrefix: 'repos_',
            currentVersion: (string) config('app.version', 'unreleased'),
        ));
    }
}
