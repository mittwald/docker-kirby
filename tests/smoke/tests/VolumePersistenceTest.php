<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Container;
use Mittwald\KirbySmoke\Response;
use Mittwald\KirbySmoke\SmokeTestCase;

/**
 * The declared volumes survive a container replacement, and what is written
 * through Kirby's roots lands in them.
 */
final class VolumePersistenceTest extends SmokeTestCase
{
	private static Response $seeded;

	private static Container $replacement;

	public static function setUpBeforeClass(): void
	{
		$volumes = [
			'-v', self::volume('content') . ':/app/content',
			'-v', self::volume('storage') . ':/app/storage',
			'-v', self::volume('media') . ':/app/public/media',
		];

		$first = self::startHealthy('vol1', $volumes);
		self::$seeded = $first->get('/');
		$first->exec('sh', '-c', 'mkdir -p /app/content/persisted && printf "Title: Persisted\n" > /app/content/persisted/default.txt');
		$first->exec('sh', '-c', 'printf "written\n" > /app/storage/cache/smoke-test');
		self::stop($first);

		self::$replacement = self::startHealthy('vol2', $volumes);
	}

	public function testSeededContentIsVisibleThroughTheVolume(): void
	{
		self::assertStringContainsString('<h1>Home</h1>', self::$seeded->body);
	}

	public function testPageCreatedInTheContentVolumeSurvives(): void
	{
		self::assertSame(200, self::$replacement->get('/persisted')->status);
	}

	public function testStorageVolumeSurvives(): void
	{
		self::assertSame('written', self::$replacement->exec('cat', '/app/storage/cache/smoke-test'));
	}
}
