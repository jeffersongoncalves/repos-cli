<?php

use App\DTOs\Repo;
use App\Services\GitOperationsService;
use App\Services\HostClientFactory;
use App\Services\Hosts\GithubClient;
use App\Services\RepoAuditor;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * GithubClient backed by canned responses; $history collects the requests made.
 *
 * @param  list<Response>  $responses
 */
function githubClient(array $responses, array &$history = []): GithubClient
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new GithubClient('token', new Client(['handler' => $stack]));
}

function json(mixed $data, int $status = 200): Response
{
    return new Response($status, [], json_encode($data));
}

function gitWith(bool $cloned): GitOperationsService
{
    $git = Mockery::mock(GitOperationsService::class);
    $git->shouldReceive('isGitRepo')->andReturn($cloned);

    return $git;
}

function repo(array $overrides = []): Repo
{
    return new Repo(...array_merge([
        'owner' => 'acme',
        'name' => 'filament-widget',
        'sshUrl' => 'git@github.com:acme/filament-widget.git',
        'private' => false,
        'defaultBranch' => '3.x',
        'description' => 'A widget',
        'homepage' => null,
    ], $overrides));
}

it('reports nothing for a healthy repo', function () {
    $client = githubClient([
        json([['name' => '1.x'], ['name' => '2.x'], ['name' => '3.x']]),      // branches
        json(['content' => base64_encode('version: 2')]),                    // dependabot.yml
        json(['content' => base64_encode("name: automerge\n")]),              // dependabot-auto-merge.yml
        json(['workflow_runs' => [['name' => 'Tests', 'head_sha' => 'h1', 'conclusion' => 'success', 'html_url' => 'u']]]),
    ]);

    $auditor = new RepoAuditor($client, gitWith(true), RepoAuditor::CHECKS, '/code', "name: automerge\r\n");

    expect($auditor->audit(repo()))->toBe([]);
});

it('reports every problem of a neglected repo', function () {
    $client = githubClient([
        json([['name' => 'main'], ['name' => '1.x'], ['name' => '2.x']]),    // branches -> expects 2.x
        json(['message' => 'Not Found'], 404),                                // no dependabot.yml
        json(['content' => base64_encode('merges: everything')]),             // custom automerge
        json(['workflow_runs' => [
            ['name' => 'Tests', 'head_sha' => 'h2', 'conclusion' => 'failure', 'html_url' => 'https://ci/run/1'],
            ['name' => 'PHPStan', 'head_sha' => 'h2', 'conclusion' => 'success', 'html_url' => 'https://ci/run/2'],
        ]]),
    ]);

    $auditor = new RepoAuditor($client, gitWith(false), RepoAuditor::CHECKS, '/code', 'name: automerge');
    $findings = $auditor->audit(repo([
        'defaultBranch' => 'main',
        'description' => '',
        'homepage' => 'https://packagist.org/packages/acme/filament-widget',
    ]));

    expect(array_map(fn ($f) => $f->check, $findings))
        ->toBe(['clone', 'website', 'description', 'branch', 'dependabot', 'automerge', 'ci'])
        ->and($findings[3]->message)->toBe('Default branch is main, expected 2.x')
        ->and($findings[6]->message)->toContain('https://ci/run/1');
});

it('checks CI on the newest commit only, so an old fixed failure is not reported', function () {
    $history = [];
    $client = githubClient([
        json(['workflow_runs' => [
            ['name' => 'Tests', 'head_sha' => 'new', 'conclusion' => 'success', 'html_url' => 'u1'],
            ['name' => 'Tests', 'head_sha' => 'old', 'conclusion' => 'failure', 'html_url' => 'u2'],
        ]]),
    ], $history);

    expect((new RepoAuditor($client, gitWith(true), ['ci']))->audit(repo()))->toBe([])
        ->and($history)->toHaveCount(1)
        ->and((string) $history[0]['request']->getUri())->toContain('branch=3.x')
        ->and((string) $history[0]['request']->getUri())->not->toContain('status=');
});

it('falls back to the public listing when the token cannot read /user', function () {
    $client = githubClient([
        json(['message' => 'Resource not accessible by integration'], 403),   // GET user (GITHUB_TOKEN)
        json(['message' => 'Not Found'], 404),                                // orgs/acme/repos
        json([['name' => 'pub', 'owner' => ['login' => 'acme'], 'ssh_url' => 's']]),
    ]);

    expect(array_map(fn ($r) => $r->name, $client->listRepos('acme')))->toBe(['pub']);
});

it('only calls the API for the selected checks', function () {
    $history = [];
    $client = githubClient([json(['content' => base64_encode('version: 2')])], $history);

    $findings = (new RepoAuditor($client, gitWith(true), ['description', 'dependabot']))->audit(repo(['description' => null]));

    expect(array_map(fn ($f) => $f->check, $findings))->toBe(['description'])
        ->and($history)->toHaveCount(1)
        ->and((string) $history[0]['request']->getUri())->toContain('contents/.github/dependabot.yml');
});

