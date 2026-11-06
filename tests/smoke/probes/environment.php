<?php

// Served by FrankenPHP: where PHP sees each of these variables. Everything in
// the server's environment ends up in getenv(), $_SERVER and $_ENV.

$names = ['KIRBY_ADMIN_PASSWORD', 'KIRBY_LICENSE', 'KIRBY_EMAIL_PASSWORD', 'SMOKE_VISIBLE'];

echo json_encode(array_combine($names, array_map(static fn (string $name): array => [
	'getenv' => getenv($name) !== false,
	'server' => isset($_SERVER[$name]),
	'env'    => isset($_ENV[$name]),
], $names)));
