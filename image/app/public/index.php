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
 * as well.
 *
 * @see https://getkirby.com/docs/guide/configuration/custom-folder-setup
 */

require __DIR__ . '/../kirby/bootstrap.php';

$roots = require '/usr/local/share/kirby/roots.php';

echo (new Kirby([
	'roots' => $roots(__DIR__),
]))->render();
