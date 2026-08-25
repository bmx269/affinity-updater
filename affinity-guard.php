<?php
/**
 * Plugin Name:       Affinity Guard
 * Plugin URI:        https://github.com/bmx269/affinity-guard
 * Description:       Security baseline for WordPress sites deployed from git. Lets core patch itself, keeps itself up to date, and gives other security tooling something to hook.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Author:            Trent Stromkins
 * Author URI:        https://affinitybridge.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       affinity-guard
 *
 * DEFAULTS
 *
 *   Enabled:     yes.     The version control veto is lifted, so background updates run.
 *   Updates:     'minor'. Point releases such as 6.8.1 to 6.8.2 install themselves.
 *   Self update: yes.     Guard keeps itself current from its GitHub releases,
 *                         within its current major version only.
 *
 * TO OVERRIDE, in wp-config.php, above the "That's all, stop editing" line:
 *
 *   define( 'AFFINITY_GUARD_ENABLED', false );      // true (default) | false
 *   define( 'AFFINITY_GUARD_UPDATES', 'major' );    // 'minor' (default) | 'major' | 'dev'
 *   define( 'AFFINITY_GUARD_SELF_UPDATE', false );  // true (default) | false
 *
 *   AFFINITY_GUARD_ENABLED false makes the plugin inert: it registers nothing
 *   and WordPress goes back to refusing all updates on a version-controlled site.
 *
 *   AFFINITY_GUARD_UPDATES is cumulative, each level including the ones before it:
 *
 *     'minor'  maintenance and security releases within a branch    6.8.1 -> 6.8.2
 *     'major'  the above, plus feature releases                     6.8   -> 6.9
 *     'dev'    the above, plus nightlies, alphas, betas and RCs     6.9-beta1 -> 6.9-beta2
 *
 *   'dev' will not move a stable site onto a beta. WordPress treats a site as a
 *   development version only when the version it is already running contains a
 *   hyphen, so that level applies to test installs on the nightly or beta
 *   channel and is ignored everywhere else. See the README for the detail.
 *
 *   AFFINITY_GUARD_SELF_UPDATE false pins this file at whatever version you
 *   deployed. Self updates never cross a major version, so 1.x will not become
 *   2.x on its own however long it is left alone.
 *
 * @package AffinityGuard
 */

namespace AffinityBridge\AffinityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version. Semantic: breaking changes to the constants or the hooks
 * documented in the README move the major number, and nothing else does.
 */
const VERSION = '1.0.0';

/**
 * Repository self updates are fetched from.
 */
const REPO = 'bmx269/affinity-guard';

/**
 * Cron hook that runs the self update check.
 */
const CRON_HOOK = 'affinity_guard_self_update';

/**
 * Applied when the matching constant is not defined.
 */
const DEFAULT_ENABLED     = true;
const DEFAULT_UPDATES     = 'minor';
const DEFAULT_SELF_UPDATE = true;

/**
 * The core update levels, each mapped to the release branches it permits.
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

/* -------------------------------------------------------------------------
 * Configuration
 * ---------------------------------------------------------------------- */

/**
 * Whether the plugin should do anything at all.
 *
 * Deliberately not filterable. This is read while the file loads, before any
 * theme or plugin exists to filter it, so a hook here would look configurable
 * while doing nothing. Use the constant.
 *
 * @return bool True unless AFFINITY_GUARD_ENABLED says otherwise.
 */
function is_enabled() {
	return defined( 'AFFINITY_GUARD_ENABLED' )
		? (bool) constant( 'AFFINITY_GUARD_ENABLED' )
		: DEFAULT_ENABLED;
}

/**
 * The configured core update level.
 *
 * An unrecognised value falls back to the default rather than failing closed or
 * open, and says so when WP_DEBUG is on — a typo here would otherwise change a
 * site's update policy silently.
 *
 * @return string One of the LEVELS keys.
 */
function level() {
	$configured = defined( 'AFFINITY_GUARD_UPDATES' )
		? strtolower( trim( (string) constant( 'AFFINITY_GUARD_UPDATES' ) ) )
		: DEFAULT_UPDATES;

	if ( ! isset( LEVELS[ $configured ] ) ) {
		warn_once(
			sprintf(
				/* translators: 1: the configured value, 2: the list of valid values, 3: the fallback value. */
				esc_html__( 'AFFINITY_GUARD_UPDATES is set to %1$s, which is not one of %2$s. Falling back to %3$s.', 'affinity-guard' ),
				esc_html( var_export( constant( 'AFFINITY_GUARD_UPDATES' ), true ) ),
				esc_html( implode( ', ', array_keys( LEVELS ) ) ),
				esc_html( DEFAULT_UPDATES )
			)
		);

		$configured = DEFAULT_UPDATES;
	}

	/**
	 * Filters the core update level.
	 *
	 * Resolved lazily when core asks about an update, so this is late enough
	 * for other plugins to hook. An unrecognised value is ignored.
	 *
	 * @since 1.0.0
	 *
	 * @param string $configured One of 'minor', 'major' or 'dev'.
	 */
	$filtered = (string) apply_filters( 'affinity_guard_update_level', $configured );

	return isset( LEVELS[ $filtered ] ) ? $filtered : $configured;
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
 * Whether Guard may replace its own file from GitHub.
 *
 * DISALLOW_FILE_MODS is honoured here for the same reason core honours it: a
 * site that has declared its files off limits has said so about this file too.
 *
 * @return bool Whether self updating is permitted.
 */
function self_update_enabled() {
	if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
		return false;
	}

	$enabled = defined( 'AFFINITY_GUARD_SELF_UPDATE' )
		? (bool) constant( 'AFFINITY_GUARD_SELF_UPDATE' )
		: DEFAULT_SELF_UPDATE;

	/**
	 * Filters whether Guard may update itself.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $enabled Whether self updating is permitted.
	 */
	return (bool) apply_filters( 'affinity_guard_self_update_enabled', $enabled );
}

