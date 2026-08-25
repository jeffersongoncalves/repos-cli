# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2026-08-25

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


