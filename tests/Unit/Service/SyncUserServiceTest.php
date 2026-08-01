<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service;

use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SyncUserServiceTest extends TestCase {

	private IProvider&MockObject $tokenProvider;
	private SyncUserMapper&MockObject $mapper;
	private SyncUserService $svc;

	protected function setUp(): void {
		$this->tokenProvider = $this->createMock(IProvider::class);
		$this->mapper = $this->createMock(SyncUserMapper::class);
		$this->svc = new SyncUserService(
			$this->createMock(IAccountManager::class),
			'sendentsynchroniser',
			$this->createMock(IAppConfig::class),
			$this->createMock(IGroupManager::class),
			new NullLogger(),
			$this->tokenProvider,
			$this->createMock(IUserManager::class),
			$this->mapper
		);
	}

	private function token(int $id, string $name): IToken&MockObject {
		$token = $this->createMock(IToken::class);
		$token->method('getId')->willReturn($id);
		$token->method('getName')->willReturn($name);
		$token->method('getUid')->willReturn('alice');
		return $token;
	}

	public function testInvalidateUserRevokesBothTokenGenerations(): void {
		$syncUser = new SyncUser();
		$syncUser->setUid('alice');
		$this->mapper->method('findByUid')->with('alice')->willReturn([$syncUser]);
		$this->tokenProvider->method('getTokenByUser')->with('alice')->willReturn([
			$this->token(1, Constants::TOKEN_NAME_LEGACY),
			$this->token(2, Constants::TOKEN_NAME),
			$this->token(3, 'Firefox on Windows'),
		]);

		$invalidated = [];
		$this->tokenProvider->method('invalidateTokenById')
			->willReturnCallback(function ($uid, $id) use (&$invalidated) {
				$invalidated[] = $id;
			});

		$this->svc->invalidateUser('alice');
		$this->assertSame([1, 2], $invalidated);
	}

	public function testHasLegacyTokenTrueForLegacyName(): void {
		$this->tokenProvider->method('getTokenByUser')->with('alice')->willReturn([
			$this->token(3, 'Firefox on Windows'),
			$this->token(1, Constants::TOKEN_NAME_LEGACY),
		]);
		$this->assertTrue($this->svc->hasLegacyToken('alice'));
	}

	public function testHasLegacyTokenFalseForNewNameOnly(): void {
		$this->tokenProvider->method('getTokenByUser')->with('alice')->willReturn([
			$this->token(2, Constants::TOKEN_NAME),
			$this->token(3, 'Firefox on Windows'),
		]);
		$this->assertFalse($this->svc->hasLegacyToken('alice'));
	}
}
