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
	 * Root parser. Run conditions -> functions -> arithmetical operators -> replace variable
	 *
	 * @param string $string
	 * @return mixed|NULL
	 */
	private function replaceVariable(string $string) {
		return $this->runFunctions($string);
	}

	/**
	 * Search for function or variable and replace it with value from scope.
	 * If value doesn't exist in scope then relaced value is empty string
	 *
	 * @param string $string
	 * @return mixed|NULL
	 */
	private function runFunctions(string $string) {
		$ret = null;

		// Try find functions
		$subMatches = [];
		preg_match_all("/fn\.([^(]+)(\(((?>[^()]++|(?2))*)\))/", $string, $subMatches, PREG_SET_ORDER);

		// Is function
		if (count($subMatches) > 0 ) {
			$tmpScopeNames = [];

			foreach ($subMatches as $subMatch) {
				// Parse function params
				$params = [];
				if ($subMatch[3]) {
					foreach ($this->parseFunctionParams($subMatch[3]) as $fnParam) {
						$fnParam = $this->replaceVariable($fnParam);
						// If some param return null stop executing function
						if (is_null($fnParam)) { $params = null; break; }

						$params[] = $fnParam;
					}
				}

				if (is_array($params)) {
					// Execute function
					$ret = $this->evalFunction($subMatch[1], ...$params);
				}

				$pos = strpos($string, $subMatch[0]);
				if ($pos !== false) {
					$tmpName = str_replace('.', '_', uniqid('temporary_', true));
					$tmpScopeNames[] = $tmpName;
					$this->scope[$tmpName] = $ret;

					$string = substr_replace($string, $tmpName, $pos, strlen($subMatch[0]));
				}
			}

			$ret = $this->runArithmeticOperators($string);

			// Clean scope, usedWords
			$this->scope = array_diff_key($this->scope, array_flip($tmpScopeNames));
			$this->usedWords = array_filter($this->usedWords, function ($word) use ($tmpScopeNames) {
				foreach ($tmpScopeNames as $tmpScopeName) {
					if (substr($word, 0, strlen($tmpScopeName)) === $tmpScopeName) { return false; }
				}
				return true;
			});
		} else {
			// Run arithmetic operators on string
			$ret = $this->runArithmeticOperators($string);
		}

		return $ret;
	}

	private function runArithmeticOperators($string) {
		// String is static value
		if ($string[0] === "'" && $string[strlen($string) - 1] === "'") {
			return substr($string, 1, -1);
		}

		$value = 0;
		preg_match_all("/\((([^()]|(?R))*)\)/", $string, $subMatches, PREG_SET_ORDER);

		if (count($subMatches) > 0) {
			foreach ($subMatches as $subMatch) {
				$bracketsSum = $this->runArithmeticOperators($subMatch[1]);

				$pos = strpos($string, $subMatch[0]);
				if ($pos !== false) {
					$string = substr_replace($string, $bracketsSum, $pos, strlen($subMatch[0]));
				}
			}
		}

		$operatorPriority = ['*', '/', '+', '-'];
		$fnExecOperator = function ($string, $operatorPosition = null) use (& $fnExecOperator, $operatorPriority) {
			if (is_null($operatorPosition)) {
				preg_match_all("/[^(\+\-)]+(\*|\/)[^(\+\-)]+/", $string, $subMatches, PREG_SET_ORDER);

				if (count($subMatches) > 0) {
					foreach ($subMatches as $subMatch) {
						$subValue = $fnExecOperator(str_replace(' ', '', $subMatch[0]), 0);

						$pos = strpos($string, $subMatch[0]);
						if ($pos !== false) {
							$string = substr_replace($string, round($subValue, 4), $pos, strlen($subMatch[0]));
						}
					}
				}

				return $fnExecOperator(preg_match_all("/(\+|\-)/", $string) ? str_replace(' ', '', $string) : $string, 2);
			} else if (array_key_exists($operatorPosition, $operatorPriority)) {
				$subValue = explode($operatorPriority[$operatorPosition], $string);
				$value = $fnExecOperator($subValue[0], $operatorPosition+1);
				if (($countSubValue = count($subValue)) > 1) {
					$fnEvalOperator = function ($a, $b, $operator) {
						$ret = null;
						$a = is_string($a) ? str_replace(',', '.', $a) : $a;
						$b = is_string($b) ? str_replace(',', '.', $b) : $b;

						switch ($operator) {
							case '*': $ret = $a * $b; break;
							case '/': $ret = $a / $b; break;
							case '+': $ret = $a + $b; break;
							case '-': $ret = $a - $b; break;
						}

						return $ret;
					};

					$i = 1;
					do {
						$value = $fnEvalOperator($value, $fnExecOperator($subValue[$i], $operatorPosition+1), $operatorPriority[$operatorPosition]);
					} while (++$i < $countSubValue);
				}

				return $value;
			} else {
				// Is intenger or float
				if (filter_var($string, FILTER_VALIDATE_INT) !== false || filter_var($string, FILTER_VALIDATE_FLOAT) !== false || filter_var($string, FILTER_VALIDATE_FLOAT,  ['options' => ['decimal' => ',']]) !== false) {
					$ret = $string;

				// String is path to value from scope
				} else {
					$ret = $this->replaceVarFromScope(trim($string));
				}

				return $ret;
			}
		};

		return $fnExecOperator($string);
	}

	/**
	 * Replace string path for value from scope
	 *
	 * @param string $string	- Format: ticket.activities[2]['statuses'][0][statuses.item.nameKey]
	 * @return NULL|mixed
	 */
	private function replaceVarFromScope(string $string) {
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
						$buffer = $this->replaceVarFromScope($buffer);
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
	 * Parse arguments for function from string
	 *
	 * @param string $string	- Format: ticket.name, fn.date('H:i:s', fn.time()), fn.strtolower('HI'), fn.inplode(',', array)
	 * @return array|NULL
	 */
	private function parseFunctionParams(string $string):? array {
		$ret = null;
		$depth = 0;
		$buffer = '';
		$strLength = strlen($string);

		// Walk char by char
		for ($i = 0; $i < $strLength; $i++) {
			switch ($string[$i]) {
				case "'":
					$run = true;
					do {
						$buffer.= $string[$i++];

						if ($string[$i] == '\\' && $string[$i + 1] == "'") { $i++; }
						if (! isset($string[$i]) || ($string[$i] == "'" && $string[$i - 1] !== '\\')) { $run = false; }
					} while ($run);
					break;

				case '(':
					$depth++;
					break;

				case ')':
					$depth--;
					break;

				case ',':
					// If $depth == true we are nested argument
					if (! $depth) {
						// Arguments is complete
						if ($buffer !== '') {
							$ret[] = $buffer;
							$buffer = '';
						}
						continue 2;
					}
					break;

				case ' ':
					if (! $depth) { continue 2; }
					break;

			}

			// Fill buffer
			$buffer.= $string[$i];
		}

		// Add last argument
		if ($buffer || $buffer === '0') {
			$ret[] = $buffer;
		}

		return $ret;
	}

	/**
	 * Execute function if fnName is in whitelist
	 *
	 * @param string $fnName
	 * @param mixed ...$params
	 * @return mixed|NULL
	 */
	private function evalFunction(string $fnName, ...$params) {
		$ret = null;
		// Allowed functions
		$allowedFunctionNames = [
			// Math
			'round', 'rand', 'pow', 'floor', 'abs',

			// Date time
			'time', 'date', 'strtotime', 'strtodate',

			// Array
			'explode', 'implode', 'array_column',

			// String
			'trim', 'strlen', 'substr', 'strpos', 'strstr', 'sprintf', 'ucfirst', 'ucwords', 'strtoupper', 'strtolower', 'strip_tags', 'str_replace', 'urlencode', 'rawurlencode'
		];

		if (in_array($fnName, $allowedFunctionNames)) {
			try {
				switch ($fnName) {
					case 'strtodate':
						$ret = date($params[1] ?? 'Y-m-d H:i:s', strtotime($params[0]));
						break;

					case 'strlen':
					case 'substr':
					case 'strpos':
					case 'strstr':
					case 'strtolower':
					case 'strtoupper':
						$fnName = 'mb_'.$fnName;

					default:
						$ret = $fnName(...$params);
						break;
				}
			} catch (\Exception $e) { $ret = null; }
		}

		return $ret;
	}
}