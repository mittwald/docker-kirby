<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Container;
use Mittwald\KirbySmoke\Response;
use Mittwald\KirbySmoke\SmokeTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * With KIRBY_DEBUG on, Kirby's error page lists $_SERVER and $_ENV to whoever
 * triggers an error, and PHP copies the server's environment into both. The
 * variables that carry secrets never show up there, whatever the site's
 * config does to `whoops`, and the two that are only needed before the
 * server starts are gone by the time it does. A variable a site adds to the
 * blocklist the way the README shows is masked as well, without unmasking
 * the image's own.
 */
final class DebugPageTest extends SmokeTestCase
{
	/** variable => [value, what must not appear on the page] */
	private const array SECRETS = [
		'KIRBY_ADMIN_PASSWORD' => ['admin-password-not-on-the-page', 'admin-password-not-on-the-page'],
		'KIRBY_LICENSE' => ['{"license":"license-not-on-the-page"}', 'license-not-on-the-page'],
		'KIRBY_EMAIL_PASSWORD' => ['mail-password-not-on-the-page', 'mail-password-not-on-the-page'],
		'KIRBY_CONTENT_SALT' => ['salt-not-on-the-page', 'salt-not-on-the-page'],
		'KIRBY_COOKIE_KEY' => ['cookie-key-not-on-the-page', 'cookie-key-not-on-the-page'],
		'KIRBY_OPTIONS_JSON' => ['{"smoke":"options-not-on-the-page"}', 'options-not-on-the-page'],
	];

	private const string VISIBLE = 'variable-on-the-page';

	/** A variable the site masks itself, the way the README shows. */
	private const string PLUGIN_KEY = 'plugin-key-not-on-the-page';

	/** The README's form, keyed by name, and a plain list, which array_replace_recursive() merges by index. */
	private const array ADDS_A_VARIABLE = [
		'keyed' => "['SMOKE_PLUGIN_KEY' => 'SMOKE_PLUGIN_KEY']",
		'list' => "['SMOKE_PLUGIN_KEY']",
	];

	private const string WHOOPS_TRUE = <<<'PHP'
		<?php
		return array_replace_recursive(
			require '/usr/local/share/kirby/env-options.php',
			['whoops' => true]
		);
		PHP;

	private static Container $kirby;

	private static Response $page;

	public static function setUpBeforeClass(): void
	{
		self::$kirby = self::startHealthy('debug', self::environment());
		self::$page = self::triggerError(self::$kirby);
	}

	/** @return list<string> docker run options */
	private static function environment(): array
	{
		$env = [
			'-e', 'KIRBY_DEBUG=true',
			'-e', 'KIRBY_ADMIN_EMAIL=admin@example.com',
			'-e', 'SMOKE_VISIBLE=' . self::VISIBLE,
			'-e', 'SMOKE_PLUGIN_KEY=' . self::PLUGIN_KEY,
		];
		foreach (self::SECRETS as $variable => [$value]) {
			array_push($env, '-e', "{$variable}={$value}");
		}

		return $env;
	}

	private static function triggerError(Container $kirby): Response
	{
		// A preview token that is an array instead of a string throws a
		// TypeError in Kirby 4 (`token`) and 5 (`_token`) alike.
		$kirby->exec('sh', '-c', 'mkdir -p /app/content/_drafts/smoke && printf "Title: Smoke\n" > /app/content/_drafts/smoke/default.txt');

		return $kirby->get('/smoke?token%5B%5D=1&_token%5B%5D=1', headers: ['Accept' => 'text/html']);
	}

	/**
	 * A container whose site config is replaced before PHP serves anything:
	 * the image's opcache does not revalidate scripts, so a config written
	 * after the first request would not be seen.
	 *
	 * @return array{Container, Response}
	 */
	private static function withConfig(string $name, string $config): array
	{
		$kirby = self::startHealthy($name, self::environment());
		$kirby->write('/app/site/config/config.php', $config);

		return [$kirby, self::triggerError($kirby)];
	}

	private static function assertNoSecretOn(Response $page): void
	{
		self::assertSame(500, $page->status);
		self::assertStringContainsString('Kirby CMS Debugger', $page->body);
		self::assertStringContainsString(self::VISIBLE, $page->body);

		foreach (self::SECRETS as [, $marker]) {
			self::assertStringNotContainsString($marker, $page->body);
		}
	}

