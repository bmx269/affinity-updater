# Changelog

All notable changes to Affinity Guard are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[semantic versioning](https://semver.org/) over its public API: the
`AFFINITY_GUARD_*` constants and the hooks listed in the README.

Self updates never cross a major version, so anything listed under a **Changed**
or **Removed** heading in a major release has to be deployed deliberately.

## [Unreleased]

## [1.0.1] - 2026-08-25

### Changed

- The project moved to the Affinity Bridge organisation, and self updates now
  read releases from `affinitybridge/affinity-guard`. A 1.0.0 install reaches
  the new location through GitHub's redirect only until a repository of the old
  name exists again, so installs should be moved to 1.0.1 by hand rather than
  left to find their own way.

## [1.0.0] - 2026-08-25

First release under the name Affinity Guard, previously Affinity Updater. The
rename came before any site outside testing had it installed, so there is no
upgrade path to provide — the earlier `AFFINITY_UPDATER_*` constants were never
released and are simply gone.

### Added

- Automatic core updates on sites deployed from git, by answering the
  `automatic_updates_is_vcs_checkout` filter, which otherwise blocks every
  background update when a `.git` directory is found at or above the install.
- `AFFINITY_GUARD_ENABLED` to switch the plugin off entirely. When off it
  registers no filters at all rather than neutralising them.
- `AFFINITY_GUARD_UPDATES` to choose a cumulative core update level of `minor`
  (default), `major` or `dev`, so a safer release branch can never be excluded.
  An unrecognised value falls back to `minor` and reports itself under `WP_DEBUG`.
- `AFFINITY_GUARD_SELF_UPDATE` and a daily check against the project's GitHub
  releases, since WordPress offers must-use plugins no update path of its own.
  The download must be a semantic version, higher than the running one, within
  the same major version, identify as this plugin, match the release version,
  and parse as PHP before it is installed. It is written to a temporary file and
  moved into place atomically, with the previous version kept as `.bak`.
- A documented hook API for other security tooling: the `affinity_guard_loaded`
  action, `affinity_guard_update_level` and `affinity_guard_self_update_enabled`
  filters, and `affinity_guard_self_updated` and `affinity_guard_self_update_failed`
  actions.

[Unreleased]: https://github.com/affinitybridge/affinity-guard/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/affinitybridge/affinity-guard/releases/tag/v1.0.1
[1.0.0]: https://github.com/affinitybridge/affinity-guard/releases/tag/v1.0.0
