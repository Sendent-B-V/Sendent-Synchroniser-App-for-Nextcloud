<?php

 namespace OCA\SendentSynchroniser\Controller;

 use OCP\IRequest;
 use OCP\AppFramework\Http\DataResponse;
 use OCP\AppFramework\ApiController;

 use OCA\SendentSynchroniser\Service\CalDavService;

 class CalDavController extends ApiController {
 	private $service;

 	public function __construct($appName,
	 IRequest $request,
	 CalDavService $service) {
 		parent::__construct($appName, $request);
 		$this->service = $service;
 	}

 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @param string $username
 	 * @return array
 	 */
 	public function getGroupMemberSet(string $username): array {
 		return $this->service->GetGroupMemberSet($username);
 	}
 	
 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @param string $serviceaccount
	 * @param string $username
	 * @return array
 	 */
	  public function setGroupMemberSet(string $serviceaccount, string $username): array {
		return $this->service->SetGroupMemberSet($serviceaccount, $username);
	}
 	
}
