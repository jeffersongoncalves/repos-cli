<div class="filament-hidden">

![repos-cli](https://raw.githubusercontent.com/jeffersongoncalves/repos-cli/main/art/jeffersongoncalves-repos-cli.png)

</div>

# repos-cli

A multi-host bulk git repository manager. Clone, pull, and list open issues
across hundreds of repos on GitHub, GitLab, and Bitbucket from one terminal
— no more `cd`-ing into 600 folders one at a time.

Built with [Laravel Zero](https://laravel-zero.com) and modeled on the other
CLIs in this monorepo.

## Install

### Global (recommended)

```bash
composer global require jeffersongoncalves/repos-cli
```

The binary `repos` will be on your `PATH` as long as Composer's global
`vendor/bin` is in it.

### From source

```bash
git clone https://github.com/jeffersongoncalves/repos-cli.git
cd repos-cli
composer install
```

## Usage

### Update

```bash
repos self-update
```

### Authenticate

Clones use your local SSH key, same as any `git clone`. The saved token is
only used for API calls (listing repos, listing issues) — one login per
host you use.

#### GitHub

Device flow (recommended — nothing to create or manage):

```bash
repos auth:github:login --device
```

Opens a verification URL and a short code; approve it in the browser. The
saved token refreshes itself automatically once it expires.

Personal access token instead:

1. [Create a classic token](https://github.com/settings/tokens/new) with the
   `repo` scope (covers repos, issues, and search)
2. `repos auth:github:login` and paste it

#### GitLab

Device flow (recommended):

```bash
repos auth:gitlab:login --device
```

Personal access token instead:

1. GitLab → Settings → [Access Tokens](https://gitlab.com/-/user_settings/personal_access_tokens) → create one with the `read_api` scope
2. `repos auth:gitlab:login` and paste it

#### Bitbucket

No device flow — Bitbucket doesn't support it. API token only:

1. Bitbucket → Personal settings → Security → [API tokens](https://support.atlassian.com/bitbucket-cloud/docs/api-tokens/) → **Create API token with scopes**
2. Name it (e.g. `repos-cli`), select **Bitbucket** as the app, and grant these [permissions](https://support.atlassian.com/bitbucket-cloud/docs/api-token-permissions/) — Write does **not** imply Read, each is separate:

| Scope | Permission | Scope ID |
|-------|-----------|----------|
| Repositories | Read | `read:repository:bitbucket` |
| Issues | Read | `read:issue:bitbucket` |

3. `repos auth:bitbucket:login` — prompts for your **Atlassian account email**
   (not your Bitbucket username) and the token

```bash
repos auth:show
```

Credentials are stored in `~/.repos-cli/config.json` (mode `0600`).

### Clone

```bash
# Single repo (URL, or owner/repo with --host)
repos clone git@github.com:acme/widgets.git
repos clone acme/widgets --host=github

# Bulk-clone every repo of an owner/org. Already-cloned repos are pulled
# instead of re-cloned, so re-running this is how you "update everything".
repos clone acme --host=github --path=~/code/acme
```

### Pull

```bash
# git pull --all on every repo found one level under a folder
repos pull ~/code/acme
```

### Issues

```bash
# Single repo
repos issues acme/widgets --host=github

# Every repo of an owner/org
# (GitHub: one search API call. GitLab/Bitbucket: looped per repo.)
repos issues acme --host=github
```

### Supported hosts

| Host | Auth | Notes |
|------|------|-------|
| GitHub | Personal access token, or `--device` flow | Bulk clone/issues via `orgs/{org}/repos` or `users/{user}/repos`; issues search via `search/issues`. |
| GitLab | Personal access token, or `--device` flow | Bulk clone/issues via `groups/{ns}/projects` or `users/{ns}/projects`. |
| Bitbucket | Account email + API token | Same auth shape as `bb-cli`. Issues skipped for repos with no issue tracker enabled. No device flow — Bitbucket doesn't support it. |

## Development

```bash
composer install
composer test       # Pest tests + Pint lint
composer lint        # Auto-fix style
composer phpstan      # Static analysis
composer build        # Build the PHAR into builds/repos
```

## License

MIT © Jefferson Goncalves
