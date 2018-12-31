<?php

class Toml {
	public $data = null;

	public function __construct(string $toml) {
		$toml = $this->cleanToml($toml);

		$this->processToml($toml);
	}

	private function cleanToml($toml) {
		$toml = str_replace(["\r\n", "\n\r"], "\n", $toml);
		$toml = trim($toml);

		return $toml;
	}

	private function processToml($toml) {
		$tokenGen = $this->tokenizeToml($toml);

		try {
			$this->readTable($tokenGen, []);
		}
		catch (Exception $e) {
			$msg = $e->getMessage();
			$offset = $e->getCode();
			$lines = explode("\n", $toml);

			$line = 0;
			$col = 0;
			$txt = '';

			if ($offset == 0) {
				if ($tokenGen->valid()) {
					$token = $tokenGen->current();
					$offset = $token['offset'];
				}
				else {
					$offset = strlen($toml);
				}
			}

			if ($offset >= 0) {
				$colCnt = 0;
				foreach ($lines as $ndx => $str) {
					$colCnt += strlen($str);
	
					if ($colCnt >= $offset) {
						$line = $ndx + 1;
						$col = $offset - ($colCnt - strlen($str)) + 1;
						if (isset($toml[$offset])) {
							$txt = $toml[$offset];
						}
						break;
					}
	
					$colCnt++;
				}
			}

			if (!$msg) {
				if (!$tokenGen->valid()) {
					$msg = 'Unexpected EOF';
				}
				else {
					switch ($txt) {
						case "\n":
							$txt = '\\n';
							break;
						case "\t":
							$txt = '\\t';
							break;
					}

					$msg = "Unexpected character '{$txt}'";
				}
			}

			if ($offset >= 0) {
				$msg .= " at line {$line} and column {$col}";
			}

			throw new Exception($msg);
		}
	}

	/* Process Token Helpers */
	private function readTable($tokenGen, $path, $arrNdx = false, $depth = 0) {
		$key = false;
		$value = false;
		$lineClear = true;
		$valuePath = $path;
		$definedChildTables = [];

		if ($arrNdx !== false) {
			$valuePath[] = $arrNdx;
			$this->setKeyValueWithPath($this->data, $valuePath, []);
		}

		while ($tokenGen->valid()) {
			$token = $tokenGen->current();

			if ($token['txt'] == "\n") {
				$tokenGen->next();
				$lineClear = true;
				continue;
			}

			if ($token['type'] == '[') {
				$tableType = $token['txt'];
				$tokenGen->next();
				$token = $tokenGen->current();
				$definitionOffset = $token['offset'];
				$newPath = $this->readPath($tokenGen);
				$token = $tokenGen->current();
				if ($token['type'] != ']' || strlen($token['txt']) != strlen($tableType)) {
					throw new Exception();
				}
				$tokenGen->next();
				if (!$tokenGen->valid()) {
					return [null, null, $definitionOffset];
				}
				$token = $tokenGen->current();
				if ($token['txt'] != "\n") {
					throw new Exception();
				}

				while ($newPath) {
					if ($newPath == $path) {
						if (($arrNdx !== false) != ($tableType == '[[')) {
							$fullPath = implode('.', array_map(function($item) {
								return '"' . $item . '"';
							}, $path));
							throw new Exception("You can not redefine {$fullPath}", $definitionOffset);
						}

						return [$newPath, $tableType, $definitionOffset];
					}
					elseif (array_slice($newPath, 0, count($path)) != $path) {
						return [$newPath, $tableType, $definitionOffset];
					}
					elseif ($arrNdx !== false) {
						$newPath = array_merge($valuePath, array_slice($newPath, count($path)));
					}

					$tableArrNdx = false;
					if ($tableType == '[[') {
						$value = $this->getValueWithPath($this->data, $newPath);
						if (is_null($value)) {
							$tableArrNdx = 0;
						}
						else {
							$tableArrNdx = count($value);
						}
					}

					$fullPath = implode('.', array_map(function($item) {
						return '"' . $item . '"';
					}, $newPath));

					if ($tableArrNdx !== false) {
						$fullPath .= ".\"{$tableArrNdx}\"";
					}

					if (in_array($fullPath, $definedChildTables)) {
						throw new Exception('Tables must be defined all in one place', $definitionOffset);
					}
					
					$definedChildTables[] = $fullPath;

					list($newPath, $tableType, $definitionOffset) = $this->readTable($tokenGen, $newPath, $tableArrNdx, $depth + 1);
				}

				return [null, null, null];
			}

			if (!$lineClear) {
				throw new Exception('New definitions need to go on a new line');
			}

			$keyToken = $tokenGen->current();

			$key = $this->readPath($tokenGen);
			$token = $tokenGen->current();
			if ($token['txt'] != '=') {
				throw new Exception();
			}
			$tokenGen->next();
			$value = $this->readValue($tokenGen);

			// Set value
			$this->setKeyValueWithPath($this->data, array_merge($valuePath, $key), $value);
			$lineClear = false;
		}

		return [null, null, null];
	}

