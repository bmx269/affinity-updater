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
 * @package AffinityUpdater
 */

namespace AffinityBridge\AffinityUpdater;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.0.0';

/**
 * The policy this plugin applies, and the wp-config.php constant that changes
 * each part of it.
 *
 * Minor releases carry security and bug fixes and are what WordPress installs
 * on its own anyway; major and development releases stay off, on the
 * assumption that a site kept in git wants those deployed deliberately.
 *
 * Everything is deliberately a constant. There is no options table, no admin
 * screen and nothing to configure per site: the plugin is meant to be dropped
 * into a fleet and forgotten.
 */
const DEFAULTS = array(
	'allow_vcs' => true,  // AFFINITY_UPDATER_ALLOW_VCS_UPDATES
	'minor'     => true,  // AFFINITY_UPDATER_MINOR_CORE_UPDATES
	'major'     => false, // AFFINITY_UPDATER_MAJOR_CORE_UPDATES
	'dev'       => false, // AFFINITY_UPDATER_DEV_CORE_UPDATES
);

/**
 * Map of setting names to the constant that overrides each one.
 */
const CONSTANTS = array(
	'allow_vcs' => 'AFFINITY_UPDATER_ALLOW_VCS_UPDATES',
	'minor'     => 'AFFINITY_UPDATER_MINOR_CORE_UPDATES',
	'major'     => 'AFFINITY_UPDATER_MAJOR_CORE_UPDATES',
	'dev'       => 'AFFINITY_UPDATER_DEV_CORE_UPDATES',
);

/**
 * Resolve one part of the policy.
 *
 * Precedence, highest first: the setting's own constant, the master
 * AFFINITY_UPDATER_ENABLE constant, then the default above.
 *
 * @param string $key One of the DEFAULTS keys.
 * @return bool Whether that behaviour is enabled.
 */
function setting( $key ) {
	if ( ! isset( DEFAULTS[ $key ] ) ) {
		return false;
	}

	if ( defined( CONSTANTS[ $key ] ) ) {
		return (bool) constant( CONSTANTS[ $key ] );
	}

	// AFFINITY_UPDATER_ENABLE is the one switch most sites will ever touch:
	// false stands the plugin down completely, true restates the defaults so a
	// deploy can assert the policy rather than inherit it.
	if ( defined( 'AFFINITY_UPDATER_ENABLE' ) && ! constant( 'AFFINITY_UPDATER_ENABLE' ) ) {
		return false;
	}

	return DEFAULTS[ $key ];
}

/**
 * Tell the updater whether this install counts as a version control checkout.
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
 * @return bool Whether to treat the install as a checkout.
 */
function filter_vcs_checkout( $checkout, $context ) {
	return setting( 'allow_vcs' ) ? false : $checkout;
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
	return setting( 'minor' );
}

/**
 * Allow or block major core releases, such as 6.8 to 6.9.
 *
 * @param bool $enabled Whether core would allow the update.
 * @return bool Whether to allow it.
 */
function filter_major( $enabled ) {
	return setting( 'major' );
}

/**
 * Allow or block development releases: nightlies, betas and release candidates.
 *
 * @param bool $enabled Whether core would allow the update.
 * @return bool Whether to allow it.
 */
function filter_dev( $enabled ) {
	return setting( 'dev' );
}

/*
 * Priority 100: late enough to win against a theme or plugin that sets a
 * blanket policy on the default priority, early enough that a site can still
 * override it from its own code.
 */
add_filter( 'automatic_updates_is_vcs_checkout', __NAMESPACE__ . '\\filter_vcs_checkout', 100, 2 );
add_filter( 'allow_minor_auto_core_updates', __NAMESPACE__ . '\\filter_minor', 100 );
add_filter( 'allow_major_auto_core_updates', __NAMESPACE__ . '\\filter_major', 100 );
add_filter( 'allow_dev_auto_core_updates', __NAMESPACE__ . '\\filter_dev', 100 );
