<?php

use App\Concerns\RunsTasks;
use LaravelZero\Framework\Commands\Command;

it('renders FAIL and returns false when the callback returns false', function () {
    $command = new class extends Command
    {
        use RunsTasks;

        public function handle(): int
        {
            $ok = $this->runTask('doing thing', fn () => false);

            $this->components->info($ok ? 'ok-true' : 'ok-false');

            return self::SUCCESS;
        }
    };

    $this->artisan($command)
        ->expectsOutputToContain('FAIL')
        ->expectsOutputToContain('ok-false')
        ->assertExitCode(0);
});

it('renders DONE and returns true when the callback returns true', function () {
    $command = new class extends Command
    {
        use RunsTasks;

        public function handle(): int
        {
            $ok = $this->runTask('doing thing', fn () => true);

            $this->components->info($ok ? 'ok-true' : 'ok-false');

            return self::SUCCESS;
        }
    };

    $this->artisan($command)
        ->expectsOutputToContain('DONE')
        ->expectsOutputToContain('ok-true')
        ->assertExitCode(0);
});
