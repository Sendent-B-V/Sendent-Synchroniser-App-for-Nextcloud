# Nextcloud 30–35 Support and Deprecation Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the app's supported range to Nextcloud 30 through 35, and clear every deprecated or dead server-API usage that the NC 30 floor allows.

**Architecture:** No functional change. The work is (1) manifest, CI and release plumbing for the new range, (2) small source fixes found by the 2026-09-14 NC 35 audit (private token shim, container `query()`, dead consent-flow route, wrong priority comment), (3) migrating deprecated public APIs to their typed successors that exist since NC 27–29 (controller attributes, `Util::addScript`, typed `IAppConfig` getters), and (4) verification on a real NC 35 container.

**Tech Stack:** PHP 8.1–8.5, Nextcloud app framework (OCP), PHPUnit 10+ run inside the `sendent-e2e-nc` Docker container, Vue 3 / webpack 5 frontend (unchanged), GitHub Actions.

**Out of scope (needs min-version ≥ 32, do NOT do now):** `OCP\IConfig::getUserValue/setUserValue` in `lib/Service/CalendarResetService.php:174,199` and `lib/Service/CollectionService.php:241,251`. Their replacement `OCP\Config\IUserConfig` only exists since NC 32. Leave them. Likewise keep the dual registration of `OCA\DAV\Events\CalendarObjectMovedToTrashEvent` and `OCP\Calendar\Events\CalendarObjectMovedToTrashEvent` in `lib/AppInfo/Application.php`: NC 30 and 31 only dispatch the OCA class.

**Commits:** Every task ends with a checkpoint step. The repo owner has asked that nothing is committed unless they explicitly request it in the session, so at each checkpoint: if the user asked for commits, run the shown `git` commands; otherwise leave the working tree uncommitted and continue.

---

## Environment facts the engineer needs

- **PHP is not on PATH on the Windows dev box.** All PHP runs happen inside Docker. `php -l` locally is not possible.
- **PHPUnit box:** container `sendent-e2e-nc` (Nextcloud 31, MariaDB). Start Docker Desktop first:
  `Start-Process 'C:\Program Files\Docker\Docker\Docker Desktop.exe'` (PowerShell), wait ~30 s, then `docker ps` must list `sendent-e2e-nc`. If it does not, run `docker compose up -d` in `C:\Users\L_u-c\AppData\Local\Temp\claude\c--Users-L-u-c-Sendent-Synchroniser-App-for-Nextcloud\78282e92-c9d1-4ec3-8a41-c726c5ef7c5e\scratchpad\e2e`.
- **Sync the working tree into the container** (Git Bash; `MSYS_NO_PATHCONV=1` is mandatory or the `/var/...` paths get rewritten to Windows paths and the copy silently no-ops):

```bash
cd "C:/Users/L_u-c/Sendent-Synchroniser-App-for-Nextcloud"
MSYS_NO_PATHCONV=1 docker exec sendent-e2e-nc rm -rf /var/www/html/custom_apps/sendentsynchroniser
tar cf - --exclude=node_modules --exclude=.git --exclude=vendor . | MSYS_NO_PATHCONV=1 docker exec -i sendent-e2e-nc sh -c 'mkdir -p /var/www/html/custom_apps/sendentsynchroniser && tar xf - -C /var/www/html/custom_apps/sendentsynchroniser'
MSYS_NO_PATHCONV=1 docker exec -u www-data -w /var/www/html/custom_apps/sendentsynchroniser sendent-e2e-nc composer install --no-interaction --quiet
MSYS_NO_PATHCONV=1 docker exec -u www-data sendent-e2e-nc chown -R www-data:www-data /var/www/html/custom_apps/sendentsynchroniser 2>/dev/null || true
```

- **Run the PHPUnit suite** (referred to below as "the test command"):

```bash
MSYS_NO_PATHCONV=1 docker exec -u www-data -w /var/www/html/custom_apps/sendentsynchroniser sendent-e2e-nc php vendor/bin/phpunit -c phpunit.xml
```

Expected baseline before any change: `OK (N tests, M assertions)` with no failures. Record N and M; later runs must not lose tests.

- **PHP syntax check inside the container** (referred to below as "the lint command"):

```bash
MSYS_NO_PATHCONV=1 docker exec -w /var/www/html/custom_apps/sendentsynchroniser sendent-e2e-nc sh -c 'find lib appinfo templates -name "*.php" -print0 | xargs -0 -n1 php -l | grep -v "No syntax errors" || echo LINT-OK'
```

Expected: `LINT-OK`.

- **Re-enable the app after PHP changes** (catches autoload / DI errors):

```bash
MSYS_NO_PATHCONV=1 docker exec -u www-data sendent-e2e-nc php occ app:disable sendentsynchroniser
MSYS_NO_PATHCONV=1 docker exec -u www-data sendent-e2e-nc php occ app:enable sendentsynchroniser
```

Expected: `sendentsynchroniser 2.2.0 enabled` (after Task 1; `2.1.0` before).

- **Frontend build** (PowerShell, only Task 8 touches anything the bundle depends on, and it does not; run once at the end as a sanity check):

```powershell
$env:NODE_ENV='production'; node node_modules/webpack/bin/webpack.js --config webpack.prod.js
```

Expected: `webpack 5.x compiled successfully`.

---

### Task 0: Baseline

