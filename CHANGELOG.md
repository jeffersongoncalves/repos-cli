# Changelog

All notable changes to this project will be documented in this file.

## [1.2.3] - 2026-08-26

### Other

- Fix FAIL never showing in clone/pull task output

## [1.2.2] - 2026-08-26

### Other

- Fix cliff.toml commit_parsers skip pattern for unprefixed release commits
- Document device flow login for GitHub and GitLab in README
- Add per-host credential setup steps to README
- Trim changelog/release notes to one line per commit
- Fix Bitbucket API token scope in README
- Recurse into submodules on clone and pull

## [1.2.1] - 2026-08-25

### Other

- Fix cliff.toml tag_pattern so release notes only include the delta since the last tag
- Fix device flow polling when a host answers pending status with a 4xx

## [1.2.0] - 2026-08-25

### Other

- Fix PHPStan: interval key always exists in device code response
- Add GitLab device flow login with automatic token refresh

## [1.1.0] - 2026-08-25

### Other

- Add GitHub device flow login with automatic token refresh

## [1.0.1] - 2026-08-25

### Other

- Move runtime deps to require-dev, matching db-cli convention
- Rewrite README to match the other CLIs' structure
- Match db-cli's project layout, fix the CI test failure

## [1.0.0] - 2026-08-25

### Other

- Initial scaffold of repos-cli
- Trigger workflow indexing
- Update release.yml example version, force workflow reindex


