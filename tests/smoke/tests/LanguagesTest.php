<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Container;
use Mittwald\KirbySmoke\SmokeTestCase;

/**
 * Languages live in the site root. The Panel may only add them where that
 * root outlives the container, and content written for languages the site
 * has lost stops the container instead of being served empty.
 */
final class LanguagesTest extends SmokeTestCase
{
	/** @var array<string, mixed> */
	private static array $ephemeral;

	/** @var array<string, mixed> */
	private static array $persisted;

	private static Container $replacement;

	private static Container $lost;

	public static function setUpBeforeClass(): void
	{
		$content = ['-v', self::volume('content') . ':/app/content'];
		$site = ['--mount', 'type=volume,src=' . self::volume('site') . ',dst=/app/site,volume-nocopy'];

		$ephemeral = self::startHealthy('lang-ephemeral');
		self::$ephemeral = $ephemeral->probe('language.php', ['LANGUAGE_CODE' => 'en']);
		self::stop($ephemeral);

		// Root, because the fresh site volume is owned by root and only the
		// root start may take ownership of it.
		$first = self::startHealthy('lang1', ['--user', '0:0', ...$content, ...$site]);
		self::$persisted = $first->probe('language.php', ['LANGUAGE_CODE' => 'en']);
		self::stop($first);

		self::$replacement = self::startHealthy('lang2', [...$content, ...$site]);

		// The content volume without the site that defines its language.
		self::$lost = self::start('lang-lost', $content);
	}

	public function testTheEphemeralSiteRefusesANewLanguage(): void
	{
		self::assertFalse(self::$ephemeral['created']);
		self::assertStringContainsString('Mount a volume at /app/site', (string) self::$ephemeral['error']);
	}

	public function testTheRefusedLanguageLeftTheContentAlone(): void
	{
		self::assertSame([], self::$ephemeral['languages']);
		self::assertSame(['site.txt'], self::$ephemeral['content']);
	}

	public function testThePersistedSiteAcceptsANewLanguage(): void
	{
		self::assertTrue(self::$persisted['created'], (string) self::$persisted['error']);
		self::assertSame(['en.php'], self::$persisted['languages']);
		self::assertSame(['site.en.txt'], self::$persisted['content']);
	}

	public function testTheLanguageSurvivesAReplacement(): void
	{
		$response = self::$replacement->get('/en');

		self::assertSame(200, $response->status);
		self::assertStringContainsString('<h1>Home</h1>', $response->body);
	}

	public function testContentForALostLanguageStopsTheContainer(): void
	{
		self::assertSame(1, self::$lost->waitExited());

		$logs = self::$lost->logs();
		self::assertStringContainsString('holds content in the languages en', $logs);
		self::assertStringContainsString('Restore site/languages/en.php', $logs);
	}
}
