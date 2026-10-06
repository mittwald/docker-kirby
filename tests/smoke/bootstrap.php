<?php

declare(strict_types=1);

use Mittwald\KirbySmoke\Docker;

require __DIR__ . '/vendor/autoload.php';

if ((string) getenv('SMOKE_IMAGE') === '') {
	fwrite(STDERR, "SMOKE_IMAGE is not set; run the suite through scripts/smoke-test.sh IMAGE\n");
	exit(2);
}

Docker::$runId = 'kirby-smoke-' . getmypid();

// Anything a test class did not get to remove, including after a fatal error
// or Ctrl-C, still carries the run label.
register_shutdown_function([Docker::class, 'sweep']);

if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
	pcntl_signal(SIGINT, static fn () => exit(130));
	pcntl_signal(SIGTERM, static fn () => exit(143));
}