	private function readPath($tokenGen) {
		$path = [];
		$string = '';
		$needDelim = false;
		$needPart = true;

		while ($tokenGen->valid()) {
			$token = $tokenGen->current();

			if ($token['txt'] == '.') {
				if ($string) {
					$path[] = $string;
					$string = '';
				}
				else {
					throw new Exception();
				}

				$needDelim = false;
			}
			else {
				if ($token['type'] == '"' || $token['type'] == "'") {
					if ($needDelim) {
						throw new Exception();
					}

					$path[] = $this->readString($tokenGen);
					$needDelim = true;
					$needPart = false;
					continue;
				}
				elseif ($token['type'] == 'l' || $token['type'] == 'd' || $token['txt'] == '_') {
					if ($needDelim) {
						throw new Exception();
					}

					$string .= $token['txt'];
					$needPart = false;
				}
				else {
					if ($needPart) {
						throw new Exception();
					}

					if ($string !== '') {
						$path[] = $string;
					}
					return $path;
				}
			}

			$tokenGen->next();
		}

		throw new Exception();
	}

	private function readValue($tokenGen) {
		if (!$tokenGen->valid()) {
			throw new Exception();
		}

		$token = $tokenGen->current();

		if ($token['type'] == '"' || $token['type'] == "'") {
			return $this->readString($tokenGen);
		}
		elseif ($token['txt'] == '{') {
			return $this->readInlineTable($tokenGen);
		}
		elseif ($token['txt'] == '[') {
			return $this->readArray($tokenGen);
		}
		else {
			$str = '';
			$startOffset = $token['offset'];

			while (strpos(self::VALUE_TERMINATORS, $token['txt']) === false) {
				$str .= $token['txt'];
				$tokenGen->next();
				if (!$tokenGen->valid()) {
					break;
				}
				$token = $tokenGen->current();
			}

			// What is it?
			if ($str == '') {
				throw new Exception();
			}

			if ($str == 'true') {
				return true;
			}
			elseif ($str == 'false') {
				return false;
			}
			elseif (preg_match(self::INT_REGEX, $str, $matches)) {
				unset($matches[0]);
				$matches = array_filter($matches, function($item) {
					return $item != '';
				});
				if (count($matches) == 1) {
					array_unshift($matches, '');
				}
				$matches = array_values($matches);

				$ndx = strpos($matches[1], '__');
				if ($ndx !== false) {
					throw new Exception('Integers with underscores cannot have consecutive underscores', $startOffset + $ndx + strlen($matches[0]));
				}

				if ($matches[1][0] == '_') {
					throw new Exception('Integers with underscores must have a digit on both sides', $startOffset);
				}
				elseif ($matches[1][strlen($matches[1]) - 1] == '_') {
					throw new Exception('Integers with underscores must have a digit on both sides', $startOffset + strlen($matches[1]) - 1 + strlen($matches[0]));
				}

				$matches[1] = str_replace('_', '', $matches[1]);

				switch ($matches[0]) {
					case '':
					case '+':
					case '-':
						$val = "{$matches[0]}{$matches[1]}";
						break;
					case '0x':
						$val = base_convert($matches[1], 16, 10);
						break;
					case '0o':
						$val = base_convert($matches[1], 8, 10);
						break;
					case '0b':
						$val = base_convert($matches[1], 2, 10);
						break;
					default:
						throw new Exception('Unexpected prefix on integer', $startOffset);
				}

				return intval($val);
			}
			elseif (preg_match(self::FLOAT_REGEX, $str, $matches)) {
				if (empty($matches[4])) {
					if ($matches[1][0] == '_') {
						throw new Exception('Floats with underscores must have a digit on both sides', $startOffset);
					}
					elseif ($matches[1][strlen($matches[1]) - 1] == '_') {
						throw new Exception('Floats with underscores must have a digit on both sides', $startOffset + strlen($matches[1]));
					}

					$matches[1] = str_replace('_', '', $matches[1]);

					$val = floatval($matches[1]);
					if (!empty($matches[2])) {
						if ($matches[2][0] == '_') {
							throw new Exception('Floats with underscores must have a digit on both sides', $startOffset + strlen($matches[1]) + 1);
						}
						elseif ($matches[2][strlen($matches[2]) - 1] == '_') {
							throw new Exception('Floats with underscores must have a digit on both sides', $startOffset + strlen($matches[1]) + strlen($matches[2]));
						}
	
						$matches[2] = str_replace('_', '', $matches[2]);

						$val = $val * pow(10, $matches[2]);
					}
				}
				else {
					$val = 1;
					if (!empty($matches[3])) {
						$val = intval($matches[3] . '1');
					}

					$matches[4] = strtoupper($matches[4]);
					$val = $val * constant($matches[4]);
				}

				return $val;
			}
			elseif (preg_match(self::DATE_REGEX, $str, $matches)) {
				unset($matches[0]);
				$matches = array_values(array_filter($matches));
				
				$val = $matches[0];

				if (!empty($matches[1])) {
					$val .= ' ' . $matches[1];

					if (!empty($matches[2])) {
						$matches[2] = intval($matches[2]);
						$val .= '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT) . ':00';
					}
				}

				return $val;
			}
			elseif (preg_match(self::TIME_REGEX, $str, $matches)) {
				return $matches[1];
			}
			else {
				throw new Exception('', $startOffset);
			}
		}
	}

