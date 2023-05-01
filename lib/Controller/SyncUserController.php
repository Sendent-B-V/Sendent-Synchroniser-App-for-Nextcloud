<?php

namespace OCA\SendentSynchroniser\Controller;

 use OCP\IRequest;
 use OCP\AppFramework\Http\DataResponse;
 use OCP\AppFramework\ApiController;

 use OCA\SendentSynchroniser\Service\SyncUserService;

 class SyncUserApiController extends ApiController {
 	private $service;

 	public function __construct($appName,
	 IRequest $request,
	 SyncUserService $service) {
 		parent::__construct($appName, $request);
 		$this->service = $service;
 	}

 	/**
 	 * @NoAdminRequired
 	 *
 	 * @NoCSRFRequired
 	 *
 	 * @return DataResponse
 	 */
 	public function index(): DataResponse {
 		return new DataResponse($this->service->findAll());
 	}

 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @param int $id
 	 */
 	public function show(int $id) {
 		return $this->service->find($id);
 	}

 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @param string $username
 	 */
 	public function showByUsername(string $username) {
 		return $this->service->findByusername($username);
 	}
 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @param string $groupId
 	 */
	  public function showByGroupId(string $groupId) {
		return $this->service->findByGroupId($groupId);
	}
 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @param string $username
 	 * @param string $groupId
 	 */
 	public function create(string $username, string $groupId) {
 		return $this->service->create($username, $groupId);
 	}

 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @param int $id
 	 * @param string $username
 	 * @param string $groupId
 	 */
 	public function update(int $id, string $username, string $groupId) {
 		return $this->service->update($id, $username, $groupId);
 	}

 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @param int $id
 	 */
 	public function destroy(int $id) {
 		return $this->service->destroy($id);
 	}
 }
