<?php # -*- coding: utf-8 -*-
/**
 * This file is part of the inpsyde-translation-cache package.
 *
 * (c)  Inpsyde GmbH
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Inpsyde MO Cache incorporates work covered by the following copyright and
 * permission notices:
 *
 *     "MO Cache" WordPress plugin
 *     https://wordpress.org/plugins/mo-cache/ - https://github.com/m4i/wordpress-mo-cache
 *     Copyright (c) 2011 Masaki Takeuchi (m4i)
 *     Released under the MIT.
 *
 * @author  Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 * @author  Masaki Takeuchi
 * @package inpsyde-translation-cache
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace Inpsyde\TranslationCache;

/**
 * Class MoCache
 *
 * @package Inpsyde\TranslationCache
 */
class MoCache {

	const VERSION        = '1.0.1';
	const GROUP          = 'mo_cache';
	const KEYS_OPTION    = 'inpsyde_translation_cache';
	const DEFAULT_EXPIRE = 43200; // 12 hours

	/**
	 * Store options for each domain.
	 *
	 * @var array
	 */
	private static $options;

	/**
	 * Flush cache for given domain(s).
	 *
	 * @param string|string[] $domains The given domains.
	 *
	 * @return bool
	 */
	public static function flush_cache( $domains ) {

		$options = is_array( self::$options ) ? self::$options : get_site_option( self::KEYS_OPTION, [] );
		$flushed = 0;

		foreach ( (array) $domains as $domain ) {
			if ( ! $domain || ! is_string( $domain ) || ! array_key_exists( $domain, $options ) ) {
				continue;
			}

			$flushed ++;

			$keys = $options[ $domain ];
			unset( $options[ $domain ] );

			array_walk(
				$keys,
				function ( $key ) {

					wp_cache_delete( $key, self::GROUP );
				}
			);
		}

		self::$options = $options;

		return $flushed > 0;
	}

	/**
	 * Flush all cached translations.
	 */
	public static function flush_all() {

		$options       = is_array( self::$options ) ? self::$options : get_site_option( self::KEYS_OPTION, [] );
		self::$options = [];

		/**
		 * The stored options.
		 *
		 * @var array $options
		 */
		foreach ( $options as $domain => $keys ) {
			array_walk(
				$keys,
				function ( $key ) {

					wp_cache_delete( $key, self::GROUP );
				}
			);
		}

		delete_site_option( self::KEYS_OPTION );

		return TRUE;
	}

	/**
	 * Runs when theme is switched and tries to invalidate the cache for bot old and new theme.
	 *
	 * @wp-hook switch_theme
	 *
	 * @param string    $new_theme_name New theme string.
	 * @param \WP_Theme $new_theme New Theme.
	 * @param \WP_Theme $old_theme Old Theme.
	 *
	 * @return bool
	 */
	public static function on_theme_switch( $new_theme_name, \WP_Theme $new_theme, \WP_Theme $old_theme ) {

		$domains = [
			$old_theme->get( 'TextDomain' ),
			$new_domain = $new_theme->get( 'TextDomain' ),
		];

		return self::flush_cache( $domains );
	}

	/**
	 * Runs when a plugin is activated and deactivated, trying to invalidate the cache for it.
	 *
	 * @wp-hook activated_plugin
	 * @wp-hook deactivate_plugin
	 *
	 * @param string $plugin_file The plugin file name string.
	 *
	 * @return bool
	 */
	public static function on_plugin_switch( $plugin_file ) {

		if ( ! is_file( $plugin_file ) ) {
			$plugin_file = trailingslashit( WP_PLUGIN_DIR ) . $plugin_file;
		}

		if ( ! is_file( $plugin_file ) ) {
			return FALSE;
		}

		$headers = get_plugin_data( $plugin_file, FALSE, FALSE );
		if ( $headers && isset( $headers[ 'TextDomain' ] ) ) {
			return self::flush_cache( [ $headers[ 'TextDomain' ] ] );
		}

		return FALSE;

	}

