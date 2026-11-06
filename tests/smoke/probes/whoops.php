<?php

// The `whoops` option Kirby ends up with, after the site's config and
// KIRBY_OPTIONS_JSON have been merged with the image's defaults.

require '/app/kirby/bootstrap.php';

$roots = require '/usr/local/share/kirby/roots.php';
$kirby = new Kirby(['roots' => $roots('/app/public')]);

echo json_encode(['whoops' => $kirby->option('whoops')]);