	private function readString($tokenGen) {
		$token = $tokenGen->current();
		$quoteType = $token['txt'];
		$quoteLen = strlen($quoteType);
		$startOffset = $token['offset'];
		$escaping = false;
		$str = '';
		$basicString = $quoteType == '"' || $quoteType == "'";

		$tokenGen->next();
		while (true) {
			if (!$tokenGen->valid()) {
				throw new Exception('String not terminated', $startOffset);
			}

			$token = $tokenGen->current();

			if ($basicString && $token['txt'] == "\n") {
				throw new Exception("Basic and literal strings do not support newlines", $token['offset']);
			}

			if ($quoteType[0] == '"' && $token['type'] == '\\') {
				$escaping = !$escaping;
			}
			else {
				$escaping = false;
			}

			if (!$escaping && $token['txt'] == $quoteType) {
				break;
			}

			$str .= $token['txt'];
			$tokenGen->next();
		}
		$tokenGen->next();

		if ($quoteLen === 3 && $str[0] == "\n") {
			$str = substr($str, 1);
		}

		if ($quoteType == '"' || $quoteType == '"""') {
			// process escaped characters
			if (preg_match_all(self::STRING_ESCAPES, $str, $matches, PREG_OFFSET_CAPTURE)) {
				$newStr = '';
				$lastStrNdx = 0;

				foreach ($matches[0] as $ndx => $fullMatch) {
					$newStr .= substr($str, $lastStrNdx, $fullMatch[1] - $lastStrNdx);

					switch ($matches[2][$ndx][0][0]) {
						case 't':
							$newStr .= "\t";
							break;
						case 'n':
							if ($quoteLen == 3) {
								throw new Exception('Multiline strings should not contain escaped newlines', $startOffset + $quoteLen + $fullMatch[1]);
							}
							$newStr .= "\n";
							break;
						case 'f':
							$newStr .= "\f";
							break;
						case 'r':
							$newStr .= "\r";
							break;
						case '"':
							$newStr .= "\"";
							break;
						case '\'':
							throw new Exception('Escaped single quotes are not allowed', $startOffset + $quoteLen + $fullMatch[1]);
						case '\\':
							$newStr .= "\\";
							break;
						case "\n":
							break;
						case 'u':
							$unicode = substr($matches[2][$ndx][0], 1);
							if (strlen($unicode) == 8) {
								throw new Exception('8 character unicode sequences are not currently supported', $startOffset + $quoteLen + $fullMatch[1]);
							}
							$newStr .= mb_convert_encoding("&#x{$unicode};", 'UTF-8', 'HTML-ENTITIES');
							break;
						default:
							throw new Exception('Unexpected escape sequence', $startOffset + $quoteLen + $fullMatch[1]);
					}

					$lastStrNdx = $fullMatch[1] + strlen($fullMatch[0]);
				}

				$str = $newStr . substr($str, $lastStrNdx);
			}
		}

		return $str;
	}