	/**
	 * Sync options with database.
	 *
	 * @wp-hook shutdown
	 */
	public static function sync_options() {

		if ( ! is_array( self::$options ) ) {
			return;
		}

		$cache_found_filter = function ( $key ) {

			$found = FALSE;
			is_string( $key ) and wp_cache_get( $key, self::GROUP, TRUE, $found );

			return $found;
		};

		// Remove keys that can't be found in cache anymore.
		array_walk(
			self::$options,
			function ( &$keys ) use ( $cache_found_filter ) {

				$keys = array_filter( (array) $keys, $cache_found_filter );
			}
		);

		array_filter( self::$options );

		get_site_option( self::KEYS_OPTION )
			? update_site_option( self::KEYS_OPTION, self::$options )
			: add_site_option( self::KEYS_OPTION, self::$options );
	}

	/**
	 * Overrides default loading of .mo file, by loading translation values from cache.
	 *
	 * @wp-hook override_load_textdomain
	 *
	 * @param bool   $override Whether to override the .mo file loading. Default false.
	 * @param string $domain   Text domain. Unique identifier for retrieving translated strings.
	 * @param string $mo_file  Path to the MO file.
	 *
	 * @return bool
	 */
	public static function load( $override, $domain, $mo_file ) {

		$instance = new static();

		return $instance->override_load_textdomain( $override, $domain, $mo_file );
	}

	/**
	 * Rewrite the textdomain load with cached values.
	 *
	 * @param bool   $override Whether to override the .mo file loading. Default false.
	 * @param string $domain   Text domain. Unique identifier for retrieving translated strings.
	 * @param string $mo_file  Path to the MO file.
	 *
	 * @return bool
	 */
	public function override_load_textdomain( $override, $domain, $mo_file ) {

		if ( ! $this->is_enabled( $domain, $mo_file ) ) {
			return FALSE;
		}

		/**
		 * Fires before the MO translation file is loaded.
		 *
		 * @param string $domain Text domain. Unique identifier for retrieving translated strings.
		 * @param string $mo_file Path to the .mo file.
		 *
		 * @see \load_textdomain()
		 */
		do_action( 'load_textdomain', $domain, $mo_file );

		/**
		 * Filters MO file path for loading translations for a specific text domain.
		 *
		 * @param string $mo_file Path to the MO file.
		 * @param string $domain Text domain. Unique identifier for retrieving translated strings.
		 *
		 * @see \load_textdomain()
		 */
		$mo_file = (string) apply_filters( 'load_textdomain_mofile', $mo_file, $domain );

		if ( function_exists( 'wp_cache_add_global_groups' ) ) {
			wp_cache_add_global_groups( self::GROUP );
		}

		$key = $this->get_key( $domain, $mo_file );

		$mo     = new \MO();
		$cached = $this->load_cache( $key, $mo );

		if ( $cached ) {
			return $this->setup_globals( $domain, $mo );
		}

		if ( is_readable( $mo_file ) && $mo->import_from_file( $mo_file ) ) {
			$this->set_cache( $key, $mo, $domain, $mo_file );
			$override = $this->setup_globals( $domain, $mo );
		}

		return $override;
	}

	/**
	 * Returns true when cache is enabled.
	 *
	 * @param string $domain  Text domain. Unique identifier for retrieving translated strings.
	 * @param string $mo_file The path to the mo file.
	 *
	 * @return bool
	 */
	private function is_enabled( $domain, $mo_file ) {

		$enabled = wp_using_ext_object_cache();

		/**
		 * Filters the value of enabled. By default is true when using external object
		 * cache, because there are no real benefit in using the plugin with default
		 * cache mechanism that does not survive the request.
		 *
		 * If filters return false, plugin do nothing.
		 *
		 * @param bool   $enabled True if plugin should work, by default when external object cache is in place.
		 * @param string $domain  Text domain. Unique identifier for retrieving translated strings.
		 * @param string $mo_file Path to the MO file.
		 */
		$enabled = apply_filters( 'mocache_cache_enabled', $enabled, $domain, $mo_file );

		return filter_var( $enabled, FILTER_VALIDATE_BOOLEAN );

	}