**Files:** none

- [ ] **Step 1: Start Docker and confirm the box is up**

Run: `docker ps --format '{{.Names}}'`
Expected: includes `sendent-e2e-nc`

- [ ] **Step 2: Sync and run the test command**

Run the sync block, then the test command.
Expected: `OK (N tests, M assertions)`. Write N and M down.

- [ ] **Step 3: Confirm clean tree**

Run: `git status --short`
Expected: empty output.

---

### Task 1: Declare Nextcloud 30–35, bump version, changelog

**Files:**
- Modify: `appinfo/info.xml:8,19`
- Modify: `package.json:3`
- Modify: `CHANGELOG.md` (append)

- [ ] **Step 1: Edit the dependency and version in info.xml**

Change line 8 from

```xml
    <version>2.1.0</version>
```

to

```xml
    <version>2.2.0</version>
```

and line 19 from

```xml
        <nextcloud min-version="28" max-version="34"/>
```

to

```xml
        <nextcloud min-version="30" max-version="35"/>
```

- [ ] **Step 2: Sync package.json version**

Change line 3 of `package.json` from `"version": "2.0.2",` to `"version": "2.2.0",`.

- [ ] **Step 3: Append the changelog entry**

Append to the end of `CHANGELOG.md`:

```markdown

## 2.2.0 - 2026-09-16

### Support
- Nextcloud 35 is supported. Nextcloud 28 and 29 are no longer supported; the app now requires Nextcloud 30 or newer.

### Changed
- Internal clean-up of deprecated Nextcloud APIs (controller attributes, typed app-config accessors, script loading from the settings classes). No functional change.
- Removed the dead `/api/1.0/getConsentFlowPage` route; its template was removed with the Vue 3 migration.
```

- [ ] **Step 4: Validate info.xml against the app store schema**

Run (Git Bash):

```bash
curl -fsSL -o /tmp/info.xsd https://apps.nextcloud.com/schema/apps/info.xsd && MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd)/appinfo:/a:ro" -v /tmp/info.xsd:/info.xsd:ro alpine sh -c 'apk add -q libxml2-utils && xmllint --noout --schema /info.xsd /a/info.xml'
```

Expected: `/a/info.xml validates`

- [ ] **Step 5: Sync, re-enable app**

Run the sync block and the re-enable commands.
Expected: `sendentsynchroniser 2.2.0 enabled`

- [ ] **Step 6: Checkpoint**

```bash
git add appinfo/info.xml package.json CHANGELOG.md
git commit -m "chore: support Nextcloud 30-35, bump to 2.2.0"
```

---

### Task 2: CI matrices and release pipeline for the new range

**Files:**
- Modify: `.github/workflows/php-test.yml:10-43`
- Modify: `.github/workflows/scheduling-suppression-test.yml:44-47`
- Modify: `.github/workflows/lint.yml:22`
- Modify: `.github/workflows/release.yml:7`

- [ ] **Step 1: Replace the php-test matrix**

In `.github/workflows/php-test.yml`, replace the whole `include:` list (lines 12–43) with:

```yaml
        include:
          # NC 30: PHP 8.1 - 8.3
          - php-versions: '8.1'
            nextcloud-versions: 'stable30'
          - php-versions: '8.2'
            nextcloud-versions: 'stable30'
          - php-versions: '8.3'
            nextcloud-versions: 'stable30'
          # NC 31: PHP 8.1 - 8.4
          - php-versions: '8.1'
            nextcloud-versions: 'stable31'
          - php-versions: '8.2'
            nextcloud-versions: 'stable31'
          - php-versions: '8.3'
            nextcloud-versions: 'stable31'
          - php-versions: '8.4'
            nextcloud-versions: 'stable31'
          # NC 32: PHP 8.1 - 8.4
          - php-versions: '8.1'
            nextcloud-versions: 'stable32'
          - php-versions: '8.2'
            nextcloud-versions: 'stable32'
          - php-versions: '8.3'
            nextcloud-versions: 'stable32'
          - php-versions: '8.4'
            nextcloud-versions: 'stable32'
          # NC 33: PHP 8.2 - 8.4
          - php-versions: '8.2'
            nextcloud-versions: 'stable33'
          - php-versions: '8.3'
            nextcloud-versions: 'stable33'
          - php-versions: '8.4'
            nextcloud-versions: 'stable33'
          # NC 34: PHP 8.2 - 8.5
          - php-versions: '8.2'
            nextcloud-versions: 'stable34'
          - php-versions: '8.3'
            nextcloud-versions: 'stable34'
          - php-versions: '8.4'
            nextcloud-versions: 'stable34'
          - php-versions: '8.5'
            nextcloud-versions: 'stable34'
          # NC 35: PHP 8.3 - 8.5
          - php-versions: '8.3'
            nextcloud-versions: 'stable35'
          - php-versions: '8.4'
            nextcloud-versions: 'stable35'
          - php-versions: '8.5'
            nextcloud-versions: 'stable35'
```

- [ ] **Step 2: Point the scheduling-suppression gate at stable35**

In `.github/workflows/scheduling-suppression-test.yml` change

```yaml
      - name: Checkout Nextcloud (stable33)
        # tests/bootstrap.php resolves NC's server bootstrap via ../../../tests,
        # so the app must live under nextcloud/apps/sendentsynchroniser.
        run: git clone https://github.com/nextcloud/server.git --recursive --depth 1 -b stable33 nextcloud
```

