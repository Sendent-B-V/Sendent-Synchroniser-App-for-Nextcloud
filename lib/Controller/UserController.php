<?php

namespace OCA\SendentSynchroniser\Controller;

use OCP\IRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\ISession;
use \OCP\ILogger;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;

class UserController extends Controller {

	/** @var IAppConfig */
	private $appConfig;

	/** @var IGroupManager */
	private $groupManager;

	/** @var ILogger */
	private $logger;

	/** @var SyncUserMapper */
	private $syncUserMapper;

	/** @var string */
	private $userId;
	
	public function __construct(ILogger $logger, $AppName, IRequest $request,
		string $userId,
		IAppConfig $appConfig,
		IGroupManager $groupManager,
		SyncUserMapper $syncUserMapper) {

		parent::__construct($AppName, $request);
		
		$this->appConfig = $appConfig;
		$this->groupManager = $groupManager;
		$this->logger = $logger;
		$this->syncUserMapper = $syncUserMapper;
		$this->userId = $userId;

	}
	
	/**
	 *
	 * This method tells if the current user is a valid Sendent synchrninser user. 
	 * 
	 * @NoAdminRequired
	 *
 	 * @return DataResponse
	 *
	 */
	public function isValid() {

		$this->logger->info('Checking validity of user ' . $this->userId);

		// Checks if user is member of an active group
		$activeGroups = $this->appConfig->getAppValue('activeGroups', '');
		$activeGroups = ($activeGroups !== '' && $activeGroups !== 'null') ? json_decode($activeGroups) : [];
		foreach ($activeGroups  as $gid) {
			if ($this->groupManager->isInGroup($this->userId, $gid)) {
				// User is member of an active group, let's find if he's valid
				$syncUsers = $this->syncUserMapper->findByUid($this->userId);
				if (!empty($syncUsers)) {
					if ($syncUsers[0]->getActive()) {
						$this->logger->info('User ' . $this->userId . ' is valid');
						return new DataResponse(TRUE);
					} else {
						$this->logger->info('User ' . $this->userId . ' is not valid');
						return new DataResponse(FALSE);
					}
				} else {
					// User has never setup sync
					$this->logger->info('User ' . $this->userId . ' has not setup sync yet');
					return new DataResponse(FALSE);
				}		
			}
		};

		// User is not member of an active group, let's pretend it is valid so the Sendent synchroniser modal is not shown
		return new DataResponse(TRUE);

	}

	/**
	 * 
	 * This method invalidates a user. It shall be called by the Sendent synchroniser
	 * (external) service to trigger the display of the "synchronisation problem" warning
	 * dialog to the user.
	 * 
	 * @NoCSRFRequired
	 * 
	 */
	public function invalidate(string $userId) {

		$this->logger->info('Invalidating user ' . $userId);

		$syncUsers = $this->syncUserMapper->findByUid($userId);

		$response = [];

		if (empty($syncUsers)) {
			$this->logger->warning('User ' . $userId . ' does not exxist');
			$response = [
				'status' => 'error',
				'message' => 'user does not exist'
			];
		} else {
			$syncUsers[0]->setActive(0);	
			$this->syncUserMapper->update($syncUsers[0]);
			$response = [
				'status' => 'success',
				'message' => 'user ' . $userId . ' invalidated'
			];
		}

		return new JSONResponse($response);

	}
}
