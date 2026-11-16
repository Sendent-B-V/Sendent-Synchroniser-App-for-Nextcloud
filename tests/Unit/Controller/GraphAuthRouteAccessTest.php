<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\GraphAuthController;
use PHPUnit\Framework\TestCase;

/**
 * Who may call the Microsoft sign-in routes. The controller holds no
 * credential check of its own: Nextcloud authenticates the request and its
 * security middleware decides from the markers on each method, where "admin
 * only" is the absence of a marker. A marker added or dropped by accident
 * changes who gets in without any other test noticing, so the markers are
 * pinned here.
 *
 * What the sync service sees when its credentials are wrong or missing is
 * therefore Nextcloud's own answer, 401 with a JSON `message`, produced before
 * the controller runs; it cannot be asserted from a unit test.
 */
class GraphAuthRouteAccessTest extends TestCase {

	/**
	 * The access markers on a controller method, from both spellings Nextcloud
	 * accepts: docblock annotations and PHP attributes.
	 *
	 * @return string[] short names, e.g. ['NoCSRFRequired']
	 */
	private function accessMarkers(string $method): array {
		$reflection = new \ReflectionMethod(GraphAuthController::class, $method);

		preg_match_all('/^\s*\*\s*@([A-Za-z]+)/m', (string)$reflection->getDocComment(), $matches);
		$markers = $matches[1];

		foreach ($reflection->getAttributes() as $attribute) {
			$parts = explode('\\', $attribute->getName());
			$markers[] = end($parts);
		}

		return $markers;
	}

	public function testStatusCallbackIsAdminOnly(): void {
		$markers = $this->accessMarkers('status');

		$this->assertNotContains('NoAdminRequired', $markers, 'the callback is authorised by the admin requirement');
		$this->assertNotContains('PublicPage', $markers, 'the callback must never be reachable without credentials');
	}

	public function testStatusCallbackSkipsTheCsrfCheck(): void {
		// The service has no session and no request token; with the check on,
		// every callback would be refused.
		$this->assertContains('NoCSRFRequired', $this->accessMarkers('status'));
	}

	public function testStartIsForAnyLoggedInUserAndKeepsTheCsrfCheck(): void {
		$markers = $this->accessMarkers('start');

		$this->assertContains('NoAdminRequired', $markers);
		$this->assertNotContains('PublicPage', $markers);
		$this->assertNotContains('NoCSRFRequired', $markers, 'start hands out a signed link, a cross-site request must not get one');
	}

	public function testOwnStatusIsForAnyLoggedInUserAndKeepsTheCsrfCheck(): void {
		$markers = $this->accessMarkers('myStatus');

		$this->assertContains('NoAdminRequired', $markers);
		$this->assertNotContains('PublicPage', $markers);
		$this->assertNotContains('NoCSRFRequired', $markers);
	}

	public function testStatusCallbackRouteIsThePostTheServiceCalls(): void {
		$routes = (require __DIR__ . '/../../../appinfo/routes.php')['routes'];
		$byName = array_column($routes, null, 'name');

		$this->assertArrayHasKey('graph_auth#status', $byName);
		$this->assertSame('/api/1.0/user/authStatus', $byName['graph_auth#status']['url']);
		$this->assertSame('POST', $byName['graph_auth#status']['verb']);
	}
}
