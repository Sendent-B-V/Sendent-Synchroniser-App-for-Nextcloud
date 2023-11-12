<?php

namespace OCA\SendentSynchroniser\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;

class SendentFileStorageManager {
	private $appData;

	public function __construct(IAppData $appData) {
		$this->appData = $appData;
		$this->ensureFolderExists();
	}

	private function ensureFolderExists(): void {
		try {
			$folder = $this->appData->getFolder('settings');
		} catch (NotFoundException $e) {
			$folder = $this->appData->newFolder('settings');
		}
	}

	public function writeTxt($group, $key, $content, string $gid = ''): string {
		$this->ensureFolderExists();
		$folder = $this->appData->getFolder('settings');
		$filename = $gid . $group . '_' . $key . 'sync_settinggroupvaluefile.txt';
		try {
			if (!$folder->fileExists($key)) {
				$pngFile = $folder->newFile($filename);
			} else {
				$pngFile = $folder->getFile($filename);
			}
		} catch (NotFoundException $e) {
			$pngFile = $folder->newFile($filename);
		}

		$pngFile->putContent($content);
		return $filename;
	}

	public function writeLicenseTxt(string $content, string $gid = ''): string {
		$this->ensureFolderExists();
		$folder = $this->appData->getFolder('settings');
		try {
			if (!$folder->fileExists('sync_licenseKeyFile')) {
				$pngFile = $folder->newFile($gid . 'sync_licenseKeyFile.txt');
			} else {
				$pngFile = $folder->getFile($gid . 'sync_licenseKeyFile.txt');
			}
		} catch (NotFoundException $e) {
			$pngFile = $folder->newFile($gid . 'sync_licenseKeyFile.txt');
		}

		$pngFile->putContent($content);
		return $gid . 'sync_licenseKeyFile.txt';
	}
	public function writeCurrentlyActiveLicenseTxt(string $content, string $gid = ''): string {
		$this->ensureFolderExists();
		$folder = $this->appData->getFolder('settings');
		try {
			if (!$folder->fileExists('sync_tokenlicenseKeyFile')) {
				$pngFile = $folder->newFile($gid . 'sync_tokenlicenseKeyFile.txt');
			} else {
				$pngFile = $folder->getFile($gid . 'sync_tokenlicenseKeyFile.txt');
			}
		} catch (NotFoundException $e) {
			$pngFile = $folder->newFile($gid . 'sync_tokenlicenseKeyFile.txt');
		}
		$pngFile->putContent($content);
		return $gid . 'sync_tokenlicenseKeyFile.txt';
	}
	public function getCurrentlyActiveLicenseContent($gid = '') {
		try {
			$folder = $this->appData->getFolder('settings');
			$file = $folder->getFile($gid . 'sync_tokenlicenseKeyFile.txt');
			// check if file exists and read from it if possible
			return $file->getContent();
		} catch (NotFoundException $e) {
			return '';
		}
	}
	public function fileExists($group, $key): bool {
		try {
			$folder = $this->appData->getFolder('settings');
			$folder->getFile($group . '_' . $key . 'sync_settinggroupvaluefile.txt');
			return true;
		} catch (NotFoundException $e) {
			return false;
		}
	}
	public function fileLicenseExists(): bool {
		try {
			$folder = $this->appData->getFolder('settings');
			$folder->getFile('sync_licenseKeyFile.txt');
			return true;
		} catch (NotFoundException $e) {
			return false;
		}
	}
	public function getContent($group, $key, $gid = '') {
		try {
			$folder = $this->appData->getFolder('settings');
			$file = $folder->getFile($gid . $group . '_' . $key . 'sync_settinggroupvaluefile.txt');
			// check if file exists and read from it if possible

			return $file->getContent();
		} catch (NotFoundException $e) {
			return '';
		}
	}
	public function getLicenseContent($gid = '') {
		try {
			$folder = $this->appData->getFolder('settings');
			$file = $folder->getFile($gid . 'sync_licenseKeyFile.txt');
			// check if file exists and read from it if possible

			return $file->getContent();
		} catch (NotFoundException $e) {
			return '';
		}
	}
}
