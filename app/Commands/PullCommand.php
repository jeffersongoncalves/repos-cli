<?php

namespace App\Commands;

use App\Services\GitOperationsService;
use Illuminate\Support\Facades\File;
use JeffersonGoncalves\LaravelZero\Console\ResolvesPath;
use JeffersonGoncalves\LaravelZero\Git\RepositoryResolver;
use LaravelZero\Framework\Commands\Command;

class PullCommand extends Command
{
    use ResolvesPath;

    protected $signature = 'pull {path? : Folder to scan for repos (default: current directory)}';

    protected $description = 'Run git pull --all on every repo found one level under a folder';

    public function handle(GitOperationsService $git): int
    {
        $root = $this->resolvePath($this->argument('path'));

        $repoDirs = collect(File::directories($root))
            ->filter(fn (string $dir) => $git->isGitRepo($dir))
            ->values();

        if ($repoDirs->isEmpty()) {
            $this->components->warn("No git repositories found directly under {$root}.");

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($repoDirs as $dir) {
            $label = (new RepositoryResolver($dir))->resolve()?->fullName() ?? basename($dir);
            $ok = $git->pull($dir);

            $this->components->task($label, fn () => $ok);

            if (! $ok) {
                $failures++;
            }
        }

        $this->components->info("{$repoDirs->count()} repos processed, {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
