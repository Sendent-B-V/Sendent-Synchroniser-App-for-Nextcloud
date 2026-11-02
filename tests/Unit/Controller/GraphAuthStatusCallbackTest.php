<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Controller\GraphAuthController;
use OCA\SendentSynchroniser\Db\AuthStatus;
use OCA\SendentSynchroniser\Db\AuthStatusMapper;
use OCA\SendentSynchroniser\Service\StartLinkBuilder;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The sign-in status callback {@see GraphAuthController::status()}. The tests
 * call the method directly, so the dispatcher's own 400 on a missing
 * non-nullable argument never applies here; every 400 asserted below is the
 * controller's own.
 */
class GraphAuthStatusCallbackTest extends TestCase {

	/** 2026-10-19T09:30:00Z, the example body from the service's contract. */
	private const EXAMPLE_EPOCH = 1792402200;

	private AuthStatusMapper&MockObject $mapper;
	private SyncUserService&MockObject $syncUserService;
	private GraphAuthController $controller;

	// Stub state, read by callbacks so a test can change it after setUp.
	private bool $userExists = true;
	private string $signInMode = Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK;
	private ?AuthStatus $storedRow = null;
	private ?AuthStatus $inserted = null;

	protected function setUp(): void {
		$this->mapper = $this->createMock(AuthStatusMapper::class);
		$this->mapper->method('findByUidAndProvider')
			->willReturnCallback(fn (string $uid, string $provider) => $this->storedRow);
		$this->syncUserService = $this->createMock(SyncUserService::class);

		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('userExists')->willReturnCallback(fn () => $this->userExists);
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getAppValue')->willReturnCallback(fn () => $this->signInMode);

		$this->controller = new GraphAuthController(
			'sendentsynchroniser',
			$this->createStub(IRequest::class),
			$this->createStub(StartLinkBuilder::class),
			$this->createStub(IUserSession::class),
			new NullLogger(),
			$this->mapper,
			$userManager,
			$appConfig,
			$this->syncUserService,
		);
	}

	/** A well-formed body; override single fields per test, null removes one. */
	private function body(array $overrides = []): array {
		return $overrides + [
			'provider' => 'graph',
			'userId' => 'alice',
			'status' => 'needsReconnect',
			'reason' => 'refresh_token_revoked',
			'changedAt' => '2026-10-19T09:30:00Z',
		];
	}

	/** Puts a row for alice/graph in the "database" and returns it. */
	private function stored(string $status, int $changedAt, ?string $reason = null): AuthStatus {
		$row = new AuthStatus();
		$row->setId(7);
		$row->setUid('alice');
		$row->setProvider('graph');
		$row->setStatus($status);
		$row->setReason($reason);
		$row->setChangedAt($changedAt);
		$this->storedRow = $row;
		return $row;
	}

	/** Expects exactly one insert and captures the row in $this->inserted. */
	private function expectInsert(): void {
		$this->mapper->expects($this->once())->method('insert')
			->willReturnCallback(function (AuthStatus $row) {
				$this->inserted = $row;
				return $row;
			});
		$this->mapper->expects($this->never())->method('update');
	}

	private function expectUpdateOf(AuthStatus $row): void {
		$this->mapper->expects($this->once())->method('update')->with($row)->willReturnArgument(0);
		$this->mapper->expects($this->never())->method('insert');
	}

	private function expectNoWrite(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');
	}

	private function call(array $body): Response {
		return $this->controller->status(
			$body['provider'] ?? null,
			$body['userId'] ?? null,
			$body['status'] ?? null,
			$body['reason'] ?? null,
			$body['changedAt'] ?? null,
		);
	}

	private function assertEmpty200(Response $response): void {
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('', $response->render());
	}

	// --- storing -----------------------------------------------------------

	public function testStoresFirstStatusForUserAndProvider(): void {
		$this->expectInsert();

		$this->assertEmpty200($this->call($this->body()));

		$this->assertSame('alice', $this->inserted->getUid());
		$this->assertSame('graph', $this->inserted->getProvider());
		$this->assertSame('needsReconnect', $this->inserted->getStatus());
		$this->assertSame('refresh_token_revoked', $this->inserted->getReason());
		$this->assertSame(self::EXAMPLE_EPOCH, $this->inserted->getChangedAt());
	}

	public function testNewerStatusOverwritesStored(): void {
		$row = $this->stored('needsReconnect', self::EXAMPLE_EPOCH - 3600, 'refresh_token_expired');
		$this->expectUpdateOf($row);

		$this->assertEmpty200($this->call($this->body(['status' => 'active', 'reason' => null])));

		$this->assertSame('active', $row->getStatus());
		$this->assertSame(self::EXAMPLE_EPOCH, $row->getChangedAt());
	}

	public function testOlderChangedAtIsIgnoredWith200(): void {
		$row = $this->stored('active', self::EXAMPLE_EPOCH + 1);
		$this->expectNoWrite();

		$this->assertEmpty200($this->call($this->body()));

		$this->assertSame('active', $row->getStatus(), 'a late retry must not overwrite a newer status');
	}