/* -------------------------------------------------------------------------
 * Core update policy
 * ---------------------------------------------------------------------- */

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

/* -------------------------------------------------------------------------
 * Self update
 *
 * WordPress has no update path for must-use plugins: they are not in
 * get_plugins(), so nothing in core ever offers them a new version. Guard
 * therefore checks its own GitHub releases on a daily cron and replaces its
 * own file.
 *
 * Two properties make that defensible rather than reckless. It never crosses a
 * major version, so a breaking release has to be deployed by hand. And it
 * refuses to install anything it cannot parse, so a truncated download leaves
 * the working copy alone rather than fataling every request on the site.
 * ---------------------------------------------------------------------- */

/**
 * Schedule the daily self update check.
 */
function schedule() {
	if ( ! self_update_enabled() ) {
		unschedule();
		return;
	}

	if ( ! wp_next_scheduled( CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', CRON_HOOK );
	}
}

/**
 * Clear the scheduled check, for when self updating is switched off.
 */
function unschedule() {
	$next = wp_next_scheduled( CRON_HOOK );

	if ( $next ) {
		wp_unschedule_event( $next, CRON_HOOK );
	}
}

/**
 * Look for a newer release and install it.
 *
 * @return string|null The version installed, or null when nothing was.
 */
function self_update() {
	if ( ! self_update_enabled() ) {
		return null;
	}

	$release = latest_release();

	if ( is_wp_error( $release ) ) {
		return fail( $release->get_error_message() );
	}

	if ( version_compare( $release['version'], VERSION, '<=' ) ) {
		return null;
	}

	// Major version changes are deliberate deploys, never background ones: a
	// major number is exactly the promise that something has broken.
	if ( major_of( $release['version'] ) !== major_of( VERSION ) ) {
		return null;
	}

	$source = fetch( sprintf( 'https://raw.githubusercontent.com/%s/%s/affinity-guard.php', REPO, $release['tag'] ) );

	if ( is_wp_error( $source ) ) {
		return fail( $source->get_error_message() );
	}

	$invalid = validate( $source, $release['version'] );

	if ( $invalid ) {
		return fail( $invalid );
	}

	$installed = install( $source );

	if ( is_wp_error( $installed ) ) {
		return fail( $installed->get_error_message() );
	}

	/**
	 * Fires after Guard has replaced its own file.
	 *
	 * The new code is on disk but not in memory: this request is still running
	 * the old version, and the next one will pick up the new.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Version that was running.
	 * @param string $to   Version now on disk.
	 */
	do_action( 'affinity_guard_self_updated', VERSION, $release['version'] );

	return $release['version'];
}

/**
 * The newest published release.
 *
 * @return array{version: string, tag: string}|\WP_Error Release details.
 */
function latest_release() {
	$response = wp_remote_get(
		sprintf( 'https://api.github.com/repos/%s/releases/latest', REPO ),
		array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'AffinityGuard/' . VERSION,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		return new \WP_Error( 'affinity_guard_http', sprintf( 'GitHub returned HTTP %d for the latest release.', $code ) );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$tag  = isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '';

	if ( '' === $tag ) {
		return new \WP_Error( 'affinity_guard_no_tag', 'The latest release carries no tag name.' );
	}

	$version = ltrim( $tag, 'vV' );

	if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
		return new \WP_Error( 'affinity_guard_bad_version', sprintf( 'Release tag %s is not a semantic version.', $tag ) );
	}

	return array(
		'version' => $version,
		'tag'     => $tag,
	);
}

/**
 * Download a file over HTTPS.
 *
 * @param string $url Source URL.
 * @return string|\WP_Error File contents.
 */
function fetch( $url ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 20,
			'headers' => array( 'User-Agent' => 'AffinityGuard/' . VERSION ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		return new \WP_Error( 'affinity_guard_http', sprintf( 'Download of %s returned HTTP %d.', $url, $code ) );
	}

	return (string) wp_remote_retrieve_body( $response );
}

/**
 * Check that downloaded code is the plugin, at the expected version, and parses.
 *
 * Anything written to this path runs on every single request, so a bad file is
 * a site down rather than a feature missing. The parse check is the important
 * one: it catches a truncated download, which is the realistic failure.
 *
 * @param string $source   Downloaded PHP.
 * @param string $expected Version the release claims to be.
 * @return string|null Reason it was rejected, or null when it is good.
 */
function validate( $source, $expected ) {
	if ( 0 !== strpos( $source, '<?php' ) ) {
		return 'Downloaded file does not begin with a PHP open tag.';
	}

	if ( false === strpos( $source, 'Plugin Name:       Affinity Guard' ) ) {
		return 'Downloaded file is not the Affinity Guard plugin.';
	}

	if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $source, $matches ) || $matches[1] !== $expected ) {
		return sprintf( 'Downloaded file reports version %s, expected %s.', isset( $matches[1] ) ? $matches[1] : 'none', $expected );
	}

	try {
		token_get_all( $source, TOKEN_PARSE );
	} catch ( \ParseError $e ) {
		return 'Downloaded file is not valid PHP: ' . $e->getMessage();
	}

	return null;
}

