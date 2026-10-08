<?php

use App\DTOs\Finding;
use App\DTOs\Repo;
use App\Services\CatalogAuditor;

function catalogRepo(string $name, bool $archived = false): Repo
{
    return new Repo(owner: 'acme', name: $name, sshUrl: "git@github.com:acme/{$name}.git", private: false, archived: $archived);
}

it('flattens nested categories and keeps the repo override', function () {
    $entries = CatalogAuditor::entries([
        'startkit' => ['featured' => [['title' => 'Kit', 'package' => 'acme/kit']]],
        'cakephp' => [['title' => 'Cake', 'package' => 'other/cake', 'repo' => 'acme/cake']],
    ]);

    expect($entries)->toBe([
        ['category' => 'startkit', 'title' => 'Kit', 'package' => 'acme/kit', 'repo' => null],
        ['category' => 'cakephp', 'title' => 'Cake', 'package' => 'other/cake', 'repo' => 'acme/cake'],
    ]);
});

it('reports missing or archived repos, unpublished packages and uncatalogued packages', function () {
    $packagist = fn (string $vendor) => [
        'acme' => ['acme/filament-ok', 'acme/filament-archived', 'acme/forgotten', 'acme/retired'],
        'other' => [],
    ][$vendor];

    $catalog = [
        'filament' => ['plugins' => [
            ['title' => 'OK', 'package' => 'acme/filament-ok'],
            ['title' => 'Archived', 'package' => 'acme/filament-archived'],
            ['title' => 'Gone', 'package' => 'acme/filament-gone'],
        ]],
        'cakephp' => [['title' => 'Cake', 'package' => 'other/cake', 'repo' => 'acme/cake']],
        'vscode' => [['title' => 'Ext', 'package' => 'acme/ext-vscode']],
    ];

    $repos = [catalogRepo('filament-ok'), catalogRepo('filament-archived', archived: true), catalogRepo('cake'), catalogRepo('ext-vscode'), catalogRepo('retired', archived: true)];

    $findings = array_map(fn (Finding $f) => [$f->repo, $f->message], (new CatalogAuditor($packagist))->audit($catalog, $repos, 'acme'));

    expect($findings)->toBe([
        ['acme/filament-archived', 'Archived: GitHub repo is archived'],
        ['acme/filament-gone', 'Gone: no GitHub repo acme/filament-gone'],
        ['acme/filament-gone', 'Gone: not published on Packagist'],
        ['other/cake', 'Cake: not published on Packagist'],
        ['acme/forgotten', 'Published on Packagist but missing from the catalog'],
    ]);
});
