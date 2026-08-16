<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2019 - 2025
 */

namespace OCA\Music\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;

/**
 * This class creates an XML response out of a passed in associative array,
 * similarly how the class JSONResponse works. The content is described with
 * a recursive array structure, where arrays may have string or integer keys.
 * One array should not mix string and integer keys, that will lead to undefined
 * outcome. Furthermore, array with integer keys is supported only as payload of
 * an array with string keys.
 *
 * Note that this response type has been created to fulfill the needs of the
 * SubsonicController and AmpacheController and may not be suitable for all other
 * purposes.
 */
class XmlResponse extends Response {
	private array $content;
	private \DOMDocument $doc;
	/** @var bool|string[] $attributeKeys */
	private $attributeKeys;
	private bool $boolAsInt;
	private bool $nullAsEmpty;
	private ?string $textNodeKey;

	/**
	 * @param array $content
	 * @param bool|string[] $attributes If true, then key-value pair is made into attribute if possible.
	 *                                  If false, then key-value pairs are never made into attributes.
	 *                                  If an array, then keys found from the array are made into attributes if possible.
	 * @param bool $boolAsInt If true, any boolean values are yielded as int 0/1.
	 *                        If false, any boolean values are yielded as string "false"/"true".
	 * @param bool $nullAsEmpty If true, any null values are converted to empty strings, and the result has an empty element or attribute.
	 *                          If false, any null-valued keys are are left out from the result.
	 * @param ?string $textNodeKey When a key within @a $content matches this, the corresponding value is converted to a text node,
	 *                             instead of creating an element or attribute named by the key.
	 */
	public function __construct(array $content, /*mixed*/ $attributes=true,
								bool $boolAsInt=false, bool $nullAsEmpty=false,
								?string $textNodeKey='value') {
		$this->setStatus(Http::STATUS_OK);
		$this->addHeader('Content-Type', 'application/xml');

		// The content must have exactly one root element, add one if necessary
		if (\count($content) != 1) {
			$content = ['root' => $content];
		}
		$this->content = $content;
		$this->doc = new \DOMDocument('1.0', 'UTF-8');
		$this->doc->formatOutput = true;
		$this->attributeKeys = $attributes;
		$this->boolAsInt = $boolAsInt;
		$this->nullAsEmpty = $nullAsEmpty;
		$this->textNodeKey = $textNodeKey;
	}

	/**
	 * @return string
	 */
	public function render() {
		$rootName = (string)\array_keys($this->content)[0];
		$rootElem = $this->doc->createElement($rootName);
		$this->doc->appendChild($rootElem);

		foreach ($this->content[$rootName] as $childKey => $childValue) {
			$this->addChildElement($rootElem, $childKey, $childValue);
		}

		return $this->doc->saveXML();
	}

	/**
	 * Add child element or attribute to a given element. In case the value of the child is an array,
	 * all the nested children will be added recursively.
	 * @param string|int|float|bool|array|\stdClass|null $value
	 */
	private function addChildElement(\DOMElement $parentElem, string $key, /*mixed*/ $value, bool $allowAttribute=true) : void {
		if (\is_bool($value)) {
			if ($this->boolAsInt) {
				$value = $value ? '1' : '0';
			} else {
				$value = $value ? 'true' : 'false';
			}
		} elseif (\is_numeric($value)) {
			$value = (string)$value;
		} elseif ($value === null && $this->nullAsEmpty) {
			$value = '';
		}

		if (\is_string($value)) {
			$value = \preg_replace('/[^\PC\s]/u', '', $value); // purge control characters expect for the whitespace
			if ($key == $this->textNodeKey) {
				$parentElem->appendChild($this->doc->createTextNode($value));
			} elseif ($allowAttribute && $this->keyMayDefineAttribute($key)) {
				$parentElem->setAttribute($key, $value);
			} else {
				$child = $this->doc->createElement($key);
				$child->appendChild($this->doc->createTextNode($value));
				$parentElem->appendChild($child);
			}
		} elseif (\is_array($value)) {
			if (self::arrayIsIndexed($value)) {
				foreach ($value as $child) {
					$this->addChildElement($parentElem, $key, $child, /*allowAttribute=*/false);
				}
			} else { // associative array
				$element = $this->doc->createElement($key);
				$parentElem->appendChild($element);
				foreach ($value as $childKey => $childValue) {
					$this->addChildElement($element, (string)$childKey, $childValue);
				}
			}
		} elseif ($value instanceof \stdClass) {
			// empty element
			$element = $this->doc->createElement($key);
			$parentElem->appendChild($element);
		} elseif ($value === null) {
			// skip
		} else {
			throw new \Exception("Unexpected value type for key $key");
		}
	}

	private function keyMayDefineAttribute(string $key) : bool {
		if (\is_array($this->attributeKeys)) {
			return \in_array($key, $this->attributeKeys);
		} else {
			return \boolval($this->attributeKeys);
		}
	}

	/**
	 * Array is considered to be "indexed" if its first element has numerical key.
	 * Empty array is considered to be "indexed".
	 */
	private static function arrayIsIndexed(array $array) : bool {
		\reset($array);
		return empty($array) || \is_int(\key($array));
	}
}
