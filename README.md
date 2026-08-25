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

## What it does

| Behaviour | Default | Filter |
| --- | --- | --- |
| Treat the install as not version-controlled | on | `automatic_updates_is_vcs_checkout` |
| Minor core releases (6.8.1 → 6.8.2) | on | `allow_minor_auto_core_updates` |
| Major core releases (6.8 → 6.9) | off | `allow_major_auto_core_updates` |
| Development releases (nightly, beta, RC) | off | `allow_dev_auto_core_updates` |

Nothing here forces an update. Lifting the veto only puts the site where a non-git site already is; plugin and theme auto-updates still follow their own per-item settings.

## Changing the policy

There is nothing to configure per site, but a site that needs to differ can say so in `wp-config.php`. One switch stands the plugin down entirely:

```php
define( 'AFFINITY_UPDATER_ENABLE', false );
```

Each behaviour also has its own constant, which beats the master one:

```php
define( 'AFFINITY_UPDATER_ALLOW_VCS_UPDATES', true );
define( 'AFFINITY_UPDATER_MINOR_CORE_UPDATES', true );
define( 'AFFINITY_UPDATER_MAJOR_CORE_UPDATES', false );
define( 'AFFINITY_UPDATER_DEV_CORE_UPDATES', false );
```

Precedence, highest first: the setting's own constant → `AFFINITY_UPDATER_ENABLE` → the default.

## What still overrides all of this

`AUTOMATIC_UPDATER_DISABLED` and `DISALLOW_FILE_MODS` stop the updater before these filters are ever reached, and `DISABLE_WP_CRON` means nothing runs unless a real cron job calls `wp-cron.php`. Check all three first when a site still never updates.

## The deploy question

A background update writes to `wp-admin`, `wp-includes`, and the updated plugin or theme directory. Decide which of these you are before rolling it out:

- **Deploy is a `git pull` on the server.** Updates apply and work. Commit the resulting changes, or your next deploy reverts them.
- **Deploy builds from a clean checkout elsewhere.** The update is real until the next release overwrites it. Bump versions in the repository instead — this plugin is not the tool you want.

## License

GPL-2.0-or-later.
