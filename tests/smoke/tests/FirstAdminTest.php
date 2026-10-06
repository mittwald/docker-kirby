<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\SmokeTestCase;

/**
 * The first panel account from KIRBY_ADMIN_*. It is created once on an empty
 * accounts root, owned by the kirby user even on a root start, and a later
 * start with different values leaves it alone. A configuration that cannot
 * produce an admin stops the container instead of starting without one.
 */
final class FirstAdminTest extends SmokeTestCase
{
	private const string PASSWORD = 'smoke-test-password';

	/** @var array{logs: string, accounts: array<string, mixed>} */
	private static array $first;

	/** @var array{logs: string, accounts: array<string, mixed>} */
	private static array $second;

	public static function setUpBeforeClass(): void
	{
		$secrets = self::directory();
		chmod($secrets, 0755);
		file_put_contents("{$secrets}/admin-password", self::PASSWORD . "\n");
		chmod("{$secrets}/admin-password", 0644);

		$accounts = self::volume('accounts') . ':/app/storage/accounts';

		$first = self::startHealthy('admin1', [
			'--user', '0:0',
			'-v', $accounts,
			'-v', "{$secrets}:/run/secrets:ro",
			'-e', 'KIRBY_ADMIN_EMAIL=admin@example.com',
			'-e', 'KIRBY_ADMIN_PASSWORD_FILE=/run/secrets/admin-password',
		]);
		self::$first = [
			'logs' => $first->logs(),
			'accounts' => $first->probe('accounts.php', ['ADMIN_PASSWORD' => self::PASSWORD]),
		];
		self::stop($first);

		$second = self::startHealthy('admin2', [
			'-v', $accounts,
			'-e', 'KIRBY_ADMIN_EMAIL=other@example.com',
			'-e', 'KIRBY_ADMIN_PASSWORD=a-different-password',
		]);
		self::$second = [
			'logs' => $second->logs(),
			'accounts' => $second->probe('accounts.php', ['ADMIN_PASSWORD' => self::PASSWORD]),
		];
		self::stop($second);
	}

	// --- first start ----------------------------------------------------------

	public function testTheEntrypointReportsTheNewAccount(): void
	{
		self::assertStringContainsString('created panel admin admin@example.com', self::$first['logs']);
	}

	public function testExactlyOneAccountExists(): void
	{
		self::assertSame(1, self::$first['accounts']['count']);
	}

	public function testTheAccountHasTheConfiguredEmailAndTheAdminRole(): void
	{
		self::assertSame('admin@example.com', self::$first['accounts']['email']);
		self::assertSame('admin', self::$first['accounts']['role']);
	}

	public function testThePasswordFromThePasswordFileWorksWithoutItsLineBreak(): void
	{
		self::assertSame('valid', self::$first['accounts']['password']);
	}

	public function testTheAccountFilesBelongToTheKirbyUserAfterARootStart(): void
	{
		self::assertSame(0, self::$first['accounts']['foreign']);
	}

	// --- second start with different values -----------------------------------

	public function testTheEntrypointReportsThatTheVariablesWereIgnored(): void
	{
		self::assertStringContainsString('panel accounts exist, KIRBY_ADMIN_* is ignored', self::$second['logs']);
	}

	public function testNoSecondAccountIsCreated(): void
	{
		self::assertSame(1, self::$second['accounts']['count']);
	}

	public function testTheOriginalPasswordStillWorks(): void
	{
		self::assertSame('valid', self::$second['accounts']['password']);
	}

	public function testTheAccountFilesAreUnchanged(): void
	{
		self::assertNotNull(self::$first['accounts']['fingerprint'], 'the account files could not be fingerprinted after the first start');
		self::assertSame(self::$first['accounts']['fingerprint'], self::$second['accounts']['fingerprint']);
	}

	// --- configurations that must not start ---------------------------------

	public function testAnEmailWithoutAPasswordStopsTheContainerAndSaysWhy(): void
	{
		$kirby = self::start('admin3', ['-e', 'KIRBY_ADMIN_EMAIL=admin@example.com']);
		self::assertSame(1, $kirby->waitExited());
		self::assertStringContainsString('neither KIRBY_ADMIN_PASSWORD nor KIRBY_ADMIN_PASSWORD_FILE', $kirby->logs());
	}

	public function testAPasswordKirbyRejectsStopsTheContainerAndSaysWhy(): void
	{
		$kirby = self::start('admin4', ['-e', 'KIRBY_ADMIN_EMAIL=admin@example.com', '-e', 'KIRBY_ADMIN_PASSWORD=short']);
		self::assertSame(1, $kirby->waitExited());
		self::assertStringContainsString('cannot create the panel admin admin@example.com', $kirby->logs());
	}
}
