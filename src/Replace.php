<?php
namespace Yakub\SimpleTemplating;

/**
 * Main class
 *
 * @author yakub
 */
final class Replace implements \JsonSerializable {

	const USE_URLENCODE = 1;

	public $usedWords = [];
	public $onlyOneParamValue = null;

	/**
	 * Var with values for replace
	 *
	 * @var array
	 */
	private $scope = [];

	/**
	 * Var with raw template string
	 *
	 * @var string
	 */
	private $template = '';

	/**
	 * Var with flags settings
	 *
	 * @var integer
	 */
	private $flags = 0;

	/**
	 *	Var with compiled template
	 *
	 * @var string
	 */
	private $output = '';

	/**
	 * Replace tokens in string with data from scope
	 *
	 * @param string $template
	 * @param array|object $scope
	 * @param integer $flags
	 * @return string|NULL
	 */
	public static function compile(string $template, array $scope = [], int $flags = 0):? self {
		$templateObject = new self($template, $scope, $flags);

		$templateObject->process();

		return $templateObject;
	}

	public function __toString() {
		return (string) $this->output;
	}

	public function jsonSerialize() {
		return (string) $this;
	}

	/**
	 * Class can be created only from itself
	 *
	 * @param string $template
	 * @param array|object $scope
	 */
	private function __construct(string $template, $scope, int $flags = 0) {
		$this->flags = $flags;

		$this->scope = json_decode(json_encode($scope, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
		$this->template = $template;
	}

	/**
	 * Process compilation
	 *
	 * @return string|NULL
	 */
	private function process(): void {
		// Replace all words in pattern {{some_text_or_function}}
		$this->output = preg_replace_callback("/{{([^}}]*)}}/", function ($match) {
			$replacedValue = $this->replaceVariable($match[1]);

			// If template is only one variable
			if ('{{'.$match[1].'}}' == trim($this->template)) { $this->onlyOneParamValue = $replacedValue; }

			// Check wrong string representation
			if (! ($replacedValue === null || is_scalar($replacedValue) || (is_object($replacedValue) && method_exists($replacedValue, '__toString')))
			|| (string) $replacedValue != $replacedValue) {
				$replacedValue = '';
			}

			if (($this->flags & static::USE_URLENCODE)) {
				$replacedValue = rawurlencode($replacedValue);
			}

			return $replacedValue;
		}, $this->template);
	}

	/**
	 * Root parser. Run conditions -> logical operators -> comparison operators -> arithmetical operators -> function -> replace variable
	 *
	 * @param string $string
	 * @return mixed|NULL
	 */
	private function replaceVariable(string $string) {
		if (! is_string($string)) { return $string; }
		$ret = trim($string);

		// Remove unnecessary brackets
		while (preg_match('/^(\(((?>[^()]++|(?1))*)\))$/', $ret)) {
			$ret = substr($ret, 1, -1);
		}

		// Is string condition
		$condition = $this->evalMagicSplit($ret, ['?', ':']);
		if (count($condition) == 3) {
			$result = $this->replaceVariable($condition[0]);
			if (is_null($result)) { return null; }
			return (!! $result) ? $this->replaceVariable($condition[1]) : $this->replaceVariable($condition[2]);
		} else if (count($condition) > 1) {
			return null;
		}

		// Find logical operators
		$logicOperators = array_filter($this->evalMagicSplit($ret, ['&&', '||'], true), function ($value) {
			return trim($value) === '' ? false : true;
		});
		if ((count($logicOperators) - 1) !== 0 && ((count($logicOperators) - 1) % 2) === 0) {
			$a = (bool) $this->replaceVariable(array_shift($logicOperators));
			$operator = array_shift($logicOperators);

			// Continue with parsing next logical operators or return current result
			return (($operator == '&&' && $a) || ($operator == '||' && ! $a)) ? $this->replaceVariable(implode('', $logicOperators)) : $a;
		} else if (count($logicOperators) > 1) {
			return null;
		}

		// Find comparison operators
		$comparisonOperators = array_filter($this->evalMagicSplit($ret, ['===', '!==', '==', '!=', '<>', '>=', '<=', '>', '<'], true), function ($value) {
			return trim($value) === '' ? false : true;
		});
		if (count($comparisonOperators) === 3) {
			return $this->runComparisonOperators($comparisonOperators);
		} else if (count($comparisonOperators) > 1) {
			return null;
		}

		// Find arithmetic operators
		$arithmeticOperators = $this->evalMagicSplit($ret, ['*', '/', '+', '-'], true);
		if ((count($arithmeticOperators) - 1) && ((count($arithmeticOperators) - 1) % 2) === 0) {
			return $this->runArithmeticOperators($arithmeticOperators);
		}

		// Find function
		$subMatch = [];
		if (preg_match("/fn\.([^(]+)(\(((?>[^()]++|(?2))*)\))/", $ret, $subMatch)) {
			return $this->runFunction($string, $subMatch);
		}

		// Is intenger or float
		if (filter_var(str_replace(' ', '', $ret), FILTER_VALIDATE_INT) !== false || filter_var(str_replace(' ', '', $ret), FILTER_VALIDATE_FLOAT) !== false || filter_var(str_replace(' ', '', $ret), FILTER_VALIDATE_FLOAT,  ['options' => ['decimal' => ',']]) !== false) {
			return $ret;
		}

		// Is static string
		else if (is_string($ret) && trim($ret) && in_array(trim($ret)[0], ['"', "'"])) {
			$startingWith = trim($ret)[0];
			return trim(str_replace('\\'.$startingWith, $startingWith, $ret), $startingWith);
		}

		// If is used negation count how many times negate
		$ret = str_replace(' ', '', $ret);
		$negateCount = 0;
		do {
			if ($ret[0] == '!') {
				$negateCount++; $ret = substr($ret, 1);
			} else { break; }
		} while (true);

		// String is path to value from scope
		$ret = $this->runScopeValue($ret);
		// Run negation
		for ($i = 0; $i < $negateCount; $i++) { $ret = ! $ret; }

		return $ret;
	}

	/**
	 * Run comparison operators form array
	 *
	 * @param array $compare	- Array with 3 keys: 0 -> value A, 1 -> operator, 2 -> value B
	 */
	private function runComparisonOperators(array $compare) {
		$ret = null;
		// Eval values A and B
		$a = $this->replaceVariable($compare[0]);
		$b = $this->replaceVariable($compare[2]);
		$operator = $compare[1];

		$a = is_string($a) ? str_replace(' ', '', $a) : $a;
		$b = is_string($b) ? str_replace(' ', '', $b) : $b;
		switch ($operator) {
			case '==': $ret = $a == $b; break;
			case '===': $ret = $a === $b; break;
			case '!=': $ret = $a != $b; break;
			case '!==': $ret = $a !== $b; break;
			case '<>': $ret = $a <> $b; break;
			case '>': $ret = $a > $b; break;
			case '>=': $ret = $a >= $b; break;
			case '<': $ret = $a < $b; break;
			case '<=': $ret = $a <= $b; break;
		}

		return $ret;
	}

	/**
	 * Calculate values from array
	 *
	 * @param array $calculate	- Array keys: 0 -> value A, 1 -> operator, 2 -> value B, 3 -> operator, 4 -> value C, ...
	 */
	private function runArithmeticOperators(array $calculate) {
		$calculateCount = count($calculate);
		// Fix minus values
		for ($i = 1; $i < $calculateCount; $i+= 2) {
			$operator = trim($calculate[$i]);
			$a = trim($calculate[$i - 1]);
			$b = trim($calculate[$i + 1]);
			if (! $a && $a !== '0' && $operator == '-') {
				array_splice($calculate, $i - 1, 3, $operator.$b);
				$calculateCount-= 2;
			}
		}
		// It was only minus value
		if (count($calculate) === 1) {
			return $calculate[0];
		}

		// Define priority operators. At first eval * and / and then + and -
		$execute = ['position' => 1, 'level' => 0, 'operators' => [['*', '/'], ['+', '-']]];
		$fnEvalOperator = function ($a, $b, $operator) {
			$ret = null;
			// Remove spaces and replace "," for "." if string is value with decimals separated by ","
			$a = is_string($a) ? str_replace([' ', ','], ['', '.'], $a) : $a;
			$b = is_string($b) ? str_replace([' ', ','], ['', '.'], $b) : $b;

			switch ($operator) {
				case '*': $ret = $a * $b; break;
				case '/': $ret = $a / $b; break;
				case '+': $ret = $a + $b; break;
				case '-': $ret = $a - $b; break;
			}

			return $ret;
		};

		do {
			// If operator is in current operators group then eval
			if (array_key_exists($execute['position'], $calculate) && in_array($calculate[$execute['position']], $execute['operators'][$execute['level']])) {
				$a = trim($this->replaceVariable($calculate[$execute['position'] - 1]));
				$b = trim($this->replaceVariable($calculate[$execute['position'] + 1]));
				$operator = trim($calculate[$execute['position']]);

				// Replace group with result
				array_splice($calculate, $execute['position'] - 1, 3, $fnEvalOperator($a, $b, $operator));

				$execute['position']-= 2;
			}

			// Jump to next group for eval
			$execute['position']+= 2;
			if (! array_key_exists($execute['position'], $calculate)) {
				// Move to next group of operators and start from begin
				if (array_key_exists(++$execute['level'], $execute['operators'])) {
					$execute['position'] = 1;
				} else { break; }
			}
		} while (true);

		return $calculate[0];
	}

	/**
	 * Run function from string
	 *
	 * @param string $string	- Whole syntax for operator. Used for detect direct access after eval. E.g.: fn.explode('', val)[0]
	 * @param array $subMatch	- Array keys: 0 -> whole match, 1 -> function name, 2 -> ignored, 3 -> function params string E.g.: "'', val"
	 */
	private function runFunction(string $string, array $subMatch) {
		$ret = null;
		$params = [];
		foreach ($this->evalMagicSplit($subMatch[3], [',']) as $fnParam) {
			$fnParam = $this->replaceVariable($fnParam);
			// If some param return null stop executing function
			if (is_null($fnParam)) { $params = null; break; }

			$params[] = $fnParam;
		}

		// Execute function
		if (is_array($params)) {
			// Allowed functions
			$allowedFunctionNames = [
				// Math
				'round', 'rand', 'pow', 'floor', 'abs',

				// Date time
				'time', 'date', 'gmdate', 'strtotime', 'strtodate',

				// Array
				'explode', 'implode', 'array_column',

				// String
				'trim', 'strlen', 'substr', 'strpos', 'strstr', 'sprintf', 'ucfirst', 'ucwords', 'strtoupper', 'strtolower', 'strip_tags', 'str_replace', 'urlencode', 'rawurlencode'
			];

			if (in_array($subMatch[1], $allowedFunctionNames)) {
				try {
					switch ($subMatch[1]) {
						case 'strtodate':
							$ret = date($params[1] ?? 'Y-m-d H:i:s', strtotime($params[0]));
							break;

						case 'strlen':
						case 'substr':
						case 'strpos':
						case 'strstr':
						case 'strtolower':
						case 'strtoupper':
							$subMatch[1] = 'mb_'.$subMatch[1];

						default:
							$ret = $subMatch[1](...$params);
							break;
					}
				} catch (\Exception $e) { $ret = null; }
			}
		}

		// If there is direct access to function return
		if (trim($string) != $subMatch[0]) {
			$tmpName = str_replace('.', '_', uniqid('temporary_', true));
			$this->scope[$tmpName] = $ret;

			// Replace function string for tmp variable
			$tmpString = str_replace($subMatch[0], $tmpName, $string);

			// Run with tmp variable name and then clean scope and usedWords
			$ret = $this->replaceVariable($tmpString);
			unset($this->scope[$tmpName]);
			$this->usedWords = array_filter($this->usedWords, function ($word) use ($tmpName) {
				return (substr($word, 0, strlen($tmpName)) === $tmpName) ? false : true;
			});
		}

		return $ret;
	}

	/**
	 * Replace string path for value from scope
	 *
	 * @param string $string	- Format: ticket.activities[2]['statuses'][0][statuses.item.nameKey]
	 * @return NULL|mixed
	 */
	private function runScopeValue(string $string) {
		$this->usedWords = array_unique(array_merge($this->usedWords, [$string]));

		$ret = null;
		$buffer = '';
		$scope = $this->scope;
		$strLength = strlen($string);

		// Function for actualize scope, buffer and output value
		$actualizeScope = function (& $scope, & $buffer, & $output, $string) {
			$ret = null;
			if ((is_array($scope) && $ret = array_key_exists($buffer, $scope)) || (is_string($scope) && substr($scope, $buffer, 1))) {
				$scope = $scope[$buffer];

				if (! $string) { $output = $scope; }
				$buffer = '';
			}

			return $ret;
		};

		// Walk char by char
		for ($i = 0; $i < $strLength; $i++) {
			switch ($string[$i]) {
				// Action char
				case '.':
				case '[':
					if ($buffer) { if (! $actualizeScope($scope, $buffer, $ret, isset($string[$i + 1]))) { break 2; } }
					// For . it was all
					if ($string[$i] === '.') { break; }

					// For [ parse whole name. Can be nested
					$nestedData = ['buffer' => '', 'depth' => 1];
					do {
						if (! isset($string[++$i])) { break 3; }
						$nestedData['buffer'].= $string[$i];

						if ($string[$i] === '[') { $nestedData['depth']++; }
						else if ($string[$i] === ']') { $nestedData['depth']--; }
					} while ($nestedData['depth'] !== 0);
					$buffer = substr($nestedData['buffer'], 0, -1);

					// Is static name
					if ($buffer[0] === "'" && $buffer[strlen($buffer) - 1] === "'") {
						$buffer = substr($buffer, 1, -1);

					// If not integer run recursively
					} else if ((string) (int) $buffer !== $buffer) {
						$buffer = $this->replaceVariable($buffer);
					}

					if (! $actualizeScope($scope, $buffer, $ret, isset($string[$i + 1]))) { break 2; }
					break;

				// Fill buffer
				default:
					$buffer.= $string[$i];
					break;
			}
		}

		// Set output value
		if ($buffer) {
			$scope = is_array($scope) ? $scope : (string) $scope;

			if (is_string($scope)) {
				$ret = substr($scope, $buffer, 1);
			} else if (is_array($scope) && array_key_exists($buffer, $scope)) {
				$ret = $scope[$buffer];
			}
		}

		return $ret;
	}

	/**
	 * Split string but ignoring splitter in brackets or question marks
	 *
	 * @param string	$string			- String to split ignoring split char in brackets or question marks
	 * @param array		$split			- Array of chars for split. Can contains more than one char. E.g.: "&&" - Split only when in string are two &
	 * @param bool		$keepSplitter	- Add to return array splitter character
	 */
	private function evalMagicSplit(string $string, array $split, bool $keepSplitter = false) {
		$depth = 0; $stack = []; $buffer = ''; $length = strlen($string);

		for ($i = 0; $i < $length; $i++) {
			$char = $string[$i];

			switch (true) {
				// Set depth
				case ($char == '('): $depth++; break;
				case ($char == ')'): $depth--; break;

				// Is in question mark
				case $char == "'":
				case $char == '"':
					do {
						$buffer.= $string[$i++];

						// Is last char or starting question mark is escaped
						if (! isset($string[$i]) || ($string[$i] == $char && $string[$i - 1] != '\\')) {
							break;
						}
					} while (true);
					break;

				default:
					if ($depth == 0) {
						foreach ($split as $spliter) {
							if (substr($string, $i, strlen($spliter)) == $spliter) {
								$stack[] = $buffer;
								if ($keepSplitter) { $stack[] = $spliter; }
								$buffer = '';
								$i = $i + (strlen($spliter) - 1);
								continue 3;
							}
						}
					}
					break;
			}

			$buffer.= $char;
		}

		if ($buffer !== '') {
			$stack[] = $buffer;
			$buffer = '';
		}

		return $stack;
	}
}