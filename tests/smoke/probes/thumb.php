<?php

// Puts an image on the home page and asks Kirby for a thumbnail of it, which
// writes the thumbnail's job file into the media root. Reports the URL paths
// of the original and the thumbnail; nothing has been rendered yet.

require '/app/kirby/bootstrap.php';

$roots = require '/usr/local/share/kirby/roots.php';
$kirby = new Kirby(['roots' => $roots('/app/public')]);

$source = $kirby->root('content') . '/home/smoke.png';
$image = imagecreatetruecolor(64, 64);
imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
imagepng($image, $source);

$file = $kirby->page('home')->file('smoke.png');

echo json_encode([
	'original' => parse_url($file->url(), PHP_URL_PATH),
	'thumb' => parse_url($file->thumb(['width' => 32])->url(), PHP_URL_PATH),
]);
