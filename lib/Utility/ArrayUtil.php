<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2025
 */

namespace OCA\Music\Utility;

/**
 * Static utility functions to work with arrays
 */
class ArrayUtil {

	/**
	 * Extract ID of each array element by calling getId and return
	 * the IDs as an array
	 * @param mixed[] $arr
	 * @return int[]
	 */
	public static function extractIds(array $arr) : array {
		return \array_map(fn($i) => $i->getId(), $arr);
	}

	/**
	 * Extract User ID of each array element by calling getUserId and return
	 * the IDs as an array
	 * @param mixed[] $arr
	 * @return string[]
	 */
	public static function extractUserIds(array $arr) : array {
		return \array_map(fn($i) => $i->getUserId(), $arr);
	}

	/**
	 * Create a look-up table from given array of items which have a `getId` function.
	 * @param mixed[] $array
	 * @return mixed[] where keys are the values returned by `getId` of each item
	 */
	public static function createIdLookupTable(array $array) : array {
		$lut = [];
		foreach ($array as $item) {
			$lut[$item->getId()] = $item;
		}
		return $lut;
	}

	/**
	 * Create a look-up table from given array so that keys of the table are obtained by calling
	 * the given method on each array entry and the values are arrays of entries having the same
	 * value returned by that method.
	 * @param mixed[] $array
	 * @param string $getKeyMethod Name of a method found on $array entries which returns a string or an int
	 * @return array<int|string, mixed[]>
	 */
	public static function groupBy(array $array, string $getKeyMethod) : array {
		$lut = [];
		foreach ($array as $item) {
			$lut[$item->$getKeyMethod()][] = $item;
		}
		return $lut;
	}

	/**
	 * Get difference of two arrays, i.e. elements belonging to $b but not $a.
	 * This function is faster than the built-in array_diff for large arrays but
	 * at the expense of higher RAM usage and can be used only for arrays of
	 * integers or strings.
	 * From https://stackoverflow.com/a/8827033
	 * @param array<int|string> $a
	 * @param array<int|string> $b
	 * @return array<int|string>
	 */
	public static function diff(array $b, array $a) : array {
		$at = \array_flip($a);
		$d = [];
		foreach ($b as $i) {
			if (!isset($at[$i])) {
				$d[] = $i;
			}
		}
		return $d;
	}

	/**
	 * Get unique items from an array of integers and/or strings. This is more performant than the built-in
	 * \array_unique but doesn't work on just any kind of array.
	 * @param array<int|string> $array
	 * @return array<int|string>
	 */
	public static function unique(array $array) : array {
		$unique = [];
		foreach ($array as $val) {
			$unique[$val] = true;
		}
		return \array_keys($unique);
	}

	/**
	 * Get a value matching a key from a dictionary, comparing the key in case-insensitive manner.
	 * @param array<string, mixed> $dictionary
	 * @return ?mixed Value matching the key or null if not found
	 */
	public static function getCaseInsensitive(array $dictionary, string $key) {
		return self::find($dictionary, fn($_, $k) => StringUtil::caselessEqual((string)$k, $key));
	}

	/**
	 * Replacement for \array_find which is available only in PHP 8.4 and later
	 *
	 * @template K Array key
	 * @template V Array value
	 * @phpstan-param array<K,V> $array
	 * @phpstan-param callable(V,K):bool $predicate
	 * @phpstan-return ?V
	 */
	public static function find(array $array, callable $predicate) {
		foreach ($array as $k => $v) {
			if ($predicate($v, $k)) {
				return $v;
			}
		}
		return null;
	}