	/**
	 * Build a cache key from domain and mo file.
	 *
	 * @param string $domain  Text domain. Unique identifier for retrieving translated strings.
	 * @param string $mo_file The path to the mo file.
	 *
	 * @return string
	 */
	private function get_key( $domain, $mo_file ) {

		$seed       = '';
		$is_default = ! $domain || 'default' === $domain;
		if ( $is_default ) {
			$seed = $GLOBALS[ 'wp_version' ];
		}

		if ( ! $seed ) {

			/**
			 * Filters the cache version, can be used to invalidate the cache
			 * when the plugin/theme that uses the mo file change version.
			 *
			 * @param string $seed   Cache ver for given domain / mo file, when changes old cache is invalidated.
			 * @param string $domain Text domain. Unique identifier for retrieving translated strings.
			 */
			$seed = apply_filters( 'mocache_cache_version', $seed, $domain );
		}

		is_string( $seed ) or $seed = '';

		return md5( self::VERSION . $seed . $domain . $mo_file );
	}

	/**
	 * Loads MO entries and headers from cache if available.
	 *
	 * @param string $key The key of the entry.
	 * @param \MO    $mo  The translation entries.
	 *
	 * @return bool
	 */
	private function load_cache( $key, \MO $mo ) {

		$cache = wp_cache_get( $key, self::GROUP, FALSE );
		if ( is_array( $cache ) && isset( $cache[ 'entries' ], $cache[ 'headers' ] ) ) {
			$mo->entries = $cache[ 'entries' ];
			$mo->set_headers( $cache[ 'headers' ] );

			return TRUE;
		}

		return FALSE;
	}

	/**
	 * Set MO entries and headers in cache.
	 *
	 * @param string $key     The key of the entry.
	 * @param \MO    $mo      The translation entries.
	 * @param string $domain  Text domain. Unique identifier for retrieving translated strings.
	 * @param string $mo_file The path to the mo file.
	 *
	 * @return void
	 */
	private function set_cache( $key, \MO $mo, $domain, $mo_file ) {

		$cache = [
			'entries' => $mo->entries,
			'headers' => $mo->headers,
		];

		$expire = wp_using_ext_object_cache() ? self::DEFAULT_EXPIRE : 0;

		/**
		 * Filters the expiration of cache.
		 *
		 * @param int    $expire  Time-to-live in seconds, 0 means no limit.
		 * @param string $domain  Text domain. Unique identifier for retrieving translated strings.
		 * @param string $mo_file Path to the MO file.
		 */
		$expire = apply_filters( 'mocache_cache_expire', $expire, $domain, $mo_file );

		// If filters messed up expire, just use a minimum value.
		is_int( $expire ) or $expire = MINUTE_IN_SECONDS;

		$set = $expire >= 0 ? wp_cache_set( $key, $cache, self::GROUP, $expire ) : FALSE;

		if ( $set && $domain && 'default' !== $domain ) {
			// We store options of all generated keys.
			$options = is_array( self::$options ) ? self::$options : get_site_option( self::KEYS_OPTION, [] );
			array_key_exists( $domain, $options ) or $options[ $domain ] = [];
			in_array( $key, $options[ $domain ], TRUE ) or $options[ $domain ][] = $key;
			self::$options = $options;
		}
	}

	/**
	 * Set MO in global $l10n array, merging with any existing MO value.
	 *
	 * @param string $domain Text domain. Unique identifier for retrieving translated strings.
	 * @param \MO    $mo     The path to the mo file.
	 *
	 * @return bool
	 */
	private function setup_globals( $domain, \MO $mo ) {

		global $l10n;

		array_key_exists( $domain, (array) $l10n ) and $mo->merge_with( $l10n[ $domain ] );
		$l10n[ $domain ] = $mo;

		return TRUE;
	}
}
