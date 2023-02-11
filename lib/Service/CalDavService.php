<?php

namespace OCA\SendentSynchroniser\Service;

use Exception;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use \OCP\ILogger;
use OCA\DAV\Connector\Sabre\Principal;

class CalDavService {
	private $principal;
	private $logger;
	public function __construct(ILogger $logger, Principal $p) {
		$this->principal = $p;      
		$this->logger = $logger;
	}

	public function GetGroupMemberSet(String $username) {
		return $this->principal->getGroupMemberSet('principals/users/'. $username 
		.'/calendar-proxy-write');
	}
	public function SetGroupMemberSet(String $serviceaccount, String $username){

		$this->logger->error('serviceaccount: ' . $serviceaccount);
		$this->logger->error('username: ' . $username);

		$currentMembers = $this->GetGroupMemberSet($serviceaccount);
		foreach ($currentMembers as $var) {
			$this->logger->error('currentmember initially: ' . $var);
		}
		if (in_array('principals/users/'. $serviceaccount .'', $currentMembers)) {
			$this->logger->error("Serviceaccount: ". $serviceaccount ."already present!");
		}
		else{
			array_push($currentMembers, 'principals/users/'. $serviceaccount .'');
		}

		foreach ($currentMembers as $var) {
			$this->logger->error('currentmembers with added users: ' . $var);

		}

		
		foreach ($currentMembers as $member) {
			$this->principal->setGroupMemberSet($member .'/calendar-proxy-write', 
			$currentMembers);
			$this->logger->error('member tried to set group member set: ' . $member);
		}
		return $this->GetGroupMemberSet($username);
	}

	// $currentMembers = $this->GetGroupMemberSet($serviceaccount);

	// 	foreach ($currentMembers as $var) {
	// 		$this->logger->error('currentmember: ' . $var);
	// 	}
	// 	$this->logger->error('username: ' . $username);

	// 	array_push($currentMembers, $username);

	// 	foreach ($currentMembers as $member) {
	// 		$this->principal->setGroupMemberSet('principals/users/'. $member .'/calendar-proxy-write', 
	// 	['principals/users/'. $serviceaccount .'']);
	// 	$this->logger->error('member tried to set group member set: ' . $member);
	// 	}
        
	// 	return $this->GetGroupMemberSet($username);

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
