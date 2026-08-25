# Changelog

All notable changes to this project will be documented in this file.

## [1.1.0] - 2026-08-25

### Other

- Initial scaffold of repos-cli

Multi-host bulk git repository manager (GitHub, GitLab, Bitbucket) built
with Laravel Zero, following the same structure and shared packages as
bb-cli/db-cli in this monorepo. Covers clone (single or bulk per owner/org,
already-cloned repos are pulled instead), pull --all across a folder of
repos, and open-issues listing (single repo or cross-repo).
- Trigger workflow indexing

Force GitHub to (re)discover .github/workflows/release.yml — it wasn't
listed by the Actions API after the initial gh repo create --push, likely
because that combined create+push doesn't fire the normal workflow sync.
- Update release.yml example version, force workflow reindex

release.yml wasn't showing up in the Actions workflow list after the
initial push (the other 3 workflows registered fine). Suspect GitHub only
reparses files that literally changed in a given push, so a real edit to
this file's content is needed to force it to register.
- Release 1.0.0
- Move runtime deps to require-dev, matching db-cli convention

Only php stays in require. Every other dependency — laravel-zero/framework,
the jeffersongoncalves/laravel-zero-* packages, and the dev tooling — lives
in require-dev, same split used across the other CLIs in this monorepo.
box.json bundles dev deps into the PHAR regardless (exclude-dev-files:
false), so this only changes what "composer require"-ing this as a library
would pull in, not what the built binary contains.
- Rewrite README to match the other CLIs' structure

Global/from-source install split, self-update, per-command usage sections,
Development section — same shape as db-cli's README instead of the ad hoc
one this started with.
- Match db-cli's project layout, fix the CI test failure

- phpunit.xml.dist: drop the tests/Feature testsuite. That directory is
  empty (no tracked files), so a fresh CI checkout doesn't have it at all
  and paratest was hard-failing with "Test directory not found" on every
  push. This is what run-tests.yml has been failing on.
- config/app.php: read the embedded version from version.txt instead of
  shelling out to `git describe` — matches db-cli, and doesn't depend on a
  .git directory that isn't bundled into the built PHAR anyway.
- config/commands.php: strip the boilerplate comment blocks.
- composer.json: drop the unused Database\Factories/Seeders autoload
  entries left over from scaffolding (this CLI has no database layer).
- .gitattributes: was still the stock create-project one (.scrutinizer.yml,
  BACKERS.md, ...); replaced with the export-ignore list actually used
  across this monorepo's CLIs.
- .editorconfig: removed, db-cli doesn't ship one either.
- phpstan.neon.dist: level 6 -> 5, matching db-cli.
- run-tests.yml: single PHP version (8.4) instead of a 8.2/8.4 matrix.
- AGENTS.md: agent-facing usage guide (flag syntax, common mistakes, exit
  codes), same shape as db-cli's.
- Release 1.0.1
- Add GitHub device flow login with automatic token refresh

auth:github:login --device opens a verification URL and code instead of
requiring a personal access token. HostClientFactory refreshes the stored
token transparently once it expires.


