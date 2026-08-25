# AGENTS.md

Guidance for an LLM/agent using the `repos` CLI.

Binary: `repos` (or `php repos` from a source checkout).

## Common mistake — auth commands are interactive-only

`auth:github:login`, `auth:gitlab:login`, and `auth:bitbucket:login` prompt
for the token (and, for Bitbucket, the account email) via Laravel Prompts —
there is **no** `--token=` flag on any of them. They cannot be scripted with
flags; either run them in a real TTY once, or write directly to
`~/.repos-cli/config.json` (mode `0600`) in the shape:

```json
{"hosts":{"github":{"token":"..."},"gitlab":{"token":"..."},"bitbucket":{"username":"you@example.com","app_password":"..."}}}
```

`auth:show` reports status per host without printing the token.

## `clone` — how the target is parsed

`repos clone TARGET [--host=github|gitlab|bitbucket] [--path=DIR]`

- `TARGET` contains `/` or `:` (a URL, or `owner/repo`) → clones **one**
  repo. A bare `owner/repo` needs `--host` (there's no remote to detect it
  from); a full URL (`git@host:owner/repo.git` or `https://...`) doesn't.
- `TARGET` is a single bare word (no `/`, no `:`) → bulk-clones **every**
  repo of that owner/org. Requires `--host` and prior auth for that host.
  Repos already present under `--path` (default: cwd) are `git pull --all`ed
  instead of re-cloned — re-running this command is how you "update
  everything".

```bash
repos clone git@github.com:acme/widgets.git
repos clone acme/widgets --host=github
repos clone acme --host=github --path=~/code/acme
```

## `pull` — local folder only, one level deep

`repos pull [PATH]` (default: cwd). Scans immediate subdirectories of
`PATH` for a `.git` folder and runs `git pull --all` on each. Does **not**
recurse, and does not need auth (plain `git pull`, uses your SSH key).

## `issues`

`repos issues TARGET [--host=...] [--qualifier=org|user]`

- `TARGET` = `owner/repo` → issues for that one repo.
- `TARGET` = bare `owner`/`org` → issues across every repo of that
  owner/org. On GitHub this is a single `search/issues` call (fast); on
  GitLab/Bitbucket it loops one API call per repo (slow for large orgs).
  `--qualifier` only affects the GitHub search query (`org:X` vs `user:X`).

## Exit codes

`0` success, `1` failure (auth missing for the target host, host
undeterminable — pass `--host`, API error, or one/more repos failed to
pull in a `pull`/bulk-`clone` run).

## Example: end-to-end from a fresh agent

```bash
repos auth:show                                    # check what's authenticated
repos clone acme --host=github --path=~/code/acme  # bulk clone/update
repos pull ~/code/acme                              # or just re-pull what's there
repos issues acme --host=github                    # open issues across the org
```
