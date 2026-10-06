<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke;

use PHPUnit\Framework\IncompleteTest;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base class for a smoke test scenario. A scenario starts its containers in
 * setUpBeforeClass() through the helpers here, which keep track of them and
 * remove them, with any volumes, networks and directories, once the class
 * is done.
 */
abstract class SmokeTestCase extends TestCase
{
	/** @var array<string, Container> */
	private static array $containers = [];

	/** @var list<string> */
	private static array $networks = [];

	/** @var list<string> */
	private static array $volumes = [];

	/** @var list<string> */
	private static array $directories = [];

	protected static function image(): string
	{
		return (string) getenv('SMOKE_IMAGE');
	}

	/**
	 * The Kirby or PHP version the image is expected to contain, or a skip if
	 * the caller did not say.
	 */
	protected static function expected(string $what): string
	{
		$value = getenv('SMOKE_' . strtoupper($what) . '_VERSION');
		if ($value === false || $value === '') {
			self::markTestSkipped("no --{$what}-version given");
		}
		return $value;
	}

	/**
	 * @param list<string> $args docker run options
	 * @param list<int> $ports container ports the test talks to
	 */
	protected static function start(string $name, array $args = [], ?string $image = null, array $ports = [80, 8090]): Container
	{
		$container = Container::start($image ?? self::image(), self::resourceName($name), $args, $ports);
		self::$containers[$container->name] = $container;
		return $container;
	}

	/** @param list<string> $args docker run options */
	protected static function startHealthy(string $name, array $args = []): Container
	{
		$container = self::start($name, $args);
		$container->waitHealthy();
		return $container;
	}

	protected static function stop(Container $container): void
	{
		$container->remove();
		unset(self::$containers[$container->name]);
	}

	protected static function volume(string $name): string
	{
		return self::$volumes[] = Docker::createVolume(self::resourceName($name));
	}

	protected static function network(string $name): string
	{
		return self::$networks[] = Docker::createNetwork(self::resourceName($name));
	}

	protected static function directory(): string
	{
		$path = sys_get_temp_dir() . '/' . uniqid(Docker::$runId . '-', true);
		mkdir($path, 0700);
		return self::$directories[] = $path;
	}

	/**
	 * For data providers: one case per value, named after it.
	 *
	 * @return array<string, array{string}>
	 */
	protected static function cases(string ...$values): array
	{
		return array_combine($values, array_map(static fn (string $value) => [$value], $values));
	}

	public static function tearDownAfterClass(): void
	{
		foreach (self::$containers as $container) {
			$container->remove();
		}
		foreach (self::$networks as $network) {
			Docker::attempt(['network', 'rm', $network]);
		}
		foreach (self::$volumes as $volume) {
			Docker::attempt(['volume', 'rm', '-f', $volume]);
		}
		foreach (self::$directories as $directory) {
			// May fail for files a container chowned; the runner is
			// ephemeral and a local temp dir gets cleaned eventually.
			exec('rm -rf ' . escapeshellarg($directory) . ' 2>/dev/null');
		}

		self::$containers = self::$networks = self::$volumes = self::$directories = [];
	}

	/**
	 * Prints the logs of the containers still running, so a failure can be
	 * diagnosed from the CI output alone. Architectures have differed before,
	 * and there is no reproducing an arm64 failure on an amd64 laptop.
	 */
	protected function onNotSuccessfulTest(Throwable $t): never
	{
		if (!$t instanceof SkippedTest && !$t instanceof IncompleteTest) {
			foreach (self::$containers as $container) {
				fwrite(STDERR, "\n--- logs of {$container->name}\n" . $container->logs());
			}
		}

		throw $t;
	}

	private static function resourceName(string $name): string
	{
		return Docker::$runId . '-' . $name;
	}
}
