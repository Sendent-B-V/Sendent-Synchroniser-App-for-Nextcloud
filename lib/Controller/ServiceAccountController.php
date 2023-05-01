<?php

 namespace OCA\SendentSynchroniser\Controller;

 use OCP\IRequest;
 use OCP\AppFramework\Http\DataResponse;
 use OCP\AppFramework\ApiController;

 use OCA\SendentSynchroniser\Service\ServiceAccountService;

 class ServiceAccountApiController extends ApiController {
 	private $service;

 	public function __construct($appName,
	 IRequest $request,
	 ServiceAccountService $service) {
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
 		return $this->service->findByUsername($username);
 	}

 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @param string $username
 	 */
 	public function create(string $username) {
 		return $this->service->create($username);
 	}

 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @param int $id
 	 * @param string $username
 	 */
 	public function update(int $id, string $username) {
 		return $this->service->update($id, $username);
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
