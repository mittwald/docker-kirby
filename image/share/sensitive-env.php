<?php

/**
 * The environment variables through which the image takes secrets.
 *
 * PHP copies the server's environment into $_SERVER and $_ENV, and with
 * `debug` on, Kirby's error page lists both to whoever triggers an error. Two
 * places use this list:
 *
 * - the front controller removes these variables from $_SERVER and $_ENV
 *   before Kirby boots, whatever the site's config says. `getenv()`, which
 *   is what `env-options.php` reads them with, is not affected.
 * - `env-options.php` puts them on the error page's `whoops.blocklist`, for
 *   entry points that do not go through the front controller.
 *
 * Returns the variable names.
 */

$secrets = require __DIR__ . '/secrets.php';

return [
	'KIRBY_ADMIN_PASSWORD',
	'KIRBY_EMAIL_PASSWORD',
	'KIRBY_LICENSE',
	'KIRBY_OPTIONS_JSON',
	// Only the variable names are used here, not the storage paths.
	...array_column($secrets(''), 0),
];