	private function readInlineTable($tokenGen) {
		$data = [];

		$tokenGen->next();
		while ($tokenGen->valid()) {
			$token = $tokenGen->current();

			if ($token['txt'] == '}') {
				$tokenGen->next();
				return $data;
			}
			elseif ($token['txt'] == ',') {
				$tokenGen->next();
			}

			$key = $this->readPath($tokenGen);
			$token = $tokenGen->current();
			if ($token['txt'] != '=') {
				throw new Exception();
			}
			$tokenGen->next();
			$value = $this->readValue($tokenGen);

			$this->setKeyValueWithPath($data, $key, $value);
		}

		throw new Exception();
	}

	private function readArray($tokenGen) {
		$data = [];
		$type = null;

		$tokenGen->next();
		while ($tokenGen->valid()) {
			$token = $tokenGen->current();

			if ($token['txt'] == "\n" || $token['txt'] == ',') {
				$tokenGen->next();
				continue;
			}
			elseif ($token['txt'] == ']') {
				$tokenGen->next();
				return $data;
			}

			$token = $tokenGen->current();
			$value = $this->readValue($tokenGen);
			if (is_null($type)) {
				$type = gettype($value);
			}
			elseif ($type != gettype($value)) {
				throw new Exception("Array of type {$type} cannot contain data of type " . gettype($value), $token['offset']);
			}

			$data[] = $value;
		}

		throw new Exception();
	}

	/* Helpers */

	private function tokenizeToml($toml) {
		$len = strlen($toml);
		
		$lastCharType = false;
		$token = false;
		$quoteType = false;
		$escaping = false;
		$readingComment = false;

		for ($i = 0; true; $i++) {
			if ($i == $len) {
				if (!$readingComment && ($quoteType || $token['type'] != ' ')) {
					yield $token;
				}

				break;
			}

			$char = $toml[$i];
			$type = $this->getCharacterType($char);

			if (!$type || $type != $lastCharType) {
				if ($token) {
					if ($token['type'] == '\\' && $quoteType && $quoteType[0] == '"') {
						$escaping = !$escaping;
					}
					elseif (($token['type'] == '"' || $token['type'] == "'" ) && !$escaping) {
						if ($quoteType) {
							if ($quoteType == $token['txt']) {
								$quoteType = false;
							}
						}
						else {
							$quoteType = $token['txt'];
						}
					}
					else {
						$escaping = false;
					}

					if (!$quoteType && $token['txt'] == '#') {
						$readingComment = true;
					}
					elseif ($readingComment && $token['txt'] == "\n") {
						$readingComment = false;
					}

					if (!$readingComment && ($quoteType || $token['type'] != ' ')) {
						yield $token;
					}
				}

				$token = [
					'txt' => $char,
					'type' => $type,
					'offset' => $i
				];

				$lastCharType = $type;
			}
			else {
				$token['txt'] .= $char;
			}
		}
	}

	private function getCharacterType($char) {
		$ord = ord($char);

		if ($ord >= 48 && $ord <= 57) {
			return 'd';
		}
		else if (($ord >= 97 && $ord <= 122) || ($ord >= 65 && $ord <= 90)) {
			return 'l';
		}
		else if ($ord == 32 || $ord == 9) {
			return ' ';
		}
		else {
			switch ($char) {
				case '"':
				case "'":
				case '[':
				case ']':
					return $char;
				
				default:
					return false;
			}
		}
	}

	private function setKeyValueWithPath(&$data, $path, $value) {
		// Todo track set values better

		$bak = &$data;
		$lastNdx = count($path) - 1;

		foreach ($path as $ndx => $key) {
			if ($ndx == $lastNdx) {
				if (isset($data[$key])) {
					$fullPath = implode('.', array_map(function($item) {
						return '"' . $item . '"';
					}, array_slice($path, 0, $ndx + 1)));
					throw new Exception("Cannot redefine key {$fullPath}", -1);
				}
				else {
					$data[$key] = $value;
				}
			}
			else {
				if (!isset($data[$key])) {
					$data[$key] = [];
				}
				elseif (!is_array($data[$key])) {
					$fullPath = implode('.', array_map(function($item) {
						return '"' . $item . '"';
					}, array_slice($path, 0, $ndx + 1)));
					throw new Exception("Cannot redefine key {$fullPath}", -1);
				}

				unset($data);
				$data = &$bak[$key];
				unset($bak);
				$bak = &$data;
			}
		}
	}

