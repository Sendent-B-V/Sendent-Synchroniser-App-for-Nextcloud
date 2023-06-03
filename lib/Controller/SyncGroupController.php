<?php

 namespace OCA\SendentSynchroniser\Controller;

 use OCP\IRequest;
 use OCP\AppFramework\Http\DataResponse;
 use OCP\AppFramework\ApiController;

 use OCA\SendentSynchroniser\Service\SyncGroupService;

 class SyncgroupController extends ApiController {
 	private $service;

 	public function __construct($appName,
	 IRequest $request,
	 SyncGroupService $service) {
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
 	 * @param string $name
 	 */
 	public function showByName(string $name) {
 		return $this->service->findByName($name);
 	}

 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @param string $name
 	 */
 	public function create(string $name) {
 		return $this->service->create($name);
 	}
	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
	 * @param string $newSendentGroups
	 */
	  public function updateFromNewList($newSendentGroups) {
		return $this->service->updateSyncGroupList($newSendentGroups);
	}
 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @param int $id
 	 * @param string $name
 	 */
 	public function update(int $id, string $name) {
 		return $this->service->update($id, $name);
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
