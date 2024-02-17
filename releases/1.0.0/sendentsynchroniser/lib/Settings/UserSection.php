<?php

namespace OCA\SendentSynchroniser\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class UserSection implements IIconSection {

		/** @var IL10N */
	private $l;

	/** @var IURLGenerator */
	private $url;

	public function __construct(IURLGenerator $url, IL10N $l) {
		$this->url = $url;
		$this->l = $l;
	}

	/**
	 * 	 * returns the ID of the section. It is supposed to be a lower case string
	 * 	 *
	 *
	 * @returns string
	 *
	 * @return string
	 *
	 * @psalm-return 'sendentsynchroniser'
	 */
	public function getID() {
		return 'sendentsynchroniser'; //or a generic id if feasible
	}

	/**
	 * returns the translated name as it should be displayed, e.g. 'LDAP / AD
	 * integration'. Use the L10N service to translate it.
	 *
	 * @return string
	 */
	public function getName() {
		return $this->l->t('Sendent Sync');
	}

	/**
	 * returns the relative path to an 16*16 icon describing the section.
	 * e.g. '/core/img/places/files.svg'
	 *
	 * @returns string
	 * @since 12
	 */
	public function getIcon(): string {
		return $this->url->imagePath('sendentsynchroniser', 'app-dark.svg');
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 * the settings navigation. The sections are arranged in ascending order of
	 * the priority values. It is required to return a value between 0 and 99.
	 */
	public function getPriority() {
		return 51;
	}
}