it('lists only the Dependabot pull requests whose checks failed', function () {
    $client = githubClient([
        json(['items' => [
            ['number' => 4, 'title' => 'Bump typescript', 'html_url' => 'https://pr/4', 'repository_url' => 'https://api.github.com/repos/acme/ext'],
            ['number' => 7, 'title' => 'Bump actions', 'html_url' => 'https://pr/7', 'repository_url' => 'https://api.github.com/repos/acme/lib'],
        ]]),
        json(['head' => ['sha' => 'aaa']]),
        json(['check_runs' => [['conclusion' => 'success'], ['conclusion' => 'failure']]]),
        json(['head' => ['sha' => 'bbb']]),
        json(['check_runs' => [['conclusion' => 'success'], ['conclusion' => 'skipped']]]),
    ]);

    expect($client->failingDependabotPulls('acme'))->toBe([
        ['repo' => 'acme/ext', 'number' => 4, 'title' => 'Bump typescript', 'url' => 'https://pr/4'],
    ]);
});

it('audits an owner from the command and fails when there are findings', function () {
    $client = githubClient([
        json(['login' => 'someone-else']),                                    // currentUsername
        json(['message' => 'Not Found'], 404),                                // orgs/acme/repos
        json([                                                                // users/acme/repos
            ['name' => 'clean', 'owner' => ['login' => 'acme'], 'ssh_url' => 's', 'default_branch' => 'main', 'description' => 'ok'],
            ['name' => 'messy', 'owner' => ['login' => 'acme'], 'ssh_url' => 's', 'default_branch' => 'main', 'description' => ''],
            ['name' => 'old', 'owner' => ['login' => 'acme'], 'ssh_url' => 's', 'default_branch' => 'main', 'archived' => true],
        ]),
    ]);

    $factory = Mockery::mock(HostClientFactory::class);
    $factory->shouldReceive('make')->andReturn($client);
    $this->app->instance(HostClientFactory::class, $factory);

    $this->artisan('audit', ['owner' => 'acme', '--only' => 'description,website', '--json' => true])
        ->expectsOutputToContain('"repo": "acme/messy"')
        ->doesntExpectOutputToContain('acme/old')
        ->doesntExpectOutputToContain('0%') // no progress bar mixed into the JSON
        ->assertExitCode(1);
});

it('skips excluded repos', function () {
    $client = githubClient([
        json(['login' => 'acme']),                                            // currentUsername == owner
        json([                                                                // user/repos
            ['name' => 'legacy', 'owner' => ['login' => 'acme'], 'ssh_url' => 's', 'default_branch' => 'main', 'description' => ''],
            ['name' => 'Other', 'owner' => ['login' => 'acme'], 'ssh_url' => 's', 'default_branch' => 'main', 'description' => ''],
        ]),
    ]);

    $factory = Mockery::mock(HostClientFactory::class);
    $factory->shouldReceive('make')->andReturn($client);
    $this->app->instance(HostClientFactory::class, $factory);

    $this->artisan('audit', ['owner' => 'acme', '--only' => 'description', '--exclude' => 'LEGACY, other', '--json' => true])
        ->expectsOutputToContain('[]')
        ->assertExitCode(0);
});

it('retries a dropped connection or a 5xx before giving up', function () {
    $client = githubClient([
        json(['message' => 'Bad Gateway'], 502),
        json(['content' => base64_encode('version: 2')]),
    ]);

    expect($client->fileContent('acme', 'lib', '.github/dependabot.yml'))->toBe('version: 2');
});

it('reports a repo it could not audit and keeps going', function () {
    $client = githubClient([
        json(['login' => 'acme']),
        json([
            ['name' => 'flaky', 'owner' => ['login' => 'acme'], 'ssh_url' => 's', 'default_branch' => 'main', 'description' => 'x'],
            ['name' => 'fine', 'owner' => ['login' => 'acme'], 'ssh_url' => 's', 'default_branch' => 'main', 'description' => 'x'],
        ]),
        json(['message' => 'Forbidden'], 403),                                // flaky: dependabot.yml
        json(['content' => base64_encode('version: 2')]),                    // fine: dependabot.yml
    ]);

    $factory = Mockery::mock(HostClientFactory::class);
    $factory->shouldReceive('make')->andReturn($client);
    $this->app->instance(HostClientFactory::class, $factory);

    $this->artisan('audit', ['owner' => 'acme', '--only' => 'dependabot', '--json' => true])
        ->expectsOutputToContain('"check": "error"')
        ->doesntExpectOutputToContain('acme/fine')
        ->assertExitCode(1);
});

it('rejects unknown checks', function () {
    $factory = Mockery::mock(HostClientFactory::class);
    $factory->shouldReceive('make')->andReturn(githubClient([]));
    $this->app->instance(HostClientFactory::class, $factory);

    $this->artisan('audit', ['owner' => 'acme', '--only' => 'spelling'])
        ->expectsOutputToContain("Unknown check 'spelling'")
        ->assertExitCode(1);
});
