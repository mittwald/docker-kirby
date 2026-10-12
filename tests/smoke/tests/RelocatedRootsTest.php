<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Container;
use Mittwald\KirbySmoke\SmokeTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The single-volume setup from the README: content relocated below the
 * storage root via KIRBY_ROOT_*. Media cannot be relocated.
 */
final class RelocatedRootsTest extends SmokeTestCase
{
	private static Container $kirby;

	public static function setUpBeforeClass(): void
	{
		self::$kirby = self::startHealthy('roots', [
			'-v', self::volume('single') . ':/app/storage',
			'-e', 'KIRBY_ROOT_CONTENT=/app/storage/content',
		]);
	}

	#[DataProvider('relocatedRoots')]
	public function testWasCreatedAndIsWritable(string $path): void
	{
		self::assertTrue(self::$kirby->succeeds('test', '-w', $path), "{$path} is missing or not writable");
	}

	public static function relocatedRoots(): array
	{
		return self::cases('/app/storage/content', '/app/storage/accounts');
	}

	public function testKirbyReadsPagesFromTheRelocatedContentRoot(): void
	{
		self::$kirby->exec('sh', '-c', 'mkdir -p /app/storage/content/relocated && printf "Title: Relocated\n" > /app/storage/content/relocated/default.txt');
		self::assertSame(200, self::$kirby->get('/relocated')->status);
	}

	public function testKirbyCliSeesTheRelocatedContentRoot(): void
	{
		self::assertStringContainsString('"/app/storage/content"', self::$kirby->exec('kirby', 'roots'));
	}

	// --- configurations that must not start ---------------------------------

	/**
	 * Earlier versions of the README suggested this. The web server only
	 * serves /app/public/media, so the Panel would load blank.
	 */
	public function testARelocatedMediaRootStopsTheContainerAndSaysWhy(): void
	{
		$kirby = self::start('roots2', ['-e', 'KIRBY_ROOT_MEDIA=/app/storage/media']);
		self::assertSame(1, $kirby->waitExited());
		self::assertStringContainsString('KIRBY_ROOT_MEDIA=/app/storage/media is not supported', $kirby->logs());
	}

	/** Setting the one supported value changes nothing, so it is accepted. */
	public function testTheDefaultMediaRootIsAccepted(): void
	{
		$kirby = self::startHealthy('roots3', ['-e', 'KIRBY_ROOT_MEDIA=/app/public/media/']);
		self::assertSame(200, $kirby->get('/')->status);
	}
}
