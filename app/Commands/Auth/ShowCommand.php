<?php

namespace App\Commands\Auth;

use App\Enums\GitHost;
use App\Services\AuthService;
use App\Services\HostClientFactory;
use JeffersonGoncalves\LaravelZero\ApiClient\ApiException;
use JeffersonGoncalves\LaravelZero\Console\FormatsOutput;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class ShowCommand extends Command
{
    use FormatsOutput;

    protected $signature = 'auth:show {--verify : Ping each authenticated host to confirm the saved credential still works}';

    protected $description = 'Show authentication status for every git host';

    public function handle(AuthService $authService, HostClientFactory $factory): int
    {
        $credentials = $authService->load();
        $verify = (bool) $this->option('verify');

        $rows = array_map(
            fn (GitHost $host) => $this->rowFor($host, $credentials?->forHost($host), $verify, $factory),
            GitHost::cases(),
        );

        $this->renderTable(['Host', 'Status', 'Account'], $rows);
        $this->components->twoColumnDetail('Config Path', $authService->getConfigPath());

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>|null  $data
     * @return array{0: string, 1: string, 2: string}
     */
    protected function rowFor(GitHost $host, ?array $data, bool $verify, HostClientFactory $factory): array
    {
        if (! $data) {
            return [$host->label(), $this->colorize('not authenticated', 'gray'), '-'];
        }

        if (! $verify) {
            return [$host->label(), $this->colorize('authenticated', 'green'), $data['username'] ?? '-'];
        }

        try {
            $username = $factory->make($host)->currentUsername();

            return [$host->label(), $this->colorize('valid', 'green'), $username];
        } catch (ApiException|Throwable $e) {
            $message = $e instanceof ApiException ? $e->getMessage() : 'request failed';

            return [$host->label(), $this->colorize("invalid ({$message})", 'red'), $data['username'] ?? '-'];
        }
    }
}
