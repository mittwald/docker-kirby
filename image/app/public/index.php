<?php

/**
 * Front controller for the mittwald/kirby container images.
 *
 * The image uses Kirby's public/private folder setup: only `/app/public` is
 * exposed by the web server, everything else lives one level above the
 * document root. The writable directories are collected in `/app/storage` so
 * that a deployment only has to persist a handful of well-known paths.
 *
 * Every root can be relocated with an environment variable, which makes it
 * possible to point content, storage and media at a single mounted volume
 * without rebuilding the image.
 *
 * @see https://getkirby.com/docs/guide/configuration/custom-folder-setup
 */

require __DIR__ . '/../kirby/bootstrap.php';

/**
 * Reads a root from the environment and falls back to the image default.
 */
$root = function (string $name, string $default): string {
	$value = getenv('KIRBY_ROOT_' . $name);

	return is_string($value) && $value !== '' ? rtrim($value, '/') : $default;
};

$base    = $root('BASE', dirname(__DIR__));
$storage = $root('STORAGE', $base . '/storage');

echo (new Kirby([
	'roots' => [
		'index'    => __DIR__,
		'base'     => $base,
		'content'  => $root('CONTENT', $base . '/content'),
		'site'     => $root('SITE', $base . '/site'),
		'media'    => $root('MEDIA', __DIR__ . '/media'),
		'storage'  => $storage,
		'accounts' => $root('ACCOUNTS', $storage . '/accounts'),
		'cache'    => $root('CACHE', $storage . '/cache'),
		'sessions' => $root('SESSIONS', $storage . '/sessions'),
		'logs'     => $root('LOGS', $storage . '/logs'),
	],
]))->render();
