<?php

/**
 * Front controller for the mittwald/kirby container images.
 *
 * The image uses Kirby's public/private folder setup: only `/app/public` is
 * exposed by the web server, everything else lives one level above the
 * document root. The writable directories are collected in `/app/storage` so
 * that a deployment only has to persist a handful of well-known paths.
 *
 * The roots themselves, including their KIRBY_ROOT_* overrides, are declared
 * in `/usr/local/share/kirby/roots.php`, which the entrypoint's scripts use
 * as well. `/usr/local/share/kirby/install-guard.php` keeps the Panel
 * installer from remote visitors, whatever Host header they send.
 *
 * @see https://getkirby.com/docs/guide/configuration/custom-folder-setup
 */

// PHP copies the server's environment into $_SERVER and $_ENV, which Kirby's
// error page lists with `debug` on. The variables that carry the image's
// secrets leave both here, before Kirby boots, so no config can put them back
// on that page. `env-options.php` reads them with getenv(), which this does
// not touch.
foreach (require '/usr/local/share/kirby/sensitive-env.php' as $variable) {
	unset($_SERVER[$variable], $_ENV[$variable]);
}

require __DIR__ . '/../kirby/bootstrap.php';

$roots        = require '/usr/local/share/kirby/roots.php';
$installGuard = require '/usr/local/share/kirby/install-guard.php';

$kirby = new Kirby([
	'roots' => $roots(__DIR__),
]);

echo $installGuard($kirby) ?? $kirby->render();
