<?php

namespace App\Commands;

use JeffersonGoncalves\LaravelZero\SelfUpdate\PharUpdater;
use JeffersonGoncalves\LaravelZero\SelfUpdate\SelfUpdateCommand as BaseSelfUpdateCommand;

class SelfUpdateCommand extends BaseSelfUpdateCommand
{
    protected $description = 'Update the repos CLI to the latest version';

    protected function githubRepo(): string
    {
        return 'jeffersongoncalves/repos-cli';
    }

    protected function assetName(): string
    {
        return 'repos.phar';
    }

    protected function tempPrefix(): string
    {
        return 'repos_';
    }

    protected function currentVersion(): string
    {
        return (string) config('app.version', 'unreleased');
    }

    protected function makeUpdater(): PharUpdater
    {
        return $this->getLaravel()->make(PharUpdater::class);
    }
}
