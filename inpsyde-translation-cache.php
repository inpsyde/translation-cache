<?php # -*- coding: utf-8 -*-
/**
 * Plugin Name: Inpsyde Translation Cache
 * Description: Improves site performance by caching translation files using WordPress object cache.
 * Author: Inpsyde GmbH, Giuseppe Mazzapica, Masaki Takeuchi
 * Version: 1.0.1
 * Requires at least: 4.5

 * This file is part of the inpsyde-translation-cache package.

 * (c)  Inpsyde GmbH

 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.

 * "Inpsyde Translation Cache" incorporates work covered by the following copyright
 * and permission notices:

 *     "MO Cache" WordPress plugin
 *     https://wordpress.org/plugins/mo-cache/
 *     https://github.com/m4i/wordpress-mo-cache
 *     Copyright (c) 2011 Masaki Takeuchi (m4i)
 *     Released under the MIT.
 */
namespace Inpsyde\TranslationCache;

if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
	return;
}

$is_regular_plugin = did_action( 'muplugins_loaded' );
$is_loaded         = did_action( 'inpsyde_translation_cache' );

/*
 * On activation as regular plugin, the plugin copy the file to MU Plugins folder,
 * so it will be used as MU plugin.
 */
$is_regular_plugin and register_activation_hook(
	__FILE__,
	function () {

		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}

		$option_name         = 'inpsyde-mocache-class-path';
		$mo_cache_class_path = __DIR__ . '/src/MoCache.php';

		get_site_option( $option_name )
			? update_site_option( $option_name, $mo_cache_class_path )
			: add_site_option( $option_name, $mo_cache_class_path );

		$target        = trailingslashit( WPMU_PLUGIN_DIR ) . basename( __FILE__ );
		$target_folder = dirname( $target );

		if ( ! file_exists( $target ) && wp_mkdir_p( $target_folder ) && is_writable( $target_folder ) ) {
			@copy( __FILE__, $target );
		}
	}
);

/*
 * On deactivation as regular plugin, the plugin check the file in MU Plugins folder,
 * if found and it is not modified since it was copied on activation, it is deleted.
 */
$is_regular_plugin and register_deactivation_hook(
	__FILE__,
	function () {

		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}

		delete_site_option( 'inpsyde-mocache-class-path' );

		$target = trailingslashit( WPMU_PLUGIN_DIR ) . basename( __FILE__ );

		if ( file_exists( $target ) && is_readable( $target ) && md5_file( __FILE__ ) === md5_file( $target ) ) {
			@unlink( $target );
		}
	}
);

/*
 * When self installation as MU plugin failed, show an admin notice telling users to manually copy the file
 * to MU plugins folder.
 */
( $is_regular_plugin && ! $is_loaded ) and add_action(
	'all_admin_notices',
	function () {

		$screen = get_current_screen();
		if ( 'plugins' !== $screen->id ) {
			return;
		}

		$markup = '<div class="notice notice-error is-dismissible"><p>%s</p></div>';
		$format = '<strong>Inpsyde Translation Cache can\'t install itself as MU plugin.</strong><br>';
		$format .= 'Please copy "%s" to "%s" to improve translation performance';
		printf( $markup, sprintf( $format, __FILE__, trailingslashit( WPMU_PLUGIN_DIR ) . basename( __FILE__ ) ) );
	}
);

/*
 * When regular plugin is deactivated, but MU plugin is still there because deleting it failed, show an admin notice
 * telling users to manually delete the file from MU plugins folder, or in case it was edited, to removed the code that
 * show the notice.
 */
( ! $is_regular_plugin && ! get_site_option( 'inpsyde-mocache-class-path' ) ) and add_action(
	'all_admin_notices',
	function () {
		$line = __LINE__ - 3;

		$markup = '<div class="notice notice-error is-dismissible">%s</div>';
		$msg    = '<p><em><strong>Inpsyde Translation Cache</strong></em> is installed as MU plugin without its "regular" plugin counterpart.</p>';
		$msg   .= '<p>Possible causes are:</p>';
		$msg   .= '<ol><li>was not possible to delete MU plugin on plugin deactivation</li>';
		$msg   .= '<li>the MU plugin was edited "manually" and preserved to avoid deleting custom work</li></ol>';
		$msg   .= '<p>In the first case, please delete MU plugin file at <code>%s</code> ';
		$msg   .= '(note <strong>it is not doing anything</strong> but showing this notice).</p>';
		$msg   .= '<p>In the second case, if the MU plugin was edited to do <em>something</em> without regular plugin, ';
		$msg   .= 'you should probably also delete the code that shows this notice (starts around line <code>%d</code>).</p>';
		printf( $markup, sprintf( $msg, __FILE__, $line ) );
	}
);

// When installed as MU plugin successfully, there's nothing more to do.
if ( $is_regular_plugin && $is_loaded ) {
	unset( $is_regular_plugin, $is_loaded );

	return;
}

// We need to check for existence because when copied to MU plugin this file is loaded twice.
if ( ! function_exists( __NAMESPACE__ . '\\load_translation_cache' ) ) {

	/**
	 * Bootstrap plugin routine.
	 *
	 * @wp-hook muplugins_loaded
	 * @wp-hook plugins_loaded
	 */
	function load_translation_cache() {

		$class_missing = FALSE;

		if ( ! class_exists( MoCache::class ) ) {
			$class_missing = TRUE;
			$class_path    = get_site_option( 'inpsyde-mocache-class-path' );
			if ( $class_path && file_exists( $class_path ) && is_readable( $class_path ) ) {
				/** @noinspection PhpIncludeInspection */
				require_once $class_path;
			}
		}

		if ( ! $class_missing || class_exists( MoCache::class ) ) {

			// Add plugin hooks, avoiding to add them more than once.
			$has_load = has_filter( 'override_load_textdomain', [ MoCache::class, 'load' ] );
			$has_load or add_filter( 'override_load_textdomain', [ MoCache::class, 'load' ], 30, 3 );

			$has_theme_switch = has_action( 'switch_theme', [ MoCache::class, 'on_theme_switch' ] );
			$has_theme_switch or add_action( 'switch_theme', [ MoCache::class, 'on_theme_switch' ], 30, 3 );

			$has_plugin_activated = has_action( 'activated_plugin', [ MoCache::class, 'on_plugin_switch' ] );
			$has_plugin_activated or add_action( 'activated_plugin', [ MoCache::class, 'on_plugin_switch' ], 30 );

			$has_plugin_deactivated = has_action( 'deactivate_plugin', [ MoCache::class, 'on_plugin_switch' ] );
			$has_plugin_deactivated or add_action( 'deactivate_plugin', [ MoCache::class, 'on_plugin_switch' ], 30 );

			$has_shutdown = has_action( 'shutdown', [ MoCache::class, 'sync_options' ] );
			$has_shutdown or add_action( 'shutdown', [ MoCache::class, 'sync_options' ], 30 );

			/**
			 * Fires just after the plugin class has been discovered.
			 * Useful to wrap calls to `Inpsyde\MoCache::flush_cache()`
			 */
			do_action( 'inpsyde_translation_cache' );
		}
	}
}

/*
 * When installed as MU plugin, we lunch plugin workflow at 'muplugins_loaded'.
 * When self installation as MU plugin failed, run loading routine at 'plugins_loaded' as a fallback.
 */
add_action(
	$is_regular_plugin ? 'plugins_loaded' : 'muplugins_loaded',
	__NAMESPACE__ . '\\load_translation_cache',
	0
);

// Cleanup.
unset( $is_regular_plugin, $is_loaded );
