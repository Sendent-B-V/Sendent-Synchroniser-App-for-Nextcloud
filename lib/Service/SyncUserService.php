<?php

namespace OCA\SendentSynchroniser\Service;

use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Services\IAppConfig;
use OC\Authentication\Token\IProvider;
use OCP\IGroupManager;
use OCP\IUserManager;
use \Psr\Log\LoggerInterface;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;

class SyncUserService {

	/** @var IAccountManager */
	private $accountManager;

	/** @var string */
	private $appName;

	/** @var AppConfig */
	private $appConfig;

	/** @var IGroupManager */
	private $groupManager;

	/** @var LoggerInterface */
	private $logger;

	/** @var IProvider */
	private $tokenProvider;

	/** @var SyncUserMapper */
	private $syncUserMapper;

	/** @var IUserManager */
	private $userManager;

	/** @var SchedulingSuppressionService */
	private $schedulingSuppression;

    public function __construct(IAccountManager $accountManager, $AppName, IAppConfig $appConfig, IGroupManager $groupManager, LoggerInterface $logger,
		Iprovider $tokenProvider, IUserManager $userManager, SyncUserMapper $syncUserMapper,
		SchedulingSuppressionService $schedulingSuppression) {

		$this->accountManager = $accountManager;
		$this->appName = $AppName;
		$this->appConfig = $appConfig;
		$this->groupManager = $groupManager;
        $this->logger = $logger;
        $this->tokenProvider = $tokenProvider;
        $this->syncUserMapper = $syncUserMapper;
		$this->userManager = $userManager;
		$this->schedulingSuppression = $schedulingSuppression;

	}

	/*
	 *
	 * This method invalidate a user
	 *
	*/
    public function invalidateUser(string $userId, int $retractConsent = Constants::USER_STATUS_INACTIVE) {

        $this->logger->info('Invalidating user ' . $userId);
        $response = [];

        $syncUsers = $this->syncUserMapper->findByUid($userId);

        if (empty($syncUsers)) {
			$this->logger->warning('User ' . $userId . ' does not exist');
			$response = [
				'status' => 'error',
				'message' => 'user does not exist'
			];
		} else {
		    // Invalidates existing app tokens — both generations: tokens minted
		    // before the rework are named TOKEN_NAME_LEGACY, newer ones TOKEN_NAME.
		    // Matching both is security-critical: retracting consent must always
		    // revoke legacy tokens too.
		    $existingTokens = $this->tokenProvider->getTokenByUser($userId);
			foreach($existingTokens as $token) {
				if (in_array($token->getName(), [Constants::TOKEN_NAME, Constants::TOKEN_NAME_LEGACY], true)) {
					$this->tokenProvider->invalidateTokenById($token->getUid(), $token->getId());
				}
			}
            // Set user status to invalid
            $syncUsers[0]->setActive($retractConsent);
            $this->syncUserMapper->update($syncUsers[0]);

			$response = [
				'status' => 'success',
				'message' => 'user ' . $userId . ' invalidated'
			];
		}

		return $response;

	}

	public function hasAnyAppToken(string $userId): bool {
		foreach ($this->tokenProvider->getTokenByUser($userId) as $token) {
			if (in_array($token->getName(), [Constants::TOKEN_NAME, Constants::TOKEN_NAME_LEGACY], true)) {
				return true;
			}
		}
		return false;
	}

	/** The meeting-invitations setting is the master switch; while off, the flag is left in place. */
	public function hasPendingResetOffer(string $userId): bool {
		if (!$this->schedulingSuppression->isSuppressionEnabled()) {
			return false;
		}
		$syncUsers = $this->syncUserMapper->findByUid($userId);
		if (empty($syncUsers)) {
			return false;
		}
		return (int)$syncUsers[0]->getResetoffer() === 1;
	}

	/** For ACTIVE users; does not check the status itself. */
	public function needsReconsent(string $userId): bool {
		return !$this->hasAnyAppToken($userId) || $this->hasPendingResetOffer($userId);
	}

	/** Called by activate(); no-op while gated off so the flag survives. */
	public function clearResetOffer(string $userId): void {
		if (!$this->schedulingSuppression->isSuppressionEnabled()) {
			return;
		}
		$syncUsers = $this->syncUserMapper->findByUid($userId);
		if (empty($syncUsers) || (int)$syncUsers[0]->getResetoffer() === 0) {
			return;
		}
		$syncUsers[0]->setResetoffer(0);
		$this->syncUserMapper->update($syncUsers[0]);
	}