to

```yaml
      - name: Checkout Nextcloud (stable35)
        # tests/bootstrap.php resolves NC's server bootstrap via ../../../tests,
        # so the app must live under nextcloud/apps/sendentsynchroniser.
        run: git clone https://github.com/nextcloud/server.git --recursive --depth 1 -b stable35 nextcloud
```

- [ ] **Step 3: Drop PHP 8.0 from the lint matrix, add 8.5**

In `.github/workflows/lint.yml` change line 22

```yaml
        php-versions: ['8.0', '8.1', '8.2', '8.3', '8.4']
```

to

```yaml
        php-versions: ['8.1', '8.2', '8.3', '8.4', '8.5']
```

- [ ] **Step 4: Sign releases with a maintained server**

In `.github/workflows/release.yml` change line 7 from `NEXTCLOUD_SERVER_MAJOR: '30'` to `NEXTCLOUD_SERVER_MAJOR: '34'`. (The value only selects which server tarball supplies `occ integrity:sign-app`; NC 30 tarballs will disappear from `download.nextcloud.com` after end-of-life. Bump to `'35'` in a later release once `latest-35.tar.bz2` exists.)

- [ ] **Step 5: YAML sanity check**

Run: `node -e "const y=require('js-yaml');for(const f of ['.github/workflows/php-test.yml','.github/workflows/scheduling-suppression-test.yml','.github/workflows/lint.yml','.github/workflows/release.yml']){y.load(require('fs').readFileSync(f,'utf8'));console.log('ok',f)}"`
Expected: four `ok` lines. (`js-yaml` is present under `node_modules` as a transitive dependency of webpack tooling; if `Cannot find module`, run `npm i --no-save js-yaml` first.)

- [ ] **Step 6: Checkpoint**

```bash
git add .github/workflows/php-test.yml .github/workflows/scheduling-suppression-test.yml .github/workflows/lint.yml .github/workflows/release.yml
git commit -m "ci: test on stable30-stable35 with PHP 8.1-8.5"
```

---

### Task 3: Use the public token interface instead of the deprecated private shim

`OC\Authentication\Token\IToken` is an empty interface marked `@deprecated 28.0.0` that merely extends `OCP\Authentication\Token\IToken`, where `PERMANENT_TOKEN` and `DO_NOT_REMEMBER` are defined (`@since 28.0.0`). `OC\Authentication\Token\IProvider` (the provider itself) has no public equivalent for `generateToken` / `getTokenByUser` / `invalidateTokenById` and stays as is.

**Files:**
- Modify: `lib/Controller/UserController.php:6,8`
- Modify: `tests/Unit/Service/SyncUserServiceTest.php:7`

- [ ] **Step 1: Switch the test fixture to the public interface first**

In `tests/Unit/Service/SyncUserServiceTest.php` change line 7

```php
use OC\Authentication\Token\IToken;
```

to

```php
use OCP\Authentication\Token\IToken;
```

- [ ] **Step 2: Run the test command**

