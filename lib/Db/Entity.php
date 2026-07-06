<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2021 - 2025
 */

namespace OCA\Music\Db;

use OCP\IL10N;

/**
 * Base class for all the entities belonging to the data model of the Music app
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method ?string getCreated()
 * @method void setCreated(?string $timestamp)
 * @method ?string getUpdated()
 * @method void setUpdated(?string $timestamp)
 */
class Entity extends \OCP\AppFramework\Db\Entity {
	public string $userId = '';
	public ?string $created = null;
	public ?string $updated = null;

	/**
	 * All entities have a non-empty human-readable name, although the exact name of the
	 * corresponding DB column varies and in some cases, the value may be technically
	 * empty but replaced with some localized place-holder text.
	 *
	 * The derived classes may override this as needed.
	 */
	public function getNameString(IL10N $l10n) : string {
		($l10n); // @phpstan-ignore expr.resultUnused (unused in this base implementation)

		if (\property_exists($this, 'name')) {
			return $this->name ?? '';
		} elseif (\property_exists($this, 'title')) {
			return $this->title ?? '';
		} else {
			return 'UNIMPLEMENTED';
		}
	}

	/**
	 * Override the property setter from the platform base class.
	 *
	 * NOTE: Type declarations should not be used on the parameters because OC and NC < 26
	 * don't use them in the parent class. On those platforms, using the declarations in this
	 * override method would break the PHP contravariance rules.
	 *
	 * @param string $name
	 * @param mixed[] $args
	 */
	protected function setter($name, $args) : void {
		parent::setter($name, $args);
		/**
		 * The parent implementation has such a feature that it doesn't mark a field updated
		 * if the new value is the same as the previous value. This is problematic in case
		 * we have created a new Entity and the new value is the same as the default value.
		 * We may still use that new Entity with Mapper::update with the intention to change
		 * that field on an existing row to its default value. This is what caused
		 * https://github.com/nc-music/oc-music/issues/1251 and probably some other subtle bugs, too.
		 */
		$this->markFieldUpdated($name);
	}
}
