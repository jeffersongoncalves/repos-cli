<?php

namespace App\Services;

use App\DTOs\Finding;
use App\DTOs\Repo;
use Closure;
use GuzzleHttp\Client;

/**
 * The `catalog` check of `repos audit`: a product catalog (the profile's plugins.json) against GitHub and Packagist.
 *
 * Every entry has a `title` and a `package` (`vendor/name`); `repo` overrides the GitHub path when the
 * Packagist vendor differs from the GitHub owner.
 */
class CatalogAuditor
{
    /** Catalog categories whose `package` is a Composer package. */
    public const PACKAGIST_CATEGORIES = ['startkit', 'filament', 'laravel', 'laravelZero', 'cli', 'cakephp'];

    /** @var Closure(string): list<string> */
    protected Closure $packagistNames;

    /** @var array<string, list<string>> */
    protected array $vendorCache = [];

    /**
     * @param  (Closure(string): list<string>)|null  $packagistNames  package names published by a vendor
     */
    public function __construct(?Closure $packagistNames = null)
    {
        $this->packagistNames = $packagistNames ?? function (string $vendor): array {
            $response = (new Client(['timeout' => 30]))->get('https://packagist.org/packages/list.json', ['query' => ['vendor' => $vendor]]);
            $names = json_decode((string) $response->getBody(), true)['packageNames'] ?? [];

            return array_values(array_map('strtolower', $names));
        };
    }

    /**
     * @param  array<string, mixed>  $catalog  decoded plugins.json
     * @param  list<Repo>  $repos  every repo of $owner, archived ones included
     * @return list<Finding>
     */
    public function audit(array $catalog, array $repos, string $owner): array
    {
        $findings = [];
        $byName = [];
        foreach ($repos as $repo) {
            $byName[strtolower($repo->name)] = $repo;
        }

        $catalogued = [];
        foreach (self::entries($catalog) as $entry) {
            $package = strtolower($entry['package']);
            $catalogued[$package] = true;

            [$repoOwner, $repoName] = explode('/', strtolower($entry['repo'] ?? $entry['package']), 2) + [1 => ''];
            if ($repoOwner === strtolower($owner)) {
                $repo = $byName[$repoName] ?? null;
                if ($repo === null) {
                    $findings[] = new Finding($entry['package'], 'catalog', "{$entry['title']}: no GitHub repo {$repoOwner}/{$repoName}");
                } elseif ($repo->archived) {
                    $findings[] = new Finding($entry['package'], 'catalog', "{$entry['title']}: GitHub repo is archived");
                }
            }

            if (in_array($entry['category'], self::PACKAGIST_CATEGORIES, true)
                && ! in_array($package, $this->published(explode('/', $package)[0]), true)) {
                $findings[] = new Finding($entry['package'], 'catalog', "{$entry['title']}: not published on Packagist");
            }
        }

        foreach ($this->published(strtolower($owner)) as $package) {
            // An archived repo is a package retired on purpose: it doesn't belong in the catalog.
            $retired = isset($byName[$name = explode('/', $package, 2)[1] ?? '']) && $byName[$name]->archived;
            if (! isset($catalogued[$package]) && ! $retired) {
                $findings[] = new Finding($package, 'catalog', 'Published on Packagist but missing from the catalog');
            }
        }

        return $findings;
    }

    /**
     * Flattens the catalog: every object with a `title` and a `package`, tagged with its top-level category.
     *
     * @param  array<string, mixed>  $catalog
     * @return list<array{category: string, title: string, package: string, repo: ?string}>
     */
    public static function entries(array $catalog): array
    {
        $entries = [];
        $walk = function (mixed $node, string $category) use (&$walk, &$entries): void {
            if (! is_array($node)) {
                return;
            }

            if (isset($node['title'], $node['package']) && is_string($node['package'])) {
                $entries[] = [
                    'category' => $category,
                    'title' => (string) $node['title'],
                    'package' => $node['package'],
                    'repo' => isset($node['repo']) && is_string($node['repo']) ? $node['repo'] : null,
                ];

                return;
            }

            foreach ($node as $child) {
                $walk($child, $category);
            }
        };

        foreach ($catalog as $category => $node) {
            $walk($node, (string) $category);
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    protected function published(string $vendor): array
    {
        return $this->vendorCache[$vendor] ??= ($this->packagistNames)($vendor);
    }
}