Expected: still `OK` (the private shim extends the public interface, so mocks of the public one satisfy the provider's return type). This proves the public interface is what the code actually depends on.

- [ ] **Step 3: Fix the controller imports**

In `lib/Controller/UserController.php` delete line 6

```php
use OC\Authentication\Exceptions\InvalidTokenException;
```

(unused; the class is `@deprecated 28.0.0`) and change line 8

```php
use OC\Authentication\Token\IToken;
```

to

```php
use OCP\Authentication\Token\IToken;
```

Lines 139–140 (`IToken::PERMANENT_TOKEN`, `IToken::DO_NOT_REMEMBER`) need no change.

- [ ] **Step 4: Verify nothing else imports the private shim**

Run: `grep -rn "OC\\\\Authentication\\\\Token\\\\IToken\|OC\\\\Authentication\\\\Exceptions" lib tests`
Expected: no output.

- [ ] **Step 5: Sync, lint, re-enable, test**

Run the sync block, the lint command (`LINT-OK`), the re-enable commands, then the test command (`OK`).

- [ ] **Step 6: Checkpoint**

```bash
git add lib/Controller/UserController.php tests/Unit/Service/SyncUserServiceTest.php
git commit -m "refactor: use OCP IToken instead of deprecated OC shim"
```

---

### Task 4: Remove the dead InitialLoadManager and the container `query()` call

`InitialLoadManager::initialLoading()` is empty; the class only writes `firstRunAppVersion=0.1.0` once and nothing reads that key. It is also the only user of the deprecated `OCP\IConfig::getAppValue/setAppValue` and of `IAppContainer::query()` (NC 35 changed `IBootContext::getAppContainer()` to return a PSR `ContainerInterface`, which has no `query()`).

**Files:**
- Delete: `lib/Service/InitialLoadManager.php`
- Modify: `lib/AppInfo/Application.php:8,47-49`

- [ ] **Step 1: Prove the key is unused**

Run: `grep -rn "firstRunAppVersion\|InitialLoadManager" lib tests src appinfo`
Expected: exactly three hits, all in `lib/Service/InitialLoadManager.php` and `lib/AppInfo/Application.php`.

- [ ] **Step 2: Delete the class**

Run: `git rm lib/Service/InitialLoadManager.php` (or `rm` if not committing).

- [ ] **Step 3: Empty the boot hook**

In `lib/AppInfo/Application.php` delete line 8

```php
use OCA\SendentSynchroniser\Service\InitialLoadManager;
```

and replace

```php
	public function boot(IBootContext $context): void {
		$context->getAppContainer()->query(InitialLoadManager::class);
	}
```

with

```php
	public function boot(IBootContext $context): void {
	}
```

(`boot()` must stay: `IBootstrap` requires it.)

- [ ] **Step 4: Sync, lint, re-enable, test**

Run the sync block, the lint command (`LINT-OK`), the re-enable commands (`sendentsynchroniser 2.2.0 enabled`), then the test command (`OK`, same N as baseline).

- [ ] **Step 5: Checkpoint**

```bash
git add -A lib/Service/InitialLoadManager.php lib/AppInfo/Application.php
git commit -m "refactor: drop no-op InitialLoadManager and deprecated container query()"
```

---

### Task 5: Correct the scheduling priority comment

On NC 30–35 Nextcloud's `IMipPlugin` inherits the `schedule` registration from `Sabre\CalDAV\Schedule\IMipPlugin`, which uses priority **120**; sabre's local-delivery handler in `Schedule\Plugin` uses the default **100**. The app's 50 runs before both. Only the comment is wrong.

**Files:**
- Modify: `lib/Sabre/SchedulingSuppressorPlugin.php:37-40`

- [ ] **Step 1: Fix the comment**

Replace

```php
	/**
	 * Priority must be lower (= called earlier) than IMipPlugin's. IMipPlugin
	 * registers at priority 100 in NC 28-33.
	 */
	public const SCHEDULE_PRIORITY = 50;
```

with

```php
	/**
	 * Priority must be lower (= called earlier) than the handlers we suppress.
	 * On NC 30-35 sabre's Schedule\Plugin delivers internally at the default
	 * priority 100 and IMipPlugin (inherited from sabre's IMipPlugin) sends
	 * mail at 120, so 50 runs before both.
	 */
	public const SCHEDULE_PRIORITY = 50;
```

- [ ] **Step 2: Run the plugin test only**

Run: `MSYS_NO_PATHCONV=1 docker exec -u www-data -w /var/www/html/custom_apps/sendentsynchroniser sendent-e2e-nc php vendor/bin/phpunit -c phpunit.xml tests/Unit/Sabre/SchedulingSuppressorPluginTest.php` (after the sync block)
Expected: `OK`

- [ ] **Step 3: Checkpoint**

```bash
git add lib/Sabre/SchedulingSuppressorPlugin.php
git commit -m "docs: correct IMipPlugin schedule priority in comment"
```

---

### Task 6: Remove the dead consent-flow page route

`PageController::getConsentFlowPage()` renders `templates/sections/consentFlow.php`, which was deleted in the Vue 3 migration (commit 51965b6). Nothing in `src/` calls the route, so it currently 500s. The cookie it used to set (`sendentsynchroniser_activationreminder_timeout`) is still read by `SettingsController::shouldShowDialog()`; that read is harmless and stays (follow-up, not this plan: decide whether the Vue dialog should set the cookie itself).

**Files:**
- Modify: `appinfo/routes.php:9`
- Modify: `lib/Controller/PageController.php` (whole file)

- [ ] **Step 1: Remove the route**

In `appinfo/routes.php` delete line 9:

```php
		['name' => 'page#getConsentFlowPage', 'url' => '/api/1.0/getConsentFlowPage', 'verb' => 'GET'],
```

- [ ] **Step 2: Rewrite PageController to the health endpoint only**

Replace the entire content of `lib/Controller/PageController.php` with:

```php
<?php

namespace OCA\SendentSynchroniser\Controller;

use OCP\AppFramework\Controller;
use OCP\IRequest;

class PageController extends Controller {

	public function __construct($AppName, IRequest $request) {
		parent::__construct($AppName, $request);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function health(){
		return "OK";
	}

}
```

(The annotations are converted to attributes in Task 7 together with the other controllers.)

- [ ] **Step 3: Sync, lint, re-enable, curl the health route**

Run the sync block, the lint command, the re-enable commands, then:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -u admin:admin12345 http://localhost:8080/apps/sendentsynchroniser/api/1.0/health
curl -s -o /dev/null -w '%{http_code}\n' -u admin:admin12345 http://localhost:8080/apps/sendentsynchroniser/api/1.0/getConsentFlowPage
```

Expected: `200` then `404`.

- [ ] **Step 4: Run the test command**

Expected: `OK`.

- [ ] **Step 5: Checkpoint**

```bash
git add appinfo/routes.php lib/Controller/PageController.php
git commit -m "fix: remove dead consent-flow page route whose template no longer exists"
```

---

### Task 7: Replace PHPDoc security annotations with PHP attributes

`@NoAdminRequired` / `@NoCSRFRequired` annotations still work on NC 35 (with a debug-level "should use the attribute" log) but the attributes `OCP\AppFramework\Http\Attribute\NoAdminRequired` and `NoCSRFRequired` have existed since NC 27, inside our range. Every affected method is listed; keep each method's other docblock lines.

**Files:**
- Modify: `lib/Controller/PageController.php`
- Modify: `lib/Controller/CalendarResetController.php:32-37,51-56`
- Modify: `lib/Controller/LicenseApiController.php:60-68,186-194`
- Modify: `lib/Controller/StatusApiController.php:36-44`
- Modify: `lib/Controller/SettingsController.php:109-118,263-282`
- Modify: `lib/Controller/UserController.php:102-111,189-194,196-203,212-219,230-237,247-257`

- [ ] **Step 1: PageController**

Add after the `use OCP\AppFramework\Controller;` line:

```php
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
```

and replace

```php
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function health(){
```

with

```php
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function health(){
```

- [ ] **Step 2: CalendarResetController**

Add after `use OCP\AppFramework\Http;`:

```php
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
```

For `status()` (line ~32) and `execute()` (line ~51): delete the ` * @NoAdminRequired` line from each docblock and put `#[NoAdminRequired]` on the line directly above `public function status(): JSONResponse {` and `public function execute(): JSONResponse {`. If a docblock becomes empty (only `/**` and `*/`), delete it.

- [ ] **Step 3: LicenseApiController**

Add after `use OCP\AppFramework\ApiController;`:

```php
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
```

For `show()` and `showInternal()`: remove the two annotation lines from each docblock, keep the `@return DataResponse` line, and add

```php
	#[NoAdminRequired]
	#[NoCSRFRequired]
```

directly above each `public function ...(): DataResponse {`. Note the `showInternal` docblock at line 186 starts at column 0 (`/**` unindented); indent it with one tab while you are there.

- [ ] **Step 4: StatusApiController**

Same two `use` lines after `use OCP\AppFramework\ApiController;`. For `index()`: remove both annotation lines from the docblock, add `#[NoAdminRequired]` and `#[NoCSRFRequired]` above `public function index(): DataResponse {`.

- [ ] **Step 5: SettingsController**

Add after `use OCP\AppFramework\ApiController;`:

```php
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
```

For `getNotificationMethod()` (annotation at line 115) and `shouldShowDialog()` (line 277): remove the ` * @NoAdminRequired` line from the docblock and add `#[NoAdminRequired]` directly above the `public function` line.

- [ ] **Step 6: UserController**

Add after `use OCP\AppFramework\Controller;`:

```php
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
```

Then:

| Method | Remove from docblock | Add above `public function` |
|---|---|---|
| `activate()` | ` * @NoAdminRequired` | `#[NoAdminRequired]` |
| `activateMail()` | ` * @NoAdminRequired` | `#[NoAdminRequired]` |
| `invalidateSelf()` | ` * @NoAdminRequired` | `#[NoAdminRequired]` |
| `invalidate(...)` | ` * @NoCSRFRequired` | `#[NoCSRFRequired]` |
| `invalidateAll(...)` | ` * @NoCSRFRequired` | `#[NoCSRFRequired]` |
| `getActiveUsers(...)` | ` * @NoCSRFRequired` | `#[NoCSRFRequired]` |

Delete any docblock that becomes empty apart from `/**`, blank ` *` lines and `*/`.

- [ ] **Step 7: Confirm no annotation remains**

Run: `grep -rnE "@(NoAdminRequired|NoCSRFRequired|PublicPage|CORS)" lib`
Expected: no output.

Run: `grep -rnc "#\[No\(Admin\|CSRF\)Required\]" lib/Controller`
Expected: `PageController.php:2`, `CalendarResetController.php:2`, `LicenseApiController.php:4`, `StatusApiController.php:2`, `SettingsController.php:2`, `UserController.php:6` (18 attributes, one per removed annotation minus the one that vanished with the consent-flow method).

- [ ] **Step 8: Sync, lint, re-enable, behavioural check**

Run the sync block, the lint command, the re-enable commands, then verify CSRF-free and admin-free access still works exactly as before:

```bash
# NoCSRFRequired + NoAdminRequired: basic-auth GET without CSRF token must be 200
curl -s -o /dev/null -w '%{http_code}\n' -u admin:admin12345 http://localhost:8080/apps/sendentsynchroniser/api/1.0/status
# NoCSRFRequired only (admin required): same call must be 200 for admin
curl -s -o /dev/null -w '%{http_code}\n' -u admin:admin12345 http://localhost:8080/apps/sendentsynchroniser/api/1.0/user/actives
# CSRF still required where no attribute: POST without token must be 412
# (Accept: application/json makes the CSRF failure a 412 JSON error instead of a 403 HTML page)
curl -s -o /dev/null -w '%{http_code}\n' -u admin:admin12345 -H 'Accept: application/json' -X POST http://localhost:8080/apps/sendentsynchroniser/api/1.0/settings/emailDomain -d emailDomain=x
```

Expected: `200`, `200`, `412`. Run the same three commands before starting this task as well; the codes must be identical before and after, which is the proof that attributes reproduce the annotations exactly.

- [ ] **Step 9: Run the test command**

Expected: `OK`.

- [ ] **Step 10: Checkpoint**

```bash
git add lib/Controller
git commit -m "refactor: use PHP attributes for controller security annotations"
```

---

### Task 8: Load settings scripts via Util::addScript instead of template helpers

The template helper `script()` is `@deprecated 24.0.0`. Move script/style loading into the two `ISettings::getForm()` implementations and leave the templates as bare mount points.

**Files:**
- Modify: `lib/Settings/Admin.php:1-14,126-133`
- Modify: `lib/Settings/User.php:1-13,58-70`
- Modify: `templates/indexAdmin.php`
- Modify: `templates/indexUser.php`

- [ ] **Step 1: Admin settings**

In `lib/Settings/Admin.php` add after `use OCP\Settings\ISettings;`:

```php
use OCP\Util;
```

and replace

```php
	public function getForm() {
		$params = $this->getParams();
		$this->initialState->provideInitialState('admin', $params);

		return new TemplateResponse('sendentsynchroniser', 'indexAdmin');
	}
```

with

```php
	public function getForm() {
		$params = $this->getParams();
		$this->initialState->provideInitialState('admin', $params);

		Util::addScript('sendentsynchroniser', 'settings');
		Util::addStyle('sendentsynchroniser', 'style');

		return new TemplateResponse('sendentsynchroniser', 'indexAdmin');
	}
```

- [ ] **Step 2: User settings**

In `lib/Settings/User.php` add after `use OCP\Settings\ISettings;`:

```php
use OCP\Util;
```

and replace

```php
		$this->initialState->provideInitialState('user', [
			'activeUser' => $activeUser,
		]);

		return new TemplateResponse('sendentsynchroniser', 'indexUser');
```

with

```php
		$this->initialState->provideInitialState('user', [
			'activeUser' => $activeUser,
		]);

		Util::addScript('sendentsynchroniser', 'settings');
		Util::addStyle('sendentsynchroniser', 'style');

		return new TemplateResponse('sendentsynchroniser', 'indexUser');
```

- [ ] **Step 3: Strip the templates**

Replace the entire content of `templates/indexAdmin.php` with:

```php
<div id="sendentsynchroniser-admin"></div>
```

and of `templates/indexUser.php` with:

```php
<div id="sendentsynchroniser-user"></div>
```

- [ ] **Step 4: Sync, lint, re-enable**

Run the sync block, the lint command (`LINT-OK`), the re-enable commands.

- [ ] **Step 5: Verify the bundle is still injected**

```bash
curl -s -u admin:admin12345 http://localhost:8080/settings/admin/sendentsynchroniser | grep -c 'sendentsynchroniser/js/settings'
curl -s -u admin:admin12345 http://localhost:8080/settings/admin/sendentsynchroniser | grep -c 'sendentsynchroniser/css/style'
```

Expected: `1` and `1` (the script and stylesheet tags are present once). Then open `http://localhost:8080/settings/admin/sendentsynchroniser` in a browser (admin / admin12345): the Sendent Sync admin section renders its groups table and settings form. Open `http://localhost:8080/settings/user/sendentsynchroniser` as a user in an active group: the consent panel renders.

- [ ] **Step 6: Run the test command**

Expected: `OK`.

- [ ] **Step 7: Checkpoint**

```bash
git add lib/Settings/Admin.php lib/Settings/User.php templates/indexAdmin.php templates/indexUser.php
git commit -m "refactor: load settings assets via Util::addScript instead of deprecated template helpers"
```

---

### Task 9: Migrate to the typed app-config accessors

`OCP\AppFramework\Services\IAppConfig::getAppValue()` / `setAppValue()` are `@deprecated 29.0.0`. Their typed successors `getAppValueString(string $key, string $default = '', bool $lazy = false): string` and `setAppValueString(string $key, string $value, bool $lazy = false, bool $sensitive = false): bool` exist since NC 29. `deleteAppValue()` is not deprecated and stays. All stored values are strings already, so this is a pure rename. One visible side effect: the settings controllers `return $this->appConfig->setAppValue(...)`, which serialised to JSON `null`; `setAppValueString` returns `bool`, so those endpoints now answer `true`. `src/components/SettingsSection.vue:284` and `src/stores/groups.ts:93` await the POST without reading the body, so nothing in the frontend changes.

TDD order: rename the mocked method in the tests first (they then fail because production still calls the old name), then rename production.

**Files:**
- Modify: `tests/Unit/Controller/SettingsControllerShouldShowDialogTest.php:51`
- Modify: `tests/Unit/Service/SchedulingSuppressionServiceTest.php:40`
- Modify: `tests/Unit/Service/TrashbinScrubServiceTest.php:26,56`
- Modify: `lib/Command/EmailTemplate.php`, `lib/Controller/SettingsController.php`, `lib/Controller/UserController.php`, `lib/Cron/NotifyInactiveUsers.php`, `lib/Db/SyncUserMapper.php`, `lib/Service/CollectionService.php`, `lib/Service/SchedulingSuppressionService.php`, `lib/Service/SyncUserService.php`, `lib/Service/TrashbinScrubService.php`, `lib/Settings/Admin.php`, `lib/Settings/User.php`

- [ ] **Step 1: Rename the mocked method in the three tests**

Run (Git Bash):

```bash
sed -i "s/method('getAppValue')/method('getAppValueString')/g" tests/Unit/Controller/SettingsControllerShouldShowDialogTest.php tests/Unit/Service/SchedulingSuppressionServiceTest.php tests/Unit/Service/TrashbinScrubServiceTest.php
grep -rn "getAppValue'" tests
```

Expected grep output: no line still containing `'getAppValue'` (all four occurrences now read `'getAppValueString'`). Also update the explanatory comment at `tests/Unit/Controller/SettingsControllerShouldShowDialogTest.php:48` from `// getAppValue() is declared` to `// getAppValueString() is declared`.

- [ ] **Step 2: Sync and run the test command; expect failures**

Expected: `FAILURES!` with failing tests in `SettingsControllerShouldShowDialogTest`, `SchedulingSuppressionServiceTest` and `TrashbinScrubServiceTest` (production still calls `getAppValue`, whose unconfigured mock returns `''`, so the "enabled" assertions fail). If the suite is green here, Step 1 did not apply; stop and check.

- [ ] **Step 3: Rename the production calls**

Run (Git Bash, from the repo root):

```bash
sed -i -E 's/->getAppValue\(/->getAppValueString(/g; s/->setAppValue\(/->setAppValueString(/g' \
  lib/Command/EmailTemplate.php \
  lib/Controller/SettingsController.php \
  lib/Controller/UserController.php \
  lib/Cron/NotifyInactiveUsers.php \
  lib/Db/SyncUserMapper.php \
  lib/Service/CollectionService.php \
  lib/Service/SchedulingSuppressionService.php \
  lib/Service/SyncUserService.php \
  lib/Service/TrashbinScrubService.php \
  lib/Settings/Admin.php \
  lib/Settings/User.php
grep -rnE "->(get|set)AppValue\(" lib
```

Expected grep output: none. (`lib/Command/EmailTemplate.php:32` keeps `deleteAppValue`, which is correct.)

- [ ] **Step 4: Check the one call without a default**

`lib/Settings/User.php:123` was `getAppValue('activeGroups')`; it is now `getAppValueString('activeGroups')`. The default of `getAppValueString` is `''`, identical to before. No edit needed; just confirm the line reads that way.

- [ ] **Step 5: Sync, lint, re-enable, test**

Run the sync block, the lint command (`LINT-OK`), the re-enable commands, then the test command.
Expected: `OK` with the baseline N tests.

- [ ] **Step 6: Behavioural check of a setter and getter round-trip**

```bash
MSYS_NO_PATHCONV=1 docker exec -u www-data sendent-e2e-nc php occ config:app:get sendentsynchroniser emailDomain
MSYS_NO_PATHCONV=1 docker exec -u www-data sendent-e2e-nc php occ sendentsynchroniser:email-template
MSYS_NO_PATHCONV=1 docker exec -u www-data sendent-e2e-nc php occ sendentsynchroniser:email-template '{userId}@example.com'
MSYS_NO_PATHCONV=1 docker exec -u www-data sendent-e2e-nc php occ config:app:get sendentsynchroniser emailTemplate
MSYS_NO_PATHCONV=1 docker exec -u www-data sendent-e2e-nc php occ sendentsynchroniser:email-template --reset
```

Expected: line 3 prints `Email template set to "{userId}@example.com"`, line 4 prints `{userId}@example.com`, line 5 prints `Email template cleared, default email behaviour restored`.

- [ ] **Step 7: Checkpoint**

```bash
git add lib tests
git commit -m "refactor: use typed IAppConfig string accessors (deprecated since NC 29)"
```

---

### Task 10: Verify on a real Nextcloud 35

The official `nextcloud:35-apache` image is published shortly after the 2026-09-16 release (there was no 35 tag on Docker Hub on 2026-09-14). Run this task as soon as `docker pull nextcloud:35-apache` succeeds. Until then, the stable35 CI rows from Task 2 are the NC 35 signal.

**Files:**
- Modify: `C:\Users\L_u-c\AppData\Local\Temp\claude\c--Users-L-u-c-Sendent-Synchroniser-App-for-Nextcloud\78282e92-c9d1-4ec3-8a41-c726c5ef7c5e\scratchpad\e2e\docker-compose.yml` (add a service)

- [ ] **Step 1: Add an NC 35 service to the compose file**

Append under `services:`:

```yaml
  nc35:
    image: nextcloud:35-apache
    container_name: sendent-e2e-nc35
    ports:
      - "8085:80"
    environment:
      SQLITE_DATABASE: nextcloud
      NEXTCLOUD_ADMIN_USER: admin
      NEXTCLOUD_ADMIN_PASSWORD: admin12345
      NEXTCLOUD_TRUSTED_DOMAINS: localhost
```

Run in that directory: `docker compose up -d nc35`, then wait until `curl -s -o /dev/null -w '%{http_code}' http://localhost:8085/status.php` prints `200` (first boot installs Nextcloud, about a minute).

- [ ] **Step 2: Confirm the server is 35 and PHP ≥ 8.3**

```bash
MSYS_NO_PATHCONV=1 docker exec -u www-data sendent-e2e-nc35 php occ status
MSYS_NO_PATHCONV=1 docker exec sendent-e2e-nc35 php -r 'echo PHP_VERSION, PHP_EOL;'
```

Expected: `versionstring: 35.0.x` and a PHP version 8.3, 8.4 or 8.5.

- [ ] **Step 3: Install the PHPUnit bootstrap shim**

The official image ships no `tests/bootstrap.php`; copy the shim from the NC 31 box:

```bash
docker cp sendent-e2e-nc:/var/www/html/tests/bootstrap.php ./nc-tests-bootstrap.php
MSYS_NO_PATHCONV=1 docker exec sendent-e2e-nc35 mkdir -p /var/www/html/tests
docker cp ./nc-tests-bootstrap.php sendent-e2e-nc35:/var/www/html/tests/bootstrap.php
rm ./nc-tests-bootstrap.php
```

- [ ] **Step 4: Sync the app into NC 35 and enable it**

Run the sync block with `sendent-e2e-nc` replaced by `sendent-e2e-nc35` in every command, then:

```bash
MSYS_NO_PATHCONV=1 docker exec -u www-data sendent-e2e-nc35 php occ app:enable sendentsynchroniser
```

Expected: `sendentsynchroniser 2.2.0 enabled`. Anything like `App does not support this Nextcloud version` means Task 1 was not applied.

- [ ] **Step 5: Run the suite on NC 35**

```bash
MSYS_NO_PATHCONV=1 docker exec -u www-data -w /var/www/html/custom_apps/sendentsynchroniser sendent-e2e-nc35 php vendor/bin/phpunit -c phpunit.xml
```

Expected: `OK` with the baseline N tests.

- [ ] **Step 6: Smoke test the live paths that use private server APIs**

In a browser against `http://localhost:8085` (admin / admin12345):

1. Admin settings → Sendent Sync: set a shared secret, add group `admin` to the active groups, enable "Disable Nextcloud meeting invitations" and the trash-bin scrub. Expected: each toggle saves without an error toast.
2. Personal settings → Sendent Sync: click the consent button. Expected: the flow completes; `occ user:auth-tokens:list admin` (NC 35 command name; on older servers it is `user:auth-tokens:list` too) shows a token named `sendent-synchronization`, and calendar `personal` plus address book `contacts` exist under Calendar / Contacts.
3. Calendar app: create an event with an attendee in the `personal` calendar. Expected: no iMIP mail is generated (check `occ log:tail 20` for the suppressor's debug line and the absence of an `IMipPlugin` send).
4. Delete that event, then in the calendar trash bin inspect it through CalDAV: `curl -u admin:admin12345 'http://localhost:8085/remote.php/dav/calendars/admin/trashbin/'` lists the object; its data contains no `X-SENDENT` property (add one to a test event first through the Exchange connector or by a CalDAV PUT if you want a positive check).
5. Personal settings → "Retract consent". Expected: token gone from `occ user:auth-tokens:list admin`.
6. `occ background-job:list` shows `OCA\SendentSynchroniser\Cron\NotifyInactiveUsers`; `occ background-job:execute <id>` runs without an exception.

Record any failure with the `occ log:tail` output before changing code.

- [ ] **Step 7: Checkpoint**

No repo files change in this task unless a smoke test fails. If one does, fix it in a fresh task with its own test first, then rerun Steps 4–6.

---

### Task 11: Final gate

**Files:** none

- [ ] **Step 1: Full suite on NC 31 box**

Run the sync block and the test command on `sendent-e2e-nc`.
Expected: `OK` with the baseline N tests.

- [ ] **Step 2: Frontend build still green**

Run the webpack production build (PowerShell command in the environment section).
Expected: `compiled successfully`, and `git status --short js/` shows no unexpected changes beyond the rebuilt bundle (if the bundle is tracked, it may change only because of the version bump; that is fine).

- [ ] **Step 3: Diff review**

Run: `git diff main --stat` (or `git status --short` if uncommitted).
Expected files touched: `appinfo/info.xml`, `appinfo/routes.php`, `package.json`, `CHANGELOG.md`, four workflow files, `lib/AppInfo/Application.php`, `lib/Service/InitialLoadManager.php` (deleted), `lib/Sabre/SchedulingSuppressorPlugin.php`, six controllers, two settings classes, two templates, `lib/Command/EmailTemplate.php`, `lib/Cron/NotifyInactiveUsers.php`, `lib/Db/SyncUserMapper.php`, `lib/Service/{CollectionService,SchedulingSuppressionService,SyncUserService,TrashbinScrubService}.php`, four test files. Nothing under `src/`.

- [ ] **Step 4: Confirm no deprecated usage slipped back**

```bash
grep -rnE "->(get|set)AppValue\(|OC\\\\Authentication\\\\Token\\\\IToken|getAppContainer\(\)->query|@NoAdminRequired|@NoCSRFRequired|^script\(|^style\(" lib templates
```

Expected: no output.

- [ ] **Step 5: Push and open the PR (only if the user asked for commits)**

```bash
git push -u origin HEAD
```

PR title: `Support Nextcloud 30-35 and clear deprecated API usage`. Body: link this plan, list the CI matrix change, note the JSON `true` response change on settings setters, and the NC 35 smoke-test results from Task 10.

---

## Follow-ups deliberately not in this plan

- `IConfig::getUserValue/setUserValue` → `IUserConfig` once min-version ≥ 32.
- Drop the `OCA\DAV\Events\CalendarObjectMovedToTrashEvent` registration and `instanceof` once min-version ≥ 32.
- The reminder-timeout cookie is no longer set by anything since the consent template went away; decide whether the Vue dialog should set it or whether `shouldShowDialog()` should stop reading it.
- `NEXTCLOUD_SERVER_MAJOR` in `release.yml` → `'35'` once `latest-35.tar.bz2` is published.
- Optionally store `sharedSecret` with `setAppValueString(..., sensitive: true)` so it is encrypted at rest; this changes what `occ config:app:get` prints, so coordinate with support docs first.
