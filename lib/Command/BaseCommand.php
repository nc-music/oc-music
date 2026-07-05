<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2018 - 2026
 */

namespace OCA\Music\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseCommand extends Command {
	protected \OCP\IUserManager $userManager;
	protected \OCP\IGroupManager $groupManager;

	public function __construct(\OCP\IUserManager $userManager, \OCP\IGroupManager $groupManager) {
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		parent::__construct();
	}

	protected function configure() : void {
		$this
			->addArgument(
				'user_id',
				InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
				'specify one or more targeted users'
			)
			->addOption(
				'all',
				null,
				InputOption::VALUE_NONE,
				'target all known users'
			)
			->addOption(
				'group',
				null,
				InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				'specify a targeted group to include all users of that group'
			)
		;
		$this->doConfigure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) : int {
		try {
			self::ensureUsersGiven($input);
			$argUsers = $this->getArgumentUsers($input);
			$groupUsers = $this->getArgumentGroupUsers($input);
			$users = \array_unique(\array_merge($argUsers, $groupUsers));
			if (!$input->getOption('all') && !\count($users)) {
				throw new \InvalidArgumentException("No users in selected groups!");
			}
			$this->doExecute($input, $output, $users);
			return 0;
		} catch (\InvalidArgumentException $e) {
			$output->writeln($e->getMessage());
			return 1;
		}
	}

	/**
	 * @return string[]
	 */
	private function getArgumentUsers(InputInterface $input) : array {
		/** @var string[] */
		$users = $input->getArgument('user_id');
		\assert(\is_array($users));

		foreach ($users as $user) {
			if (!$this->userManager->userExists($user)) {
				throw new \InvalidArgumentException("User <error>$user</error> does not exist!");
			}
		}
		return $users;
	}

	/**
	 * @return string[]
	 */
	private function getArgumentGroupUsers(InputInterface $input) : array {
		$users = [];
		$groups = $input->getOption('group');
		\assert(\is_array($groups));

		foreach ($groups as $group) {
			if (!$this->groupManager->groupExists($group)) {
				throw new \InvalidArgumentException("Group <error>$group</error> does not exist!");
			} else {
				foreach ($this->groupManager->get($group)->getUsers() as $user) {
					\array_push($users, $user->getUID());
				}
			}
		}
		return $users;
	}

	protected static function ensureUsersGiven(InputInterface $input) : void {
		if (!$input->getArgument('user_id')
			&& !$input->getOption('all')
			&& !$input->getOption('group')) {
			throw new \InvalidArgumentException("Specify either the target user(s), --group or --all");
		}
	}

	abstract protected function doConfigure() : void;
	abstract protected function doExecute(InputInterface $input, OutputInterface $output, array $users) : void;
}