	/**
	 * 
	 * This function returns all the users that may use the app
	 * 
	*/
	public function getAllUsers() {
		// Gets all users in active groups
		$users = [];
		foreach ($this->activeGroups() as $gid) {
			$group = $this->groupManager->get($gid);
			if ($group === null) {
				// In this case it's likely the group was removed
				$this->logger->warning('Group ' . $gid . ' does not exist (anymore)');
				continue;
			}
			$users = array_merge($users, $group->getUsers());
		}

		return $users;
	}

	/**
	 * The rule behind getValidUsers() for a single user: an activated sync
	 * user in one of the active groups.
	 */
	public function isValidUser(string $userId): bool {
		return $this->syncedCalendarsOfValidUser($userId) !== null;
	}

	/**
	 * The one rule for which calendars of a user are synced. It exists so the
	 * calendar the Connector is told about (getValidUsers()) and the calendars
	 * the change feed writes per-event rows for (SyncScope) can never differ:
	 * both must get the URIs from here and nowhere else.
	 *
	 * A user may have more than one synced calendar, so the answer is a set.
	 * Today the set always holds exactly one URI.
	 *
	 * Guarantees:
	 * - Today's one URI comes from this order: the calendar stored on the
	 *   user's row, then the app setting 'defaultCalendar', then 'personal'.
	 * - A step that holds null or an empty string counts as absent and falls
	 *   through to the next one.
	 * - The set is never empty, holds no empty string and no duplicates.
	 * - The first entry is the one the valid-users list reports as 'calendar'.
	 * - URIs are returned as stored. Whether a calendar with that URI exists
	 *   is not checked; the setting is free text and may name none.
	 * - Reads only; never writes the row or the setting.
	 *
	 * @param SyncUser $syncUser the user's row, already loaded by the caller
	 * @return list<string> calendar URIs, for example ['personal']
	 */
	public function resolveSyncedCalendars(SyncUser $syncUser): array {
		$calendar = $syncUser->getCalendar();

		if ($calendar !== null && $calendar !== ''){
			return [$calendar];
		}

		$calendar = $this->appConfig->getAppValue('defaultCalendar', '');

		if ($calendar !== ''){
			return [$calendar];
		}

		return ['personal'];
	}

	/**
	 * Answers in one call both things the change feed asks about the owner of
	 * a changed collection: is this a valid sync user, and if so, which
	 * calendars are synced. One call so that one user costs one row load.
	 *
	 * Guarantees:
	 * - Returns a set exactly when isValidUser() returns true for the same
	 *   user, and null exactly when it returns false. The two must not drift.
	 * - The set is the one resolveSyncedCalendars() gives for the user's row.
	 * - Null means "not a valid sync user". It is never an empty list.
	 * - Loads the user's row once.
	 * - Never throws for an unknown user.
	 *
	 * @return list<string>|null the synced calendar URIs, or null when the
	 *                           user is not a valid sync user
	 */
	public function syncedCalendarsOfValidUser(string $userId): ?array {
		$syncUsers = $this->syncUserMapper->findByUid($userId);
		if (empty($syncUsers) || (int)$syncUsers[0]->getActive() !== Constants::USER_STATUS_ACTIVE) {
			return null;
		}

		foreach ($this->activeGroups() as $gid) {
			if ($this->groupManager->isInGroup($userId, $gid)) {
				return $this->resolveSyncedCalendars($syncUsers[0]);
			}
		}

		return null;
	}

	/**
	 * Defensive parse of the activeGroups app config: missing, 'null' or
	 * malformed JSON all mean no active groups.
	 *
	 * @return string[]
	 */
	private function activeGroups(): array {
		$decoded = json_decode($this->appConfig->getAppValue('activeGroups', ''), true);

		return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
	}

	/**
	 *
	 * This function returns all inactive users that may use the app
	 * 
	*/
	public function getInvalidUsers() {

		$users = $this->getAllUsers();

		// Gets only inactive sendent sync users
		$inactiveUsers = [];
		foreach ($users as $user) {
			$syncUsers = $this->syncUserMapper->findByUid($user->getUid());
			if (!empty($syncUsers)) {
				if ($syncUsers[0]->getActive() === Constants::USER_STATUS_INACTIVE) {
					// Makes sure we don't create duplicates
					if(!array_key_exists($syncUsers[0]->getUid(), $inactiveUsers)) {
						$inactiveUsers[$syncUsers[0]->getUid()] = $syncUsers[0];
					}
				}
			}
		}

		return $inactiveUsers;

	}

