<?php

namespace OCA\SendentSynchroniser\Command;

use OCP\AppFramework\Services\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EmailTemplate extends Command {

	/** @var IAppConfig */
	private $appConfig;

	public function __construct(IAppConfig $appConfig) {
		parent::__construct();
		$this->appConfig = $appConfig;
	}

	protected function configure(): void {
		$this
			->setName('sendentsynchroniser:email-template')
			->setDescription('Show, set or reset the template used to build user email addresses, e.g. "{userId}@domain.com" or "{username}@domain.com". When set, it overrides the primary email address and the email domain setting.')
			->addArgument('template', InputArgument::OPTIONAL, 'Template to set, e.g. "{userId}@domain.com"')
			->addOption('reset', null, InputOption::VALUE_NONE, 'Clear the template and restore the default email behaviour');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('reset')) {
			$this->appConfig->deleteAppValue('emailTemplate');
			$output->writeln('Email template cleared, default email behaviour restored');
			return 0;
		}

		$template = $input->getArgument('template');
		if ($template === null) {
			$current = $this->appConfig->getAppValue('emailTemplate', '');
			if ($current === '') {
				$output->writeln('No email template configured (default email behaviour)');
			} else {
				$output->writeln($current);
			}
			return 0;
		}

		$error = $this->validate($template);
		if ($error !== null) {
			$output->writeln('<error>' . $error . '</error>');
			return 1;
		}

		$this->appConfig->setAppValue('emailTemplate', $template);
		$output->writeln('Email template set to "' . $template . '"');
		return 0;
	}

	private function validate(string $template): ?string {
		if (substr_count($template, '@') !== 1) {
			return 'Template must contain exactly one "@", e.g. "{userId}@domain.com"';
		}
		if (strpos($template, '{userId}') === false && strpos($template, '{username}') === false) {
			return 'Template must contain at least one of the placeholders {userId} or {username}';
		}
		if (preg_match_all('/\{([^{}]*)\}/', $template, $matches)) {
			foreach ($matches[1] as $placeholder) {
				if ($placeholder !== 'userId' && $placeholder !== 'username') {
					return 'Unknown placeholder {' . $placeholder . '}, supported placeholders are {userId} and {username}';
				}
			}
		}
		$domain = substr($template, strpos($template, '@') + 1);
		if ($domain === '' || strpos($domain, '.') === false || strpos($domain, '{') !== false) {
			return 'Template must end with a plain domain after the "@", e.g. "{userId}@domain.com"';
		}
		return null;
	}

}
