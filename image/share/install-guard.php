<?php

/**
 * Keeps Kirby's Panel installer from remote visitors.
 *
 * Kirby lets anyone create the first account through
 * `POST <api.slug>/system/install` while no account exists, if the
 * installation counts as local or `panel.install` is switched on. It counts
 * as local as soon as the host it detected is `localhost` or ends in
 * `.local`, `.test` or `.ddev.site`, before it looks at any address. Without
 * the `url` option that host is SERVER_NAME, which FrankenPHP takes from the
 * request: the Host header, or the host of an absolute request target. On a
 * site address that is a bare port, the image's default, any client could
 * claim to be local that way and create an admin account.
 *
 * So the installer only answers a visitor on the container's loopback
 * interface, or any visitor once `panel.install` is switched on. REMOTE_ADDR
 * is loopback only when the TCP peer is: the Caddyfile never takes a
 * forwarded loopback address at its word. The request is matched on the path
 * Kirby itself routes, so a different spelling of the same route, with extra
 * slashes or URL params, is refused as well, whatever its method.
 *
 * Returns a function that takes the Kirby instance and returns the response
 * that refuses the request, or null to let Kirby handle it.
 *
 * @see https://getkirby.com/docs/reference/system/options/panel
 */

use Kirby\Cms\App;
use Kirby\Http\Response;

return static function (App $kirby): Response|null {
	// The same condition Kirby applies on top of its own check.
	if ($kirby->option('panel.install', false) === true) {
		return null;
	}

	if (in_array($_SERVER['REMOTE_ADDR'] ?? null, ['127.0.0.1', '::1'], true) === true) {
		return null;
	}

	$segments = static fn (string $path): array => array_values(array_filter(
		explode('/', $path),
		static fn (string $segment): bool => $segment !== ''
	));

	$installer = [...$segments((string)$kirby->option('api.slug', 'api')), 'system', 'install'];

	if ($segments($kirby->path()) !== $installer) {
		return null;
	}

	return Response::json([
		'status'  => 'error',
		'code'    => 403,
		'key'     => 'error.permission',
		'message' => 'The Panel installer only accepts requests from inside the container. Create the first account with KIRBY_ADMIN_EMAIL and KIRBY_ADMIN_PASSWORD, or set KIRBY_PANEL_INSTALL=true until it exists.',
	], 403);
};
