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
	private function readTable($tokenGen, $path) {
		$key = false;
		$value = false;
		$lineClear = false;

		while ($tokenGen->valid()) {
			$token = $tokenGen->current();

			if ($token['txt'] == "\n") {
				$tokenGen->next();
				$lineClear = true;
				continue;
			}

			if ($token['type'] == '[') {
				$tableArr = strlen($token['txt']) == 2;
				$tokenGen->next();
				$path = $this->readPath($tokenGen);
				$closeToken = $tokenGen->current();
				if ($closeToken['type'] != ']' || strlen($closeToken['txt']) != strlen($token['txt'])) {
					throw new Exception();
				}
				$tokenGen->next();

				if ($tableArr) {
					$value = $this->getValueWithPath($this->data, $path);
					if (is_null($value)) {
						$path[] = 0;
					}
					else {
						$path[] = count($value);
					}
				}

				$this->readTable($tokenGen, $path);
				break;
			}

			if (!$lineClear) {
				throw new Exception('New definitions need to go on a new line');
			}

			$key = $this->readPath($tokenGen);
			$token = $tokenGen->current();
			if ($token['txt'] != '=') {
				throw new Exception();
			}
			$tokenGen->next();
			$value = $this->readValue($tokenGen);

			// Set value
			$this->setKeyValueWithPath($this->data, array_merge($path, $key), $value);
		}
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

					if ($token['type'] == 'd' && !$string) {
						throw new Exception('Plain text variables need to start with an alpha character');
					}

					$string .= $token['txt'];
					$needPart = false;
				}
				else {
					if ($needPart) {
						throw new Exception();
					}

					if ($string) {
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
			if (!$str) {
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
				$matches = array_filter($matches);
				if (count($matches) == 1) {
					array_unshift($matches, '');
				}
				$matches = array_values($matches);

				$ndx = strpos($matches[1], '__');
				if ($ndx !== false) {
					throw new Exception('Integers with underscores cannot have consecutive underscores', $startOffset + $ndx + strlen($matches[0]));
				}

				if ($matches[1][0] == '_') {
					throw new Exception('Integers with underscores must have a digit on either side', $startOffset + strlen($matches[0]));
				}
				elseif ($matches[1][strlen($matches[1]) - 1] == '_') {
					throw new Exception('Integers with underscores must have a digit on either side', $startOffset + strlen($matches[1]) - 1 + strlen($matches[0]));
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
				$val = floatval($matches[1]);
				if (!empty($matches[2])) {
					$val = $val * pow(10, $matches[2]);
				}

				return $val;
			}
			elseif (preg_match(self::DATE_REGEX, $str, $matches)) {
				unset($matches[0]);
				$matches = array_values(array_filter($matches));
				
				$val = $matches[0] . ' ' . $matches[1];
				if (!empty($matches[2])) {
					$matches[2] = intval($matches[2]);
					$val .= '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT) . ':00';
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
		while ($tokenGen->valid()) {
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
			if (preg_match_all('/(\\\\)([tnfr"\'\\\\]|u[0-9A-F]{8}|u[0-9A-F]{4}|\n\\s*)/', $str, $matches, PREG_OFFSET_CAPTURE)) {
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

			$value = $this->readValue($tokenGen);

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
					throw new Exception("Cannot re-set key {$fullPath}", -1);
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
					throw new Exception("Cannot re-set key {$fullPath}", -1);
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

	public const INT_REGEX = '/^(0x)([0-9A-F_]+)$|^(0o)([0-7_]+)$|^(0b)([01_]+)$|^([0-9_]+)$/i';
	public const FLOAT_REGEX = '/^([+-]?[0-9]+(?:\\.[0-9]+)?)(e[+-]?[0-9]+)?$/i';
	public const DATE_REGEX = '/^(\\d{4}-\\d{2}-\\d{2})(?:T?(\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?))?(?:Z(\\d{1,2})?|-(\\d{2}):\\d{2})?$/';
	public const TIME_REGEX = '/^(\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?)$/';
	public const VALUE_TERMINATORS = "\n,}]";
}
