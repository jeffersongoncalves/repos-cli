<?php

namespace App\Commands\Auth;

use App\Enums\GitHost;
use App\Services\AuthService;
use JeffersonGoncalves\LaravelZero\Console\FormatsOutput;
use LaravelZero\Framework\Commands\Command;

class ShowCommand extends Command
{
    use FormatsOutput;

    protected $signature = 'auth:show';

    protected $description = 'Show authentication status for every git host';

    public function handle(AuthService $authService): int
    {
        $credentials = $authService->load();

        $rows = array_map(function (GitHost $host) use ($credentials) {
            $data = $credentials?->forHost($host);

            return [
                $host->label(),
                $data ? $this->colorize('authenticated', 'green') : $this->colorize('not authenticated', 'gray'),
                $data['username'] ?? '-',
            ];
        }, GitHost::cases());

        $this->renderTable(['Host', 'Status', 'Account'], $rows);
        $this->components->twoColumnDetail('Config Path', $authService->getConfigPath());

        return self::SUCCESS;
    }
}
