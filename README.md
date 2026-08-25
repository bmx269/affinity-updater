# Affinity Updater

A must-use plugin that lets WordPress install its own security releases on sites deployed from git. One file, no settings, no admin screen — drop it in and forget it.

## Why

`WP_Automatic_Updater::is_vcs_checkout()` walks up from the install looking for `.git`, `.svn`, `.hg` or `.bzr`. One hit and WordPress refuses to run **any** background update — core, plugins, themes, translations. The reasoning is sound: core assumes a version-controlled site is deployed, and that writing files behind the deploy's back causes more trouble than it prevents.

For teams whose sites are in git for code review and rollback rather than for immutable deploys, that trade is backwards. It means hand-applying every point release across every site. This plugin lifts the veto through the [`automatic_updates_is_vcs_checkout`](https://developer.wordpress.org/reference/hooks/automatic_updates_is_vcs_checkout/) filter and pins a sane core update policy.

## Install

Copy `affinity-updater.php` into `wp-content/mu-plugins/`:

```sh
cp affinity-updater.php /path/to/wp-content/mu-plugins/
```

That is the whole installation. Must-use plugins load on every request, need no activation, and cannot be switched off from the admin — which is the point on a managed fleet. WordPress only loads files at the top level of `mu-plugins`, so keep it as a single file there rather than in a subdirectory.

It works as an ordinary plugin too, if you would rather have it in `plugins/` and activate it per site.

## Defaults

Drop the file in and this is what you get, with nothing to configure:

| | Default | Effect |
| --- | --- | --- |
| **Enabled** | `true` | The version control veto is lifted, so background updates are allowed to run |
| **Updates** | `'minor'` | Point releases (6.8.1 → 6.8.2) install themselves; major and development releases do not |

Nothing here forces an update. Lifting the veto only puts the site where a non-git site already is, and plugin and theme auto-updates still follow their own per-item settings.

## Overriding the defaults

Two constants, both optional, both in `wp-config.php` above the `/* That's all, stop editing! */` line:

```php
define( 'AFFINITY_UPDATER_ENABLED', false );   // true (default) | false
define( 'AFFINITY_UPDATER_UPDATES', 'major' ); // 'minor' (default) | 'major' | 'dev'
```

### `AFFINITY_UPDATER_ENABLED`

| Value | Result |
| --- | --- |
| `true` *(default)* | The plugin applies the policy below |
| `false` | The plugin registers nothing and goes inert. WordPress returns to refusing every background update on a version-controlled site, exactly as if the file were not there |

### `AFFINITY_UPDATER_UPDATES`

The levels are **cumulative** — each one includes those above it. A site that wants major releases installed automatically certainly wants the security point releases too, so there is no way to ask for one branch while excluding a safer one.

| Value | Minor<br>6.8.1 → 6.8.2 | Major<br>6.8 → 6.9 | Dev<br>nightly, beta, RC |
| --- | :---: | :---: | :---: |
| `'minor'` *(default)* | yes | no | no |
| `'major'` | yes | yes | no |
| `'dev'` | yes | yes | yes |

The value is trimmed and lower-cased, so `' Major '` works. An unrecognised value falls back to `'minor'` and, when `WP_DEBUG` is on, raises a `_doing_it_wrong()` notice naming the typo — a misspelling should not change a site's update policy in silence.

## What minor, major and dev actually mean

These are WordPress's own categories, not this plugin's. Core sorts every offered update into exactly one of them in [`Core_Upgrader::should_update_to_version()`](https://developer.wordpress.org/reference/classes/core_upgrader/should_update_to_version/) (`wp-admin/includes/class-core-upgrader.php`) and asks a different filter about each.

### Minor — [`allow_minor_auto_core_updates`](https://developer.wordpress.org/reference/hooks/allow_minor_auto_core_updates/)

**6.8.1 → 6.8.2.** An update within the same `x.y` branch, where only the third number moves. Core detects it by comparing the first two version segments: same branch, minor update.

These are maintenance and security releases. They carry bug fixes and security patches, add no features, and are the releases WordPress installs by itself on a normal site. This is the level the plugin defaults to, and the reason it exists — an unattended security patch is the update you least want to be applying by hand across a fleet.

### Major — [`allow_major_auto_core_updates`](https://developer.wordpress.org/reference/hooks/allow_major_auto_core_updates/)

**6.8 → 6.9.** An update that moves to a higher `x.y` branch.

Worth knowing that this is not semver, and the naming trips people up: WordPress calls 6.8 → 6.9 a *major* release even though only the second number changes, and 7.0 is not special — it is the release after 6.9, nothing more. Major releases are the feature releases. They ship new blocks, editor changes and API deprecations, and they are where something in a theme or plugin can break. Off by default here for that reason.

### Dev — [`allow_dev_auto_core_updates`](https://developer.wordpress.org/reference/hooks/allow_dev_auto_core_updates/)

**6.9-beta1 → 6.9-beta2, or 7.0-alpha-59321 → 7.0-alpha-59400.** Nightlies, alphas, betas and release candidates.

Core decides this from the version the site is *already running*: a `$wp_version` containing a hyphen is a development version. Two consequences worth being clear about:

- **`'dev'` will not move a stable site onto a beta.** A site on 6.8 is not a development version, so this filter is never consulted there. The level only governs sites already on the nightly, beta or RC channel — a dedicated test install, not production.
- **On a development install, dev is a gate rather than a branch.** Core checks it first and blocks everything if it fails, then *falls through* to the minor or major question for the target version. So a site on 6.9-beta1 set to `'minor'` would have every update refused at the dev gate, including the beta2 that fixes something. That fall-through is exactly why the levels here are cumulative: `'dev'` opens the gate and permits what lies beyond it.

### Which filters this drives

| Behaviour | Filter |
| --- | --- |
| Treat the install as not version-controlled | [`automatic_updates_is_vcs_checkout`](https://developer.wordpress.org/reference/hooks/automatic_updates_is_vcs_checkout/) |
| Minor core releases | [`allow_minor_auto_core_updates`](https://developer.wordpress.org/reference/hooks/allow_minor_auto_core_updates/) |
| Major core releases | [`allow_major_auto_core_updates`](https://developer.wordpress.org/reference/hooks/allow_major_auto_core_updates/) |
| Development releases | [`allow_dev_auto_core_updates`](https://developer.wordpress.org/reference/hooks/allow_dev_auto_core_updates/) |

All four are added at priority 100: late enough to beat a theme or plugin setting a blanket policy on the default priority, early enough that your own code can still override it.

## What still overrides all of this

`AUTOMATIC_UPDATER_DISABLED` and `DISALLOW_FILE_MODS` stop the updater before these filters are ever reached, and `DISABLE_WP_CRON` means nothing runs unless a real cron job calls `wp-cron.php`. Check all three first when a site still never updates.

## The deploy question

A background update writes to `wp-admin`, `wp-includes`, and the updated plugin or theme directory. Decide which of these you are before rolling it out:

- **Deploy is a `git pull` on the server.** Updates apply and work. Commit the resulting changes, or your next deploy reverts them.
- **Deploy builds from a clean checkout elsewhere.** The update is real until the next release overwrites it. Bump versions in the repository instead — this plugin is not the tool you want.

## License

GPL-2.0-or-later.