	private function getValueWithPath(&$data, $path) {
		$bak = &$data;
		$lastNdx = count($path) - 1;

		foreach ($path as $ndx => $key) {
			if ($ndx == $lastNdx) {
				if (isset($data[$key])) {
					return $data[$key];
				}
				else {
					return null;
				}
			}
			else {
				if (!isset($data[$key]) || !is_array($data[$key])) {
					return null;
				}

				unset($data);
				$data = &$bak[$key];
				unset($bak);
				$bak = &$data;
			}
		}
	}

	private static function dataToToml($input, $path = '', $depth = 0) {
		switch (gettype($input)) {
			case 'boolean':
				return [$input ? 'true' : 'false', false];
			case 'integer':
			case 'double':
				return [strval($input), false];
			case 'string':
				$input = '"' . str_replace(
					[
						"\\",
						"\n",
						"\t"
					],
					[
						"\\\\",
						"\\n",
						"\\t"
					],
					$input
				) . '"';
				return [$input, false];
		}

		// simple array
		$isSimpleArray = count($input) == 0;
		$isArrayOfTables = false;
		if (!$isSimpleArray) {
			if (array_keys($input) === range(0, count($input) - 1)) {
				$isArrayOfTables = is_array($input[0]);
				$isSimpleArray = !$isArrayOfTables;
			}
		}

		$toml = '';

		if ($isSimpleArray) {
			foreach ($input as $key => $val) {
				if ($toml) {
					$toml .= ', ';
				}
				list($valueToml) = self::dataToToml($val, $path);
				$toml .= $valueToml;
			}

			$toml = "[{$toml}]";
			return [$toml, false];
		}

		$cleanPath = preg_replace('/\\.?__\\|\\d+\\|__/', '', $path);
		$hadSubKeys = false;
		$indent = str_repeat('  ', $depth);
		$subTableToml = '';

		foreach ($input as $key => $val) {
			if ($isArrayOfTables) {
				$toml .= "\n\n{$indent}[[{$cleanPath}]]";

				foreach ($val as $k => $v) {
					$childPath = "{$k}";
					if ($cleanPath) {
						$childPath = "{$cleanPath}.__|{$key}|__.{$childPath}";
					}
					list($valueToml, $valueIsTable) = self::dataToToml($v, $childPath, $depth + 1);

					if ($valueIsTable) {
						$subTableToml .= "{$valueToml}";
					}
					else {
						$toml .= "\n{$indent}  " . $k . ' = ' . $valueToml;
					}
				}
			}
			else {
				$childPath = $key;
				if ($cleanPath) {
					$childPath = "{$cleanPath}.{$childPath}";
				}

				list($valueToml, $valueIsTable) = self::dataToToml($val, $childPath, $depth);

				if ($valueIsTable) {
					$subTableToml .= "{$valueToml}";
				}
				else {
					$toml .= "\n{$indent}" . $key . ' = ' . $valueToml;
					$hadSubKeys = true;
				}
			}
		}

		if (!$isArrayOfTables && $cleanPath && $hadSubKeys) {
			$toml = "\n\n{$indent}[{$cleanPath}]{$toml}";
		}

		if ($subTableToml) {
			$toml = "{$toml}{$subTableToml}";
		}

		if ($path) {
			return [$toml, true];
		}
		else {
			return trim($toml);
		}
	}

	/* Static Methods */

	public static function parse(string $toml) {
		$obj = new self($toml);
		return $obj->data;
	}
	
	public static function parseFile(string $path) {
		$toml = file_get_contents($path);
		return self::parse($toml);
	}

	public static function toToml($input) {
		return self::dataToToml($input);
	}

	public const INT_REGEX = '/^(0x)([0-9A-F_]+)$|^(0o)([0-7_]+)$|^(0b)([01_]+)$|^([+-]?[0-9_]+)$/i';
	public const FLOAT_REGEX = '/^([+-]?[0-9_]+(?:\\.[0-9_]+)?)(?:e([+-]?[0-9_]+))?$|^([+-])?(inf|nan)$/i';
	public const DATE_REGEX = '/^(\\d{4}-\\d{2}-\\d{2})(?:T?(\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?))?(?:Z(\\d{1,2})?|-(\\d{2}):\\d{2})?$/';
	public const TIME_REGEX = '/^(\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?)$/';
	public const VALUE_TERMINATORS = "\n,}]";
	public const STRING_ESCAPES = '/(\\\\)([tnfr"\'\\\\]|u[0-9A-F]{8}|u[0-9A-F]{4}|\n\\s*)/';
}
