<?php

use App\Services\GitOperationsService;
use Illuminate\Support\Facades\File;

it('renders FAIL for a failed repo and DONE for a successful one, and counts the failure', function () {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'repos-cli-test-'.uniqid();
    mkdir($root.DIRECTORY_SEPARATOR.'repo-ok'.DIRECTORY_SEPARATOR.'.git', 0777, true);
    mkdir($root.DIRECTORY_SEPARATOR.'repo-bad'.DIRECTORY_SEPARATOR.'.git', 0777, true);

    $git = Mockery::mock(GitOperationsService::class);
    $git->shouldReceive('isGitRepo')->andReturn(true);
    $git->shouldReceive('pull')->withArgs(fn ($path) => str_contains($path, 'repo-ok'))->andReturn(true);
    $git->shouldReceive('pull')->withArgs(fn ($path) => str_contains($path, 'repo-bad'))->andReturn(false);
    $this->app->instance(GitOperationsService::class, $git);

    $this->artisan('pull', ['path' => $root])
        ->expectsOutputToContain('FAIL')
        ->assertExitCode(1);

    File::deleteDirectory($root);
});
