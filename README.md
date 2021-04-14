Inpsyde Translation Cache
=========================

> Improves site performance by caching translation files using WordPress object cache.

----

## Description

_'Inpsyde Translation Cache'_ provides caching of the translation `.mo` files using [WP Object Cache](http://codex.wordpress.org/Class_Reference/WP_Object_Cache) 
mechanism.
 
Performance benefit of the plugin can be seen when using [persistent cache plugin](http://codex.wordpress.org/Class_Reference/WP_Object_Cache#Persistent_Cache_Plugins)
because, by default, WordPress object cache does not 'survive' the single request and translation files are _already_
cached on a per-request basis in WP.

For this reason **the plugin, by default, does nothing when there's no persistent caching plugin installed**.

--------

## MU plugin self-installation

Considering that many plugins have their language files, to be allowed to cache plugins translations, _'Inpsyde Translation Cache'_
has to run as [MU plugin](https://codex.wordpress.org/Must_Use_Plugins).

For this reason, **_'Inpsyde Translation Cache'_ copies its main file to the MU plugin folder on installation**, 
so it can work as MU plugin.

The 'regular' plugin stays active though, but doing nothing, or better, it listed for deactivation to delete the MU
plugin copy.

In fact, when 'regular' plugin is deactivated, the MU plugin copy is deleted (unless it was modified).

Please note that on some configurations this 'self-installation' routine may not work, e.g. if MU plugins folder is not
writable.

In those cases _'Inpsyde Translation Cache'_ will continue to work as a regular plugin, but it will not be able to cache
translation files loaded before `'plugins_loaded'` hook is fired. Moreover, an admin notice is also shown to suggest users
 to manually copy main plugin file to MU plugins folder to improve performance.

--------

## Cache invalidation

_'Inpsyde Translation Cache'_ stores all the text domains:

- core
- plugins
- themes

it means that when a new version of those is installed, the cache need to cleared.

### Automatic invalidation

For invalidation of WordPress core translations the plugin relies on WordPress version: if that change all cached core 
translations are invalidated.

For plugin and themes, things are less straightforward.

_'Inpsyde Translation Cache'_ uses [`'switch_theme'`](https://developer.wordpress.org/reference/functions/switch_theme/) 
hook to invalidate cache of both the 'old' and the 'new' theme.

It also uses [`'activated_plugin'`](https://developer.wordpress.org/reference/hooks/activated_plugin/) and
 [`'deactivated_plugin'`](https://developer.wordpress.org/reference/hooks/deactivated_plugin/) to invalidate cache for 
 plugins.

However, to invalidate cache of themes and plugins _'Inpsyde Translation Cache'_ needs to know the text domain they use.

For that scope, _'Inpsyde Translation Cache'_ uses 'TextDomain' file header of plugin and themes.

Considering that 'TextDomain' file header is not mandatory, it is possible that some plugins or themes that don't provide 
that header (even if they should).

### Manual invalidation
 
In those cases it is possible to invalidate the cache 'manually' by calling `Inpsyde\MoCache::flush_cache()` method.

For example, let's assume there's a plugin that loads a text domain like this:

```php
 load_plugin_textdomain( 'some-plugin-txt-domain', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); 
```

and let's assume this plugin has **not** in plugin headers something like:

```
Text Domain: some-plugin-txt-domain
```

_'Inpsyde Translation Cache'_ will not be able to flush cache for that plugin automatically when activated and deactivated.

However, 'manually' invalidating cache for that plugin would be just a matter of:

```php
function invalidate_some_plugin_translation_cache() {
	if ( class_exists( 'Inpsyde\TranslationCache\MoCache' ) ) {
		Inpsyde\TranslationCache\MoCache::flush_cache( 'some-plugin-txt-domain' );
    }
}
 
register_activation_hook(
    WP_PLUGIN_DIR . '/some-plugin-dir/some-plugin-filename.php',
    'invalidate_some_plugin_translation_cache'
);

register_deactivation_hook(
    WP_PLUGIN_DIR . '/some-plugin-dir/some-plugin-filename.php',
    'invalidate_some_plugin_translation_cache'
);
```

Of course, when it is possible to edit the plugin to include the `Text Domain` plugin header, it is surely better,
because _'Inpsyde Translation Cache'_ will be able to invalidate the cache for plugin translations automatically.

For themes the workflow is very similar, just use `'switch_theme'` hook to intercept theme change.

### Invalidation by version

There's another way to invalidate cached translations.

_'Inpsyde Translation Cache'_ builds an unique key for the combination of text domain and `.mo` file path.

This unique key is built using an 'hash' of:

- text domain
- `.mo` file path
- _'Inpsyde Translation Cache'_ own version
- an arbitrary 'version' string that can be set via `mocache_cache_version` filter

It means that using `mocache_cache_version` filter it is possible to invalidate all the stored keys for a 
given text domain.

For example, assuming same plugin as above we could do:

```php
add_filter( 'mocache_cache_version', function( $version, $domain ) {
    $plugin_path = WP_PLUGIN_DIR . '/some-plugin-dir/some-plugin-filename.php';
    $headers     = get_plugin_data( $plugin_file_path, FALSE, FALSE );
    
    return $headers['Version'];
}, 10, 2 );
```

So we use the 'Version' plugin header to invalidate the cache whenever the version change.

Compared to the 'manual' approach above, this approach has the benefit to do not explicitly call _'Inpsyde Translation Cache'_
 objects, so there's no need to check for class existence, in fact, if _'Inpsyde Translation Cache'_ is not installed or 
 not activated, then the code does nothing with no effect.

But it also has two flaws:

- Cache entries for 'old' versions are **not** deleted, they are just ignored because there's a new version.
  However, they will be discarded when 'natural' expiration for keys is reached and considering that, by default, the
  'natural' expiration is 12 hours, this issue should not be that critical.
- A code like the one above requires the plugin to define the version in headers and to be effective it relies on the 
  version be updated every time any translation file is changed.
  Even if these are common practices, theme / plugins authors may just ignore or forget them.

So, again, the suggestion is to use `Text Domain` header whenever possible, maybe suggesting to maintainers of 
third party plugins and themes to add it when not present.

--------

## Available hooks

_'Inpsyde Translation Cache'_ provides some hooks for customization. Most of them will only work properly from a MU plugin, 
especially if _'Inpsyde Translation Cache'_ is running as MU plugin.

Available **action** hooks:

- **`inpsyde_translation_cache`**, Fires just after the plugin class has been loaded.
   Useful to wrap calls to `Inpsyde\TranslationCache\MoCache::flush_cache()`
   
Available **filter** hooks:

- **`mocache_cache_enabled`** Can be used to disable plugin programmatically returning `false` via hooked callback.
  
- **`mocache_cache_version`** Can be used to invalidate the cache for given text domain (passed as argument)
  If returned version change, cache is invalidated. See _' Invalidation by version'_ above.
 
- **`mocache_cache_expire`** Can be used to customize the duration in seconds of cached values. Returning a value
  less or equal to zero prevent cache to be done at all.

--------

## Installation

_'Inpsyde Translation Cache'_ is available via Composer with package name **`inpsyde/mo-cache`**, but it does not
require Composer to be installed or used.

The 'classical' installation method (_download_ -> _put in plugins folder_ -> _activate_) works as well.

--------

## Requirements

 * PHP 5.5 or higher.
 * WordPress, tested currently in version 5.1
 
--------

## License

Copyright (c) since 2016 Inpsyde GmbH.

_'Inpsyde Translation Cache'_ code is licensed under [this license](./LICENSE).

_'Inpsyde Translation Cache'_ incorporates work covered by the following copyright and
permission notices:

 - ['MO Cache' WordPress plugin](https://wordpress.org/plugins/mo-cache/),
   Copyright (c) Masaki Takeuchi (m4i)
   Released under the MIT.


```
  ___                           _      
 |_ _|_ __  _ __  ___ _   _  __| | ___ 
  | || '_ \| '_ \/ __| | | |/ _` |/ _ \
  | || | | | |_) \__ \ |_| | (_| |  __/
 |___|_| |_| .__/|___/\__, |\__,_|\___|
           |_|        |___/            
```

The team at [Inpsyde](https://inpsyde.com) is engineering the Web since 2006.
