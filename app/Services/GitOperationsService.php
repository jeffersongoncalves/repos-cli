<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class GitOperationsService
{
    public function clone(string $url, string $destination): bool
    {
        return Process::timeout(300)->run(['git', 'clone', '--recurse-submodules', $url, $destination])->successful();
    }

    public function pull(string $path): bool
    {
        return Process::path($path)->timeout(300)->run(['git', 'pull', '--all', '--recurse-submodules'])->successful();
    }

    public function isGitRepo(string $path): bool
    {
        return is_dir($path.DIRECTORY_SEPARATOR.'.git');
    }
}
