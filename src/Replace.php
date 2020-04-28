<?php
namespace Yakub\SimpleTemplating;

/**
 * Main class
 *
 * @author yakub
 */
class Replace implements \JsonSerializable {

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
	public static function compile(string $template, $scope, int $flags = 0):? self {
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
	 * Run after changes in code
	 *
	 * @return boolean
	 */
	public static function testCompile() {
		$testCase = [
			'strings' => [
				'template' => "{{string}}, {{string[4]}}, {{integer}}, {{array}}, {{array[1]}}, {{fn.explode(',', valueForExplode)[1]}}, [{{fn.implode(', ', array)}}], {{object.name}}, {{object.data[0]}}, {{object['string']}}, {{object[string]}}, {{fn.strtoupper(object[string])}}, {{fn.date('H:i:s')}}",
				'output' => 'stringValue, n, 1, , 2, Hi, [1, 2], objectName, objectData, success, successData, SUCCESSDATA, '.date('H:i:s')
			],
			'data' => ['string' => 'stringValue', 'integer' => 1, 'valueForExplode' => 'Hello,Hi', 'array' => [1, 2], 'object' => ['name' => 'objectName', 'data' => ['objectData'], 'string' => 'success', 'stringValue' => 'successData']]
		];

		return static::compile($testCase['strings']['template'], $testCase['data']) == $testCase['strings']['output'];
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
			if (! is_string($replacedValue) && (string) $replacedValue != $replacedValue) {
				$replacedValue = '';
			}

			if (($this->flags & static::USE_URLENCODE)) {
				$replacedValue = rawurlencode($replacedValue);
			}

			return $replacedValue;
		}, $this->template);
	}

	/**
	 * Search for function or variable and replace it with value from scope.
	 * If value doesn't exist in scope then relaced value is empty string
	 *
	 * @param string $string
	 * @return mixed|NULL
	 */
	private function replaceVariable(string $string) {
		$ret = null;

		// Try find function
		$subMatches = [];
		preg_match_all("/fn\.([^(]*)\((([^()]|(?R))*)\)/", $string, $subMatches, PREG_SET_ORDER);
		$subMatches = $subMatches[0] ? $subMatches[0] : [];

		// Is function
		if ($subMatches[1]) {
			// Parse function params
			$params = [];
			foreach ($this->parseFunctionParams($subMatches[2]) as $fnParam) {
				$fnParam = $this->replaceVariable($fnParam);
				// If some param return null stop executing function
				if (is_null($fnParam)) { $params = null; break; }

				$params[] = $fnParam;
			}

			if (is_array($params)) {
				// Execute function
				$ret = $this->runFunction($subMatches[1], ...$params);
			}

			if ($ret && substr($string, 0, strlen($subMatches[0])) === $subMatches[0] && strlen($string) > strlen($subMatches[0])) {
				$tmpName = str_replace('.', '_', uniqid('temporary_', true));
				$this->scope[$tmpName] = $ret;
				$ret = $this->replaceVarFromScope($tmpName.substr($string, strlen($subMatches[0])));

				unset ($this->scope[$tmpName]); $this->usedWords = array_diff($this->usedWords, [$tmpName.substr($string, strlen($subMatches[0]))]);
			}
		} else {
			// String is static value
			if ($string[0] === "'" && $string[strlen($string) - 1] === "'") {
				$ret = substr($string, 1, -1);

			} else if (filter_var($string, FILTER_VALIDATE_INT) !== false || filter_var($string, FILTER_VALIDATE_FLOAT) !== false) {
				$ret = $string;

			// String is path to value from scope
			} else {
				$ret = $this->replaceVarFromScope($string);
			}
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
	private function runFunction(string $fnName, ...$params) {
		$ret = null;
		// Allowed functions
		$allowedFunctionNames = [
			// Math
			'round', 'rand',

			// Date time
			'time', 'date', 'strtotime', 'strtodate',

			// Array
			'explode', 'implode', 'array_column',

			// String
			'trim', 'strlen', 'substr', 'strpos', 'strstr', 'sprintf', 'ucfirst', 'ucwords', 'strtoupper', 'strtolower', 'strip_tags', 'str_replace', 'urlencode'
		];

		if (in_array($fnName, $allowedFunctionNames)) {
			try {
				switch ($fnName) {
					case 'strtodate':
						$ret = date($params[1] ?: DATE_FORMAT, strtotime($params[0]));
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
			if ((is_array($scope) && $return = array_key_exists($buffer, $scope)) || (is_string($scope) && $scope[$buffer])) {
				$scope = $scope[$buffer];

				if (! $string) { $output = $scope; }
				else { $buffer = ''; }
			}

			return $return;
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
		if ($buffer && array_key_exists($buffer, $scope)) {
			$ret = $scope[$buffer];
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

				case ')':
					if ($depth) { $depth--; }
					else {
						// Arguments is complete
						$ret[] = $buffer.$string[$i];
						$buffer = '';
						continue 2;
					}
					break;
			}

			// Fill buffer
			$buffer.= $string[$i];
		}

		// Add last argument
		if ($buffer) {
			$ret[] = $buffer;
		}

		return $ret;
	}
}