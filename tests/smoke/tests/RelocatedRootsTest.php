<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Container;
use Mittwald\KirbySmoke\SmokeTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The single-volume setup from the README: content and media relocated below
 * the storage root via KIRBY_ROOT_*.
 */
final class RelocatedRootsTest extends SmokeTestCase
{
	private static Container $kirby;

	public static function setUpBeforeClass(): void
	{
		self::$kirby = self::startHealthy('roots', [
			'-v', self::volume('single') . ':/app/storage',
			'-e', 'KIRBY_ROOT_CONTENT=/app/storage/content',
			'-e', 'KIRBY_ROOT_MEDIA=/app/storage/media',
		]);
	}

	#[DataProvider('relocatedRoots')]
	public function testWasCreatedAndIsWritable(string $path): void
	{
		self::assertTrue(self::$kirby->succeeds('test', '-w', $path), "{$path} is missing or not writable");
	}

	public static function relocatedRoots(): array
	{
		return self::cases('/app/storage/content', '/app/storage/media', '/app/storage/accounts');
	}

	public function testKirbyReadsPagesFromTheRelocatedContentRoot(): void
	{
		self::$kirby->exec('sh', '-c', 'mkdir -p /app/storage/content/relocated && printf "Title: Relocated\n" > /app/storage/content/relocated/default.txt');
		self::assertSame(200, self::$kirby->get('/relocated')->status);
	}
}
