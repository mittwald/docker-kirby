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
 * The options are returned as nested arrays, the same shape a hand-written
 * config file has. Flat dotted keys such as `email.transport.host` would only
 * be found when Kirby asks for exactly that key, and Kirby reads several
 * option groups as a whole instead: `option('email')`, `option('thumbs')` and
 * `option('cache.pages')` would all miss them.
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

$set = static function (array &$array, string $path, mixed $value): void {
	$keys = explode('.', $path);
	$last = array_pop($keys);
	$node = &$array;

	foreach ($keys as $key) {
		if (is_array($node[$key] ?? null) === false) {
			$node[$key] = [];
		}

		$node = &$node[$key];
	}

	$node[$last] = $value;
};

/**
 * option path => [env var, cast]
 *
 * Casts: 'string', 'bool', 'int', 'list' (comma separated), 'security'.
 */
$map = [
	'url'                           => ['KIRBY_URL', 'string'],
	'debug'                         => ['KIRBY_DEBUG', 'bool'],
	'panel.install'                 => ['KIRBY_PANEL_INSTALL', 'bool'],
	'panel.slug'                    => ['KIRBY_PANEL_SLUG', 'string'],
	'panel'                         => ['KIRBY_PANEL', 'bool'],
	'languages'                     => ['KIRBY_LANGUAGES', 'bool'],
	'smartypants'                   => ['KIRBY_SMARTYPANTS', 'bool'],
	'date.handler'                  => ['KIRBY_DATE_HANDLER', 'string'],
	'content.locking'               => ['KIRBY_CONTENT_LOCKING', 'bool'],
	'cache.pages.active'            => ['KIRBY_CACHE_PAGES', 'bool'],
	'cache.pages.type'              => ['KIRBY_CACHE_PAGES_TYPE', 'string'],
	'thumbs.driver'                 => ['KIRBY_THUMBS_DRIVER', 'string'],
	'thumbs.quality'                => ['KIRBY_THUMBS_QUALITY', 'int'],
	'api.basicAuth'                 => ['KIRBY_API_BASIC_AUTH', 'bool'],
	'api.allowInsecure'             => ['KIRBY_API_ALLOW_INSECURE', 'bool'],
	'auth.methods'                  => ['KIRBY_AUTH_METHODS', 'list'],
	'auth.trials'                   => ['KIRBY_AUTH_TRIALS', 'int'],
	'auth.challenge.email.from'     => ['KIRBY_AUTH_EMAIL_FROM', 'string'],
	'auth.challenge.email.fromName' => ['KIRBY_AUTH_EMAIL_FROM_NAME', 'string'],
	'email.transport.type'          => ['KIRBY_EMAIL_TRANSPORT', 'string'],
	'email.transport.host'          => ['KIRBY_EMAIL_HOST', 'string'],
	'email.transport.port'          => ['KIRBY_EMAIL_PORT', 'int'],
	'email.transport.username'      => ['KIRBY_EMAIL_USER', 'string'],
	'email.transport.security'      => ['KIRBY_EMAIL_SECURITY', 'security'],
];

foreach ($map as $option => [$variable, $cast]) {
	if (($value = $read($variable)) === null) {
		continue;
	}

	$value = match ($cast) {
		'bool' => $bool($value),
		'int'  => (int)$value,
		'list' => array_values(array_filter(array_map('trim', explode(',', $value)))),
		// Kirby takes the protocol by name, or `true` to derive it from the
		// port, which only works for 587 and 465.
		'security' => match (strtolower($value)) {
			'tls', 'ssl' => strtolower($value),
			default      => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
				?? throw new InvalidArgumentException($variable . ' must be tls, ssl, true or false, got "' . $value . '"'),
		},
		default => $value,
	};

	// `panel` is both a switch and the parent of `panel.*`. Kirby only checks
	// the switch for `false`, so switching it on is the default and must not
	// replace the nested options that are already set.
	if ($value === true && is_array($options[$option] ?? null)) {
		continue;
	}

	$set($options, $option, $value);
}

// The SMTP password is read separately so that it can also come from a mounted
// secret file instead of the process environment.
$password = $read('KIRBY_EMAIL_PASSWORD');

if ($password === null && ($file = $read('KIRBY_EMAIL_PASSWORD_FILE')) !== null && is_readable($file)) {
	$password = trim((string)file_get_contents($file));
}

if ($password !== null) {
	$set($options, 'email.transport.password', $password);
}

// Kirby only logs in to the SMTP server when `auth` is set, so credentials
// on their own would be silently ignored.
if (isset($options['email']['transport']['username']) || isset($options['email']['transport']['password'])) {
	$options['email']['transport']['auth'] = true;
}

if (($json = $read('KIRBY_OPTIONS_JSON')) !== null) {
	$decoded = json_decode($json, true);

	if (is_array($decoded) === false) {
		throw new InvalidArgumentException('KIRBY_OPTIONS_JSON is not a valid JSON object: ' . json_last_error_msg());
	}

	// Option groups are merged, so `{"email":{"presets":…}}` keeps the
	// transport built from the KIRBY_EMAIL_* variables. Lists such as
	// `routes` or `auth.methods` are replaced as a whole; merging them by
	// index would mix the two lists.
	$merge = static function (array $base, array $override) use (&$merge): array {
		foreach ($override as $key => $value) {
			$current = $base[$key] ?? null;

			$base[$key] = is_array($value) && is_array($current) && array_is_list($value) === false && array_is_list($current) === false
				? $merge($current, $value)
				: $value;
		}

		return $base;
	};

	$options = $merge($options, $decoded);
}

return $options;
