<?php

use App\Services\GitOperationsService;
use Illuminate\Support\Facades\Process;

it('clones with submodules', function () {
    Process::fake();

    (new GitOperationsService)->clone('git@github.com:acme/widgets.git', '/tmp/widgets');

    Process::assertRan(fn ($process) => $process->command === [
        'git', 'clone', '--recurse-submodules', 'git@github.com:acme/widgets.git', '/tmp/widgets',
    ]);
});

it('pulls with submodules', function () {
    Process::fake();

    (new GitOperationsService)->pull('/tmp/widgets');

    Process::assertRan(fn ($process) => $process->command === [
        'git', 'pull', '--all', '--recurse-submodules',
    ]);
});
