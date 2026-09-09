<?php

/**
 * Maps container environment variables onto Kirby config options.
 *
 * Kirby has no native support for environment based configuration, so the
 * image provides this translation layer. It lives outside `/app` on purpose:
 * downstream images replace `site/config/config.php` with their own file and
 * can still pull the environment defaults in with a single `require`.
 *
 *     <?php
 *     return array_replace_recursive(
 *         require '/usr/local/share/kirby/env-options.php',
 *         ['smartypants' => true]
 *     );
 *
 * Only variables that are actually set end up in the returned array, so
 * anything left untouched keeps Kirby's own default. `KIRBY_OPTIONS_JSON` is
 * the escape hatch for options that have no dedicated variable; it is merged
 * last and therefore wins.
 *
 * @see https://getkirby.com/docs/reference/system/options
 */

$options = [];

$read = static function (string $name): ?string {
	$value = getenv($name);

	return is_string($value) && $value !== '' ? $value : null;
};

$bool = static function (string $value): bool {
	return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
};

/**
 * option name => [env var, cast]
 *
 * Casts: 'string', 'bool', 'int', 'list' (comma separated).
 */
$map = [
	'url'                  => ['KIRBY_URL', 'string'],
	'debug'                => ['KIRBY_DEBUG', 'bool'],
	'panel.install'        => ['KIRBY_PANEL_INSTALL', 'bool'],
	'panel.slug'           => ['KIRBY_PANEL_SLUG', 'string'],
	'panel'                => ['KIRBY_PANEL', 'bool'],
	'languages'            => ['KIRBY_LANGUAGES', 'bool'],
	'smartypants'          => ['KIRBY_SMARTYPANTS', 'bool'],
	'date.handler'         => ['KIRBY_DATE_HANDLER', 'string'],
	'content.locking'      => ['KIRBY_CONTENT_LOCKING', 'bool'],
	'cache.pages.active'   => ['KIRBY_CACHE_PAGES', 'bool'],
	'cache.pages.type'     => ['KIRBY_CACHE_PAGES_TYPE', 'string'],
	'thumbs.driver'        => ['KIRBY_THUMBS_DRIVER', 'string'],
	'thumbs.quality'       => ['KIRBY_THUMBS_QUALITY', 'int'],
	'api.basicAuth'        => ['KIRBY_API_BASIC_AUTH', 'bool'],
	'api.allowInsecure'    => ['KIRBY_API_ALLOW_INSECURE', 'bool'],
	'auth.methods'         => ['KIRBY_AUTH_METHODS', 'list'],
	'auth.trials'          => ['KIRBY_AUTH_TRIALS', 'int'],
	'email.transport.type' => ['KIRBY_EMAIL_TRANSPORT', 'string'],
	'email.transport.host' => ['KIRBY_EMAIL_HOST', 'string'],
	'email.transport.port' => ['KIRBY_EMAIL_PORT', 'int'],
	'email.transport.user' => ['KIRBY_EMAIL_USER', 'string'],
	'email.transport.security' => ['KIRBY_EMAIL_SECURITY', 'bool'],
];

foreach ($map as $option => [$variable, $cast]) {
	if (($value = $read($variable)) === null) {
		continue;
	}

	$options[$option] = match ($cast) {
		'bool' => $bool($value),
		'int'  => (int)$value,
		'list' => array_values(array_filter(array_map('trim', explode(',', $value)))),
		default => $value,
	};
}

// The SMTP password is read separately so that it can also come from a mounted
// secret file instead of the process environment.
if (isset($options['email.transport.host'])) {
	$password = $read('KIRBY_EMAIL_PASSWORD');

	if ($password === null && ($file = $read('KIRBY_EMAIL_PASSWORD_FILE')) !== null && is_readable($file)) {
		$password = trim((string)file_get_contents($file));
	}

	if ($password !== null) {
		$options['email.transport.password'] = $password;
	}
}

if (($json = $read('KIRBY_OPTIONS_JSON')) !== null) {
	$decoded = json_decode($json, true);

	if (is_array($decoded) === false) {
		throw new InvalidArgumentException('KIRBY_OPTIONS_JSON is not a valid JSON object: ' . json_last_error_msg());
	}

	$options = array_replace($options, $decoded);
}

return $options;