/**
 * Replace this file, keeping the previous version alongside it.
 *
 * The write goes to a temporary file first and is moved into place with
 * rename(), which is atomic on a local filesystem — a request arriving
 * mid-update sees either the whole old file or the whole new one.
 *
 * @param string $source Validated PHP to install.
 * @return true|\WP_Error True on success.
 */
function install( $source ) {
	$file = __FILE__;

	if ( ! is_writable( $file ) || ! is_writable( dirname( $file ) ) ) {
		return new \WP_Error( 'affinity_guard_readonly', sprintf( '%s is not writable.', $file ) );
	}

	$temp = $file . '.tmp';

	if ( false === file_put_contents( $temp, $source ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing this plugin's own file, before WP_Filesystem is available on cron.
		return new \WP_Error( 'affinity_guard_write', sprintf( 'Could not write %s.', $temp ) );
	}

	// Kept as .bak rather than .php so WordPress does not load the old copy as
	// a second must-use plugin.
	copy( $file, $file . '.bak' );

	if ( ! rename( $temp, $file ) ) {
		unlink( $temp );
		return new \WP_Error( 'affinity_guard_replace', sprintf( 'Could not replace %s.', $file ) );
	}

	if ( function_exists( 'opcache_invalidate' ) ) {
		opcache_invalidate( $file, true );
	}

	return true;
}

/**
 * The major component of a semantic version.
 *
 * @param string $version Semantic version.
 * @return int Major number.
 */
function major_of( $version ) {
	return (int) strtok( $version, '.' );
}

/**
 * Record a failed self update and carry on.
 *
 * A failure here is never fatal: the site keeps running the version it has.
 *
 * @param string $reason Why the update did not happen.
 * @return null Always null, so callers can return it directly.
 */
function fail( $reason ) {
	/**
	 * Fires when a self update was attempted and did not complete.
	 *
	 * @since 1.0.0
	 *
	 * @param string $reason  Why the update did not happen.
	 * @param string $version Version still installed.
	 */
	do_action( 'affinity_guard_self_update_failed', $reason, VERSION );

	warn_once( $reason );

	return null;
}

/**
 * Report a configuration or update problem once per request, under WP_DEBUG.
 *
 * @param string $message What went wrong.
 */
function warn_once( $message ) {
	static $warned = array();

	if ( isset( $warned[ $message ] ) || ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}

	$warned[ $message ] = true;

	if ( function_exists( '_doing_it_wrong' ) ) {
		_doing_it_wrong( 'Affinity Guard', esc_html( $message ), esc_html( VERSION ) );
	}
}

/* -------------------------------------------------------------------------
 * Bootstrap
 * ---------------------------------------------------------------------- */

/*
 * Nothing is registered when the plugin is switched off, so a disabled site
 * behaves exactly as if the file were not there.
 *
 * Priority 100 on the core filters is late enough to win against a theme or
 * plugin that sets a blanket policy on the default priority, and early enough
 * that your own code can still override it.
 */
if ( is_enabled() ) {
	add_filter( 'automatic_updates_is_vcs_checkout', __NAMESPACE__ . '\\filter_vcs_checkout', 100, 2 );
	add_filter( 'allow_minor_auto_core_updates', __NAMESPACE__ . '\\filter_minor', 100 );
	add_filter( 'allow_major_auto_core_updates', __NAMESPACE__ . '\\filter_major', 100 );
	add_filter( 'allow_dev_auto_core_updates', __NAMESPACE__ . '\\filter_dev', 100 );

	add_action( CRON_HOOK, __NAMESPACE__ . '\\self_update' );
	add_action( 'init', __NAMESPACE__ . '\\schedule' );

	/**
	 * Fires once Affinity Guard has registered everything it does.
	 *
	 * This is the integration point for other security tooling: at this moment
	 * every filter and hook documented in the README exists and can be
	 * overridden. Guard loads as a must-use plugin, so this fires before any
	 * ordinary plugin is loaded.
	 *
	 * @since 1.0.0
	 *
	 * @param string $version The running version of Affinity Guard.
	 */
	do_action( 'affinity_guard_loaded', VERSION );
}
