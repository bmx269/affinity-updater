<?php
/**
 * Plugin Name:       Affinity Updater
 * Plugin URI:        https://github.com/bmx269/affinity-updater
 * Description:       Lets WordPress install its own security releases on sites deployed from git. Drop it in and forget it — no settings, no admin screen.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Author:            Trent Stromkins
 * Author URI:        https://affinitybridge.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       affinity-updater
 *
 * DEFAULTS
 *
 *   Enabled: yes. The version control veto is lifted, so background updates run.
 *   Updates: minor. Point releases such as 6.8.1 to 6.8.2 install themselves;
 *            major and development releases do not.
 *
 * TO OVERRIDE, in wp-config.php, above the "That's all, stop editing" line:
 *
 *   define( 'AFFINITY_UPDATER_ENABLED', false );    // true (default) | false
 *   define( 'AFFINITY_UPDATER_UPDATES', 'major' );  // 'minor' (default) | 'major' | 'dev'
 *
 *   AFFINITY_UPDATER_ENABLED false makes the plugin inert: it registers nothing
 *   and WordPress goes back to refusing all updates on a version-controlled site.
 *
 *   AFFINITY_UPDATER_UPDATES is cumulative, each level including the ones before it:
 *
 *     'minor'  minor releases only                        6.8.1 -> 6.8.2
 *     'major'  minor and major releases                   6.8   -> 6.9
 *     'dev'    minor, major, and nightlies, betas and RCs
 *
 * @package AffinityUpdater
 */

namespace AffinityBridge\AffinityUpdater;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.0.0';

/**
 * Applied when AFFINITY_UPDATER_ENABLED is not defined.
 */
const DEFAULT_ENABLED = true;

/**
 * Applied when AFFINITY_UPDATER_UPDATES is not defined, or is not a level below.
 */
const DEFAULT_UPDATES = 'minor';

/**
 * The update levels, each mapped to the core release branches it permits.
 *
 * Levels are cumulative on purpose. A site that wants major releases installed
 * automatically certainly wants the security point releases too, so there is no
 * way to ask for one branch while excluding a safer one.
 */
const LEVELS = array(
	'minor' => array( 'minor' ),
	'major' => array( 'minor', 'major' ),
	'dev'   => array( 'minor', 'major', 'dev' ),
);

/**
 * Whether the plugin should do anything at all.
 *
 * @return bool True unless AFFINITY_UPDATER_ENABLED says otherwise.
 */
function is_enabled() {
	return defined( 'AFFINITY_UPDATER_ENABLED' )
		? (bool) constant( 'AFFINITY_UPDATER_ENABLED' )
		: DEFAULT_ENABLED;
}

/**
 * The configured update level.
 *
 * An unrecognised value falls back to the default rather than failing closed or
 * open, and says so when WP_DEBUG is on — a typo here would otherwise change a
 * site's update policy silently.
 *
 * @return string One of the LEVELS keys.
 */
function level() {
	if ( ! defined( 'AFFINITY_UPDATER_UPDATES' ) ) {
		return DEFAULT_UPDATES;
	}

	$configured = strtolower( trim( (string) constant( 'AFFINITY_UPDATER_UPDATES' ) ) );

	if ( isset( LEVELS[ $configured ] ) ) {
		return $configured;
	}

	static $warned = false;

	if ( ! $warned && defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( '_doing_it_wrong' ) ) {
		$warned = true;

		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				/* translators: 1: the configured value, 2: the list of valid values, 3: the fallback value. */
				esc_html__( 'AFFINITY_UPDATER_UPDATES is set to %1$s, which is not one of %2$s. Falling back to %3$s.', 'affinity-updater' ),
				esc_html( var_export( constant( 'AFFINITY_UPDATER_UPDATES' ), true ) ),
				esc_html( implode( ', ', array_keys( LEVELS ) ) ),
				esc_html( DEFAULT_UPDATES )
			),
			esc_html( VERSION )
		);
	}

	return DEFAULT_UPDATES;
}

/**
 * Whether a core release branch may install itself.
 *
 * @param string $branch One of 'minor', 'major' or 'dev'.
 * @return bool Whether the current level permits that branch.
 */
function allows( $branch ) {
	return in_array( $branch, LEVELS[ level() ], true );
}

/**
 * Tell the updater this install is not a version control checkout.
 *
 * WP_Automatic_Updater::is_vcs_checkout() walks up from the context directory
 * looking for .git, .svn, .hg or .bzr, and a single hit disables core, plugin,
 * theme and translation updates alike. Reporting false does not force any
 * update; it only lifts that veto, putting the site where a non-git site
 * already is.
 *
 * @see https://developer.wordpress.org/reference/hooks/automatic_updates_is_vcs_checkout/
 *
 * @param bool   $checkout Whether a checkout was discovered.
 * @param string $context  Filesystem path being checked.
 * @return bool Always false.
 */
function filter_vcs_checkout( $checkout, $context ) {
	return false;
}

/**
 * Allow or block minor core releases, such as 6.8.1 to 6.8.2.
 *
 * Core applies this filter to the value it has already resolved from the
 * auto_update_core_minor option and the WP_AUTO_UPDATE_CORE constant, so the
 * answer given here is the final one for this branch.
 *
 * @param bool $enabled Whether core would allow the update.
 * @return bool Whether to allow it.
 */
function filter_minor( $enabled ) {
	return allows( 'minor' );
}

/**
 * Allow or block major core releases, such as 6.8 to 6.9.
 *
 * @param bool $enabled Whether core would allow the update.
 * @return bool Whether to allow it.
 */
function filter_major( $enabled ) {
	return allows( 'major' );
}

/**
 * Allow or block development releases: nightlies, betas and release candidates.
 *
 * @param bool $enabled Whether core would allow the update.
 * @return bool Whether to allow it.
 */
function filter_dev( $enabled ) {
	return allows( 'dev' );
}

/*
 * Nothing is registered when the plugin is switched off, so a disabled site
 * behaves exactly as if the file were not there.
 *
 * Priority 100 is late enough to win against a theme or plugin that sets a
 * blanket policy on the default priority, and early enough that a site can
 * still override it from its own code.
 */
if ( is_enabled() ) {
	add_filter( 'automatic_updates_is_vcs_checkout', __NAMESPACE__ . '\\filter_vcs_checkout', 100, 2 );
	add_filter( 'allow_minor_auto_core_updates', __NAMESPACE__ . '\\filter_minor', 100 );
	add_filter( 'allow_major_auto_core_updates', __NAMESPACE__ . '\\filter_major', 100 );
	add_filter( 'allow_dev_auto_core_updates', __NAMESPACE__ . '\\filter_dev', 100 );
}
