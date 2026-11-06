<?php

/**
 * Moves dependencies that Kirby pins to one exact release onto the newest
 * patch release of the same minor version, without breaking Kirby's own
 * requirement.
 *
 * Kirby requires each of its dependencies in exactly one version, so a fix
 * in one of them reaches an installation only with Kirby's next release.
 * Until then, Composer refuses to install the pinned release once an advisory
 * for it is published, and the build fails. The daily rebuild then stops
 * delivering PHP and distribution updates as well. symfony/yaml is the case
 * that made this necessary: Kirby 5.6.2 pins 7.4.21 and Kirby 4.9.6 pins
 * 6.4.45, both affected by GHSA-hwfg-qwvh-2wfx, fixed in 7.4.23 and 6.4.50.
 *
 * Symfony's patch releases contain bug and security fixes only, which is what
 * makes following them safe for the packages listed below. Each one is
 * required as an inline alias, `symfony/yaml:7.4.23 as 7.4.21`, so Kirby's
 * exact requirement is still met. Once Kirby pins the newest patch release
 * itself, nothing is added.
 *
 * Run by the builder stage between `composer create-project --no-install` and
 * the install, with the project directory and the Kirby release as arguments.
 * It only edits composer.json; the install that follows resolves everything
 * at once, and Composer's own advisory check still applies to the result.
 *
 * The alias stays in the composer.json the image ships as /app/composer.json:
 * the lock file and vendor/ depend on it. Derived images that run Composer
 * inherit it, and the README ("Building your own site on top") says what that
 * means for them and how to drop it.
 *
 * @see https://getcomposer.org/doc/articles/aliases.md#require-inline-alias
 */

const PACKAGES = ['symfony/yaml'];

const RELEASE = '/^v?(\d+)\.(\d+)\.(\d+)$/';

$log = static function (string $message): void {
	fwrite(STDERR, '[patch-releases] ' . $message . PHP_EOL);
};

$fail = static function (string $message) use ($log): never {
	$log('error: ' . $message);
	exit(1);
};

if (isset($argv[1], $argv[2]) === false) {
	$fail('usage: patch-releases.php PROJECT_DIR KIRBY_VERSION');
}

[, $project, $kirby] = $argv;

/**
 * Runs Composer in the project and returns its standard output. Its messages
 * on standard error go straight to the build log.
 */
$composer = static function (string ...$args) use ($project, $fail): string {
	$command = 'composer --working-dir=' . escapeshellarg($project) . ' ' . implode(' ', array_map('escapeshellarg', $args));

	exec($command, $output, $exitCode);

	if ($exitCode !== 0) {
		$fail($command . ' exited with ' . $exitCode);
	}

	return implode("\n", $output);
};

$show = static function (string ...$args) use ($composer): array {
	return json_decode($composer('show', '--available', '--format=json', ...$args), true, flags: JSON_THROW_ON_ERROR);
};

$requires = $show('getkirby/cms', $kirby)['requires'] ?? [];

foreach (PACKAGES as $package) {
	// Only an exact pin needs this. A range lets Composer pick a fixed
	// release on its own.
	if (preg_match(RELEASE, $requires[$package] ?? '', $pin) !== 1) {
		continue;
	}

	$pinned = $pin[1] . '.' . $pin[2] . '.' . $pin[3];
	$newest = $pinned;

	foreach ($show($package)['versions'] ?? [] as $version) {
		if (
			preg_match(RELEASE, $version, $release) === 1 &&
			$release[1] === $pin[1] &&
			$release[2] === $pin[2] &&
			version_compare(ltrim($version, 'v'), $newest, '>')
		) {
			$newest = ltrim($version, 'v');
		}
	}

	if ($newest === $pinned) {
		$log("Kirby {$kirby} pins {$package} {$pinned}, the newest {$pin[1]}.{$pin[2]} release");
		continue;
	}

	$composer('require', '--no-update', '--no-interaction', "{$package}:{$newest} as {$pinned}");
	$log("requiring {$package} {$newest} in place of {$pinned}, which Kirby {$kirby} pins");
}