	/** @param array<string, mixed> $whoops */
	private static function assertBlocklistHas(array $whoops, string ...$variables): void
	{
		foreach (['_SERVER', '_ENV'] as $superglobal) {
			$listed = array_values($whoops['blocklist'][$superglobal] ?? []);

			foreach ([...array_keys(self::SECRETS), ...$variables] as $variable) {
				self::assertContains($variable, $listed, "{$variable} is not on the {$superglobal} blocklist");
			}
		}
	}

	/** Shows that the page does list the environment the secrets are hidden from. */
	public function testAnErrorShowsTheDebugPageWithTheEnvironment(): void
	{
		self::assertSame(500, self::$page->status);
		self::assertStringContainsString('Kirby CMS Debugger', self::$page->body);
		self::assertStringContainsString(self::VISIBLE, self::$page->body);
	}

	#[DataProvider('secrets')]
	public function testTheDebugPageDoesNotShow(string $variable): void
	{
		self::assertStringNotContainsString(self::SECRETS[$variable][1], self::$page->body);
	}

	public static function secrets(): array
	{
		return self::cases(...array_keys(self::SECRETS));
	}

	#[DataProvider('startOnlySecrets')]
	public function testTheServerDoesNotCarry(string $variable): void
	{
		self::assertSame(
			['getenv' => false, 'server' => false, 'env' => false],
			self::$kirby->webProbe('environment.php')[$variable],
		);
	}

	public static function startOnlySecrets(): array
	{
		return self::cases('KIRBY_ADMIN_PASSWORD', 'KIRBY_LICENSE');
	}

	/** Shows that the probe sees what is there, and that the mail password still reaches Kirby. */
	public function testTheServerStillCarriesWhatKirbyReadsOnEveryRequest(): void
	{
		$environment = self::$kirby->webProbe('environment.php');

		self::assertTrue($environment['SMOKE_VISIBLE']['getenv']);
		self::assertTrue($environment['KIRBY_EMAIL_PASSWORD']['getenv']);
	}

	public function testTheAdminWasCreatedBeforeThePasswordWasDropped(): void
	{
		self::assertSame(1, self::$kirby->probe('accounts.php')['count']);
	}

	/** Without a config of its own, a site's other variables are listed. */
	public function testAVariableTheSiteDoesNotMaskIsListed(): void
	{
		self::assertStringContainsString(self::PLUGIN_KEY, self::$page->body);
	}

	#[DataProvider('additions')]
	public function testAConfigThatAddsAVariableMasksItWithoutUnmaskingTheImages(string $form): void
	{
		$entries = self::ADDS_A_VARIABLE[$form];
		[$kirby, $page] = self::withConfig("debug-adds-{$form}", <<<PHP
			<?php
			return array_replace_recursive(
				require '/usr/local/share/kirby/env-options.php',
				['whoops' => ['blocklist' => ['_SERVER' => {$entries}, '_ENV' => {$entries}]]]
			);
			PHP);

		self::assertNoSecretOn($page);
		self::assertStringNotContainsString(self::PLUGIN_KEY, $page->body);
		self::assertBlocklistHas($kirby->probe('whoops.php')['whoops'], 'SMOKE_PLUGIN_KEY');
	}

	public static function additions(): array
	{
		return self::cases(...array_keys(self::ADDS_A_VARIABLE));
	}

	/** `whoops: true` drops the blocklist; the front controller still keeps the secrets off the page. */
	public function testAConfigThatSetsWhoopsToTrueShowsNoSecret(): void
	{
		[, $page] = self::withConfig('debug-true', self::WHOOPS_TRUE);

		self::assertNoSecretOn($page);
	}

	/** A list in KIRBY_OPTIONS_JSON replaces lists as a whole; the image's entries are added back. */
	public function testAListInKirbyOptionsJsonKeepsTheImagesEntries(): void
	{
		$whoops = self::$kirby->probe('whoops.php', [
			'KIRBY_OPTIONS_JSON' => '{"whoops":{"blocklist":{"_SERVER":["SMOKE_JSON_KEY"],"_ENV":["SMOKE_JSON_KEY"]}}}',
		])['whoops'];

		self::assertBlocklistHas($whoops, 'SMOKE_JSON_KEY');
	}
}
