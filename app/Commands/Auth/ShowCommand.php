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

        $rows = collect(GitHost::cases())
            ->flatMap(function (GitHost $host) use ($credentials, $verify, $factory) {
                $profiles = $credentials?->profilesFor($host) ?? [];

                if ($profiles === []) {
                    return [[$host->label(), '-', $this->colorize('not authenticated', 'gray'), '-']];
                }

                return collect($profiles)->map(
                    fn (array $data, string $profile) => $this->rowFor($host, $profile, $data, $verify, $factory)
                )->values()->all();
            })
            ->all();

        $this->renderTable(['Host', 'Profile', 'Status', 'Account'], $rows);
        $this->components->twoColumnDetail('Config Path', $authService->getConfigPath());

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $data
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    protected function rowFor(GitHost $host, string $profile, array $data, bool $verify, HostClientFactory $factory): array
    {
        if (! $verify) {
            return [$host->label(), $profile, $this->colorize('authenticated', 'green'), $data['username'] ?? '-'];
        }

        try {
            $username = $factory->make($host, $profile)->currentUsername();

            return [$host->label(), $profile, $this->colorize('valid', 'green'), $username];
        } catch (ApiException|Throwable $e) {
            $message = $e instanceof ApiException ? $e->getMessage() : 'request failed';

            return [$host->label(), $profile, $this->colorize("invalid ({$message})", 'red'), $data['username'] ?? '-'];
        }
    }
}