	/**
	 * Replacement for \array_all which is available only in PHP 8.4 and later
	 *
	 * @template K Array key
	 * @template V Array value
	 * @phpstan-param array<K,V> $array
	 * @phpstan-param callable(V,K):bool $predicate
	 */
	public static function all(array $array, callable $predicate) : bool {
		foreach ($array as $k => $v) {
			if (!$predicate($v, $k)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Replacement for \array_any which is available only in PHP 8.4 and later
	 *
	 * @template K Array key
	 * @template V Array value
	 * @phpstan-param array<K,V> $array
	 * @phpstan-param callable(V,K):bool $predicate
	 */
	public static function any(array $array, callable $predicate) : bool {
		foreach ($array as $k => $v) {
			if ($predicate($v, $k)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get multiple items from @a $array, as indicated by a second array @a $keys.
	 * If @a $preserveKeys is given as true, the result will have the original keys, otherwise
	 * the result is re-indexed with keys 0, 1, 2, ...
	 * @param array<int|string, mixed> $array
	 * @param array<int|string> $keys
	 * @return array<int|string, mixed>
	 */
	public static function multiGet(array $array, array $keys, bool $preserveKeys=false) : array {
		$result = [];
		foreach ($keys as $key) {
			if ($preserveKeys) {
				$result[$key] = $array[$key];
			} else {
				$result[] = $array[$key];
			}
		}
		return $result;
	}

	/**
	 * Get multiple columns from the multidimensional @a $array. This is similar to the built-in
	 * function \array_column except that this can return multiple columns and not just one.
	 * @param array<array<int|string, mixed>> $array
	 * @param array<int|string> $columns
	 * @param int|string|null $indexColumn
	 * @return array<array<int|string, mixed>>
	 */
	public static function columns(array $array, array $columns, $indexColumn=null) : array {
		if ($indexColumn !== null) {
			$array = \array_column($array, null, $indexColumn);
		}

		return \array_map(fn($row) => self::multiGet($row, $columns, true), $array);
	}

	/**
	 * Like the built-in function \array_filter but this one works recursively on nested arrays.
	 * Another difference is that this function always requires an explicit callback condition.
	 * Both inner nodes and leafs nodes are passed to the $condition.
	 * @param mixed[] $array
	 * @param callable(mixed):bool $condition
	 * @return mixed[]
	 */
	public static function filterRecursive(array $array, callable $condition) : array {
		$result = [];

		foreach ($array as $key => $value) {
			if ($condition($value)) {
				if (\is_array($value)) {
					$result[$key] = self::filterRecursive($value, $condition);
				} else {
					$result[$key] = $value;
				}
			}
		}

		return $result;
	}

	/**
	 * Inverse operation of self::filterRecursive, keeping only those items where
	 * the $condition evaluates to *false*.
	 * @param mixed[] $array
	 * @param callable(mixed):bool $condition
	 * @return mixed[]
	 */
	public static function rejectRecursive(array $array, callable $condition) : array {
		$invCond = fn($item) => !$condition($item);
		return self::filterRecursive($array, $invCond);
	}

	/**
	 * Convert the given array $arr so that keys of the potentially multi-dimensional array
	 * are converted using the mapping given in $dictionary. Keys not found from $dictionary
	 * are not altered.
	 * @param array<int|string, mixed> $arr
	 * @param array<int|string, int|string> $dictionary
	 * @return array<int|string, mixed>
	 */
	public static function convertKeys(array $arr, array $dictionary) : array {
		$newArr = [];

		foreach ($arr as $k => $v) {
			$key = $dictionary[$k] ?? $k;
			$newArr[$key] = \is_array($v) ? self::convertKeys($v, $dictionary) : $v;
		}

		return $newArr;
	}

	/**
	 * Walk through the given, potentially multi-dimensional, array and cast all leaf nodes
	 * to integer type. The array is modified in-place. Optionally, apply the conversion only
	 * on the leaf nodes matching the given predicate.
	 * @param mixed[] $arr Input/output array to operate on
	 * @param ?callable(mixed):bool $predicate
	 */
	public static function intCastValues(array &$arr, ?callable $predicate=null) : void {
		\array_walk_recursive($arr, function(&$value) use($predicate) {
			if ($predicate === null || $predicate($value)) {
				$value = (int)$value;
			}
		});
	}

	/**
	 * Given a two-dimensional array, sort the outer dimension according to values in the
	 * specified column of the inner dimension.
	 * @param array<array<string, mixed>> $arr Input/output array to operate on
	 */
	public static function sortByColumn(array &$arr, string $column) : void {
		\usort($arr, fn($a, $b) => StringUtil::caselessCompare($a[$column], $b[$column]));
	}

}