	public function getValidUserCount() {

		$users = $this->getAllUsers();

		// Gets only active sendent sync users
		$index = 0;
		$activeUsers = [];
		foreach ($users as $user) {
			$syncUsers = $this->syncUserMapper->findByUid($user->getUid());
			if (!empty($syncUsers)) {
				$syncUser = $syncUsers[0];
				if ($syncUser->getActive() === Constants::USER_STATUS_ACTIVE) {
					// Makes sure we don't create duplicates
					if(!array_key_exists($syncUser->getUid(), $activeUsers)) {
							$activeUsers[$index] = $user;
							$index++;
					}
				}
			}
		}

		return $index;

	}

	/**
	 * 
	 * This function returns all active users that may use the app
	 * 
	*/
	/**
	 *
	 * This function returns all active users that may use the app
	 *
	*/
	public function getValidUsers() {

		$users = $this->getAllUsers();

		// Load global default collection settings
		$defaultAddressbook = $this->appConfig->getAppValue('defaultAddressbook', '') ?: 'contacts';

		// Gets all active sendent sync users
		$index = 0;
		$activeUsers = [];
		foreach ($users as $user) {
			$syncUsers = $this->syncUserMapper->findByUid($user->getUid());
			if (!empty($syncUsers)) {
				$syncUser = $syncUsers[0];
				if ($syncUser->getActive() === Constants::USER_STATUS_ACTIVE) {
					if(!array_key_exists($syncUser->getUid(), $activeUsers)) {
						$NCUser = $this->userManager->get($syncUser->getUid());
						$user = $syncUser->jsonSerialize();
						$username = $syncUser->getUsername();
						if($username === null || $username === ''){
							$user['username'] = $user['uid'];
						} else {
							$user['username'] = $username;
						}
						$user['uid'] = $NCUser->getUID();
						// When an email template is configured (occ sendentsynchroniser:email-template),
						// the email address is built from it and the account email addresses are ignored
						$emailTemplate = $this->appConfig->getAppValue('emailTemplate', '');
						if ($emailTemplate !== '') {
							$user['email'] = str_replace(['{userId}', '{username}'], [$NCUser->getUID(), $user['username']], $emailTemplate);
						} else {
							$user['email'] = $NCUser->getEmailAddress();
							// Replaces email address by one of the user email addresses that matches the sync domain (if any)
							$emailDomain = $this->appConfig->getAppValue('emailDomain', '');
							if ($emailDomain !== '') {
								$account = $this->accountManager->getAccount($NCUser);
								$email = $account->getProperty(IAccountManager::PROPERTY_EMAIL);
								$emailAddress = $email->getValue();
								if (substr($emailAddress, -strlen($emailDomain)) === $emailDomain) {
									$user['email'] = $email->getValue();
								} else {
									$emailsCollection = $account->getPropertyCollection(IAccountManager::COLLECTION_EMAIL);
									foreach($emailsCollection->getProperties() as $email)	{
										$emailAddress = $email->getValue();
										if (substr($emailAddress, -strlen($emailDomain)) === $emailDomain) {
											$user['email'] = $email->getValue();
										}
									}
								}
							}
						}
						$user['displayName'] = $NCUser->getDisplayName();

						// Collection targets: stored value → admin default → NC default
						// The Connector is told one calendar: the first of the synced set.
						$userCalendar = $this->resolveSyncedCalendars($syncUser)[0];
						$userAddressbook = $syncUser->getAddressbook();
						if ($userAddressbook === null || $userAddressbook === '') {
							$userAddressbook = $defaultAddressbook;
						}
						$user['calendar'] = $userCalendar;
						$user['addressbook'] = $userAddressbook;
						//this method replaces the mechanism with named array indexes because C# cannot deal with that.
						if(!$this->checkIfUserInArray($activeUsers, $syncUser->getUid()))
						{
							$activeUsers[$index] = $user;
							$index++;
						}
					}
				}
			}
		}

		return $activeUsers;

	}

	//this method replaces the mechanism with named array indexes because C# cannot deal with that.
	private function checkIfUserInArray($array, $id) : bool
	{
		$found = false;
		foreach($array as $user)
		{
			if($user['uid'] == $id)
			{
				$this->logger->info('Duplicate found for user: ' . $user['displayName']);
				$found = true;
				break;
			}
		}
		return $found;
	}

}