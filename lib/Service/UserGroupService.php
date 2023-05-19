<?php

namespace OCA\SendentSynchroniser\Service;

use Exception;
use OCA\SendentSynchroniser\Service\Entities\GroupItem;
use OCA\SendentSynchroniser\Service\Entities\UserItem;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IGroup;
use OCP\IGroupManager;
use \OCP\ILogger;

class UserGroupService {
	private $groupManager;
	private $logger;

	public function __construct(ILogger $logger, IGroupManager $groupManager) {
		$this->groupManager = $groupManager;     
		$this->logger = $logger;
	}

	public function GetGroups() {
		$groups = $this->groupManager->search('');
		
		$newGroups = \array_map(function ($group) {
			/** @var IGroup $group */
			$groupitem = new GroupItem();
			$groupitem->id = $group->getGId();
			return $groupitem;
		}, $groups);
		return $newGroups;
	}
	public function GetGroupUsers(string $groupid) {
		$groups = $this->groupManager->search($groupid);
		
		$newGroups = \array_map(function ($group) {
			/** @var IGroup $group */
			$groupitem = new GroupItem();
			$groupitem->id = $group->getGId();
			
			$users = $group->getUsers();

			$newUsers = array();

			foreach($users as $user) {
				$this->logger->error('found user: ' . $user->GetuId() . 'in group: '. $group->getGid() );

				/** @var IUser $user */
				$userItem = new UserItem();
				$userItem->name = $user->getDisplayName();
				$userItem->email = $user->getEMailAddress();
				$userItem->id = $user->GetuId();
				array_push($newUsers, $userItem);
			}
			
			$groupitem->users = $newUsers;

			return $groupitem;
		}, $groups);
		return $newGroups;
	}
	/**
	 * @return never
	 */
	private function handleException(Exception $e) {
		if ($e instanceof DoesNotExistException ||
			$e instanceof MultipleObjectsReturnedException) {
			throw new NotFoundException($e->getMessage());
		} else {
			throw $e;
		}
	}
	
}