	public function testEqualChangedAtIsStored(): void {
		// The same transition delivered twice: overwriting is harmless.
		$row = $this->stored('needsReconnect', self::EXAMPLE_EPOCH, 'refresh_token_revoked');
		$this->expectUpdateOf($row);

		$this->assertEmpty200($this->call($this->body()));
	}

	public function testActiveClearsReason(): void {
		$row = $this->stored('needsReconnect', self::EXAMPLE_EPOCH - 60, 'consent_revoked');
		$this->expectUpdateOf($row);

		$this->assertEmpty200($this->call($this->body(['status' => 'active', 'reason' => null])));

		$this->assertNull($row->getReason());
	}

	public function testReasonSentWithoutNeedsReconnectIsNotStored(): void {
		$this->expectInsert();

		$this->assertEmpty200($this->call($this->body(['status' => 'notConnected'])));

		$this->assertNull($this->inserted->getReason());
	}

	public function testFractionalSecondsAreAccepted(): void {
		// .NET serialises DateTime with up to seven fractional digits.
		$this->expectInsert();

		$this->assertEmpty200($this->call($this->body(['changedAt' => '2026-10-19T09:30:00.1234567Z'])));

		$this->assertSame(self::EXAMPLE_EPOCH, $this->inserted->getChangedAt());
	}

	public function testUnknownReasonIsAccepted(): void {
		$this->expectInsert();

		$this->assertEmpty200($this->call($this->body(['reason' => 'some_future_code'])));

		$this->assertSame('some_future_code', $this->inserted->getReason());
	}

	public function testRetractedUserIsNotA404(): void {
		// Consent is not existence: no sync user row is consulted, only the account.
		$this->expectInsert();

		$this->assertEmpty200($this->call($this->body()));
	}

	// --- rejecting ---------------------------------------------------------

	public function testUnknownStatusIs400(): void {
		$this->expectNoWrite();

		$response = $this->call($this->body(['status' => 'Active']));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUnknownProviderIs400(): void {
		$this->expectNoWrite();

		$response = $this->call($this->body(['provider' => 'imap']));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public static function requiredFields(): array {
		return [
			'provider' => ['provider'],
			'userId' => ['userId'],
			'status' => ['status'],
			'changedAt' => ['changedAt'],
		];
	}

	#[DataProvider('requiredFields')]
	public function testMissingFieldIs400(string $field): void {
		$this->expectNoWrite();

		$response = $this->call($this->body([$field => null]));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public static function malformedChangedAt(): array {
		return [
			'relative text' => ['tomorrow'],
			'no Z' => ['2026-10-19T09:30:00'],
			'offset instead of Z' => ['2026-10-19T09:30:00+02:00'],
			'date only' => ['2026-10-19'],
			'epoch number' => ['1792402200'],
			'eight fractional digits' => ['2026-10-19T09:30:00.12345678Z'],
		];
	}

	#[DataProvider('malformedChangedAt')]
	public function testMalformedChangedAtIs400(string $value): void {
		$this->expectNoWrite();

		$response = $this->call($this->body(['changedAt' => $value]));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUnknownUserIs404(): void {
		$this->userExists = false;
		$this->expectNoWrite();

		$response = $this->call($this->body());

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// --- invalidation under strict -----------------------------------------

	public function testNeedsReconnectUnderStrictInvalidatesUser(): void {
		$this->signInMode = Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT;
		$this->syncUserService->expects($this->once())->method('invalidateUser')->with('alice');

		$this->assertEmpty200($this->call($this->body()));
	}

	public function testNeedsReconnectUnderFallbackLeavesUserAlone(): void {
		$this->syncUserService->expects($this->never())->method('invalidateUser');

		$this->assertEmpty200($this->call($this->body()));
	}

	public function testNeedsReconnectUnderServiceAccountLeavesUserAlone(): void {
		$this->signInMode = Constants::GRAPH_AUTH_METHOD_SERVICE_ACCOUNT;
		$this->syncUserService->expects($this->never())->method('invalidateUser');

		$this->assertEmpty200($this->call($this->body()));
	}

	public function testActiveUnderStrictDoesNotInvalidate(): void {
		$this->signInMode = Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT;
		$this->syncUserService->expects($this->never())->method('invalidateUser');

		$this->assertEmpty200($this->call($this->body(['status' => 'active', 'reason' => null])));
	}

	public function testStaleNeedsReconnectUnderStrictDoesNotInvalidate(): void {
		// The user already reconnected; a late retry of the old needsReconnect
		// must neither overwrite nor knock them out.
		$this->signInMode = Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT;
		$this->stored('active', self::EXAMPLE_EPOCH + 1);
		$this->expectNoWrite();
		$this->syncUserService->expects($this->never())->method('invalidateUser');

		$this->assertEmpty200($this->call($this->body()));
	}
}
