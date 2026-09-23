<?php

/**
 * The roots of the mittwald/kirby images.
 *
 * Shared by the front controller and the scripts the entrypoint runs before
 * the server starts, so that both find the same accounts, content and storage.
 * Returns a function that takes the document root and builds the roots below
 * and next to it.
 *
 * Every root can be relocated with an environment variable, which makes it
 * possible to point content, storage and media at a single mounted volume
 * without rebuilding the image.
 *
 * @see https://getkirby.com/docs/guide/configuration/custom-folder-setup
 */

return static function (string $index): array {
	/**
	 * Reads a root from the environment and falls back to the image default.
	 */
	$root = static function (string $name, string $default): string {
		$value = getenv('KIRBY_ROOT_' . $name);

		return is_string($value) && $value !== '' ? rtrim($value, '/') : $default;
	};

	$base    = $root('BASE', dirname($index));
	$storage = $root('STORAGE', $base . '/storage');

	return [
		'index'    => $index,
		'base'     => $base,
		'content'  => $root('CONTENT', $base . '/content'),
		'site'     => $root('SITE', $base . '/site'),
		'media'    => $root('MEDIA', $index . '/media'),
		'storage'  => $storage,
		'accounts' => $root('ACCOUNTS', $storage . '/accounts'),
		'cache'    => $root('CACHE', $storage . '/cache'),
		'sessions' => $root('SESSIONS', $storage . '/sessions'),
		'logs'     => $root('LOGS', $storage . '/logs'),
	];
};
