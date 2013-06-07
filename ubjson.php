<?php

class UBJSON {

	const TYPE_ARRAY  = 0;
	const TYPE_OBJECT = 1; 

	//internal constants
	const EOF		= 0;
	const DATA		= 1;

	const NOOP		= 'N';
	const NULL		= 'Z';
	const FALSE		= 'F';
	const TRUE		= 'T';
	const INT8		= 'i';
	const UINT8		= 'U';
	const INT16		= 'I';
	const INT32		= 'l';
	const INT64		= 'L';
	const FLOAT		= 'd';
	const DOUBLE	= 'D';
	const CHAR		= 'C';
	const STRING	= 'S';
	const HIGH_PRECISION = 'H';
	const ARRAY_OPEN 	 = '[';
	const ARRAY_CLOSE	 = ']';
	const OBJECT_OPEN	 = '{';
	const OBJECT_CLOSE	 = '}';


	protected $_decodeType = self::TYPE_ARRAY;

	protected $_source;
	protected $_sourceLength;
	protected $_offset = 0;
	
	protected $_token = self::EOF;
	protected $_tokenValue = null;

	
	protected function __construct($source) {
		$this->_source = $source;
		if (is_string($this->_source)) {
        	$this->_sourceLength = strlen($this->_source);
		}
	}
	
	//encoder
	public static function encode($value) {
		$ubjson = new self($value);
		
		return $ubjson->_encodeValue($value);
	}
	
	protected function _encodeValue(&$value) {
		
		if (is_object($value)) {
			return $this->_encodeObject($value);
		} elseif (is_array($value)) {
			return $this->_encodeArray($value);
		}
		return	$this->_encodeData($value);
	}
	
	protected function _encodeData(&$value) {
		$result = 'null';

		if (is_int($value) || is_float($value)) {
        	$result = $this->_encodeNumeric($value);
        } elseif (is_string($value)) {
            $result = $this->_encodeString($value);
        } elseif ($value === null) {
			$result = self::NULL;
		} elseif (is_bool($value)) {
            $result = $value ? self::TRUE : self::FALSE;
        }

        return $result;
	}
	
	protected function _encodeArray(&$array) {
		
		if (!empty($array) && (array_keys($array) !== range(0, count($array) - 1))) {
			// associative array
			$result = self::OBJECT_OPEN;
			foreach ($array as $key => $value) {
				$key = (string)$key;
				$result .= $this->_encodeString($key).$this->_encodeValue($value);
            }
            $result .= self::OBJECT_CLOSE;
		} else {
			// indexed array
			$result = self::ARRAY_OPEN;
			$length = count($array);
			for ($i = 0; $i < $length; $i++) {
				$result .= $this->_encodeValue($array[$i]);
			}
			$result .= self::ARRAY_CLOSE;
		}
		
		return $result;
	}
	
	protected function _encodeObject(&$object) {
		
		if ($object instanceof Iterator) {
			$propCollection = (array)$object;
		} else {
			$propCollection = get_object_vars($object);
		}
		
		return $this->_encodeArray($propCollection);
	}
	
	protected function _encodeString(&$string) {
		$result = null;
		
		$len = strlen($string);
		if ($len == 1) {
			$result = $prefix = self::CHAR.$string;
		} else {
			$prefix = self::STRING;
			if (preg_match('/^[\d]+(:?\.[\d]+)?$/', $string)) {
				$prefix = self::HIGH_PRECISION;
			}
			$result = $prefix.$this->_encodeNumeric(strlen($string)).$string;
		}
		
		return $result;
	}
	
	protected function _encodeNumeric(&$numeric) {
		$result = null;
		
		if (is_int($numeric)) {
			if (256 > $numeric) {
				if (0 < $numeric) {
					$result = self::UINT8.pack('C', $numeric);
				} else {
					$result = self::INT8.pack('c', $numeric);
				}
			} elseif (32768 > $numeric) {
				$result = self::INT16.pack('s', $numeric);
			} elseif (2147483648 > $numeric) {
				$result = self::INT32.pack('l', $numeric);
			}
		} elseif (is_float($numeric)) {
			$result = self::FLOAT.pack('f', $numeric);
		}
		
		return $result;
	}
	
	//decoder
	public static function decode($source, $decodeType = self::TYPE_ARRAY) {
		$ubjson = new self($source);
		$ubjson->setDecodeValue($decodeType);
		$ubjson->_getNextToken();
		
		return $ubjson->_decodeValue();
	}
	
	public function setDecodeValue($decodeType) {
		$this->_decodeType = $decodeType;
	}
	
	
	protected function _decodeValue() {
		$result = null;
		
		switch ($this->_token) {
			case self::DATA:
				$result = $this->_tokenValue;
				$this->_getNextToken();
				break;
			case self::ARRAY_OPEN:
			case self::OBJECT_OPEN:
				$result = $this->_decodeStruct();
				break;
			default:
				$result = null;
		}
		
		return $result;
	}
		
	protected function _getNextToken() {
		
		$this->_token = self::EOF;
		$this->_tokenValue = null;
		
		if ($this->_offset >= $this->_sourceLength) {
			return $this->_token;
		}
		
		$val = null;
		++$this->_offset;
		$token = $this->_source{$this->_offset-1};
		$this->_token = self::DATA;
		
		switch ($token) {
			case self::INT8:
				list(, $this->_tokenValue) = unpack('c', $this->_read(1));
				break;
			case self::UINT8:
				list(, $this->_tokenValue) = unpack('C', $this->_read(1));
				break;
			case self::INT16:
				list(, $this->_tokenValue) = unpack('s', $this->_read(2));
				break;
			case self::INT32:
				list(, $this->_tokenValue) = unpack('l', $this->_read(4));
				break;
// 			case self::INT64:
// 				//unsupported
//				break;
			case self::FLOAT:
				list(, $this->_tokenValue) = unpack('f', $this->_read(4));
				break;
// 			case self::DOUBLE:
// 				//unsupported
// 				break;
			case self::TRUE:
				$this->_tokenValue = true;
				break;
			case self::FALSE:
				$this->_tokenValue = false;
				break;
			case self::NULL:
				$this->_tokenValue = null;
				break;
			case self::CHAR:
				$this->_tokenValue = $this->_read(1);
				break;
// 			case self::NOOP:
// 				$this->_tokenValue = null;
// 				break;
			case self::STRING:
			case self::HIGH_PRECISION:
				++$this->_offset;
				$len = 0;
				switch ($this->_source{$this->_offset-1}) {
					case self::INT8:
						list(, $len) = unpack('c', $this->_read(1));
						break;
					case self::UINT8:
						list(, $len) = unpack('C', $this->_read(1));
						break;
					case self::INT16:
						list(, $len) = unpack('s', $this->_read(2));
						break;
					case self::INT32:
						list(, $len) = unpack('l', $this->_read(4));
						break;
					default:
						//unsupported
						$this->_token = null;
				}
				$this->_tokenValue = '';
				if ($len) {
					$this->_tokenValue = $this->_read($len);
				}
				break;
			case self::OBJECT_OPEN:
				$this->_token = self::OBJECT_OPEN;
				break;
			case self::OBJECT_CLOSE:
				$this->_token = self::OBJECT_CLOSE;
				break;
			case self::ARRAY_OPEN:
				$this->_token = self::ARRAY_OPEN;
				break;
			case self::ARRAY_CLOSE:
				$this->_token = self::ARRAY_CLOSE;
				break;
			default:
				$this->_token = self::EOF;
		}
		
		return $this->_token;
	}
	
	protected function _decodeStruct() {
		
		$key = 0;
		$tokenOpen = $this->_token;
		
		if ($tokenOpen == self::OBJECT_OPEN && $this->_decodeType == self::TYPE_OBJECT) {
			$result = new stdClass();
		} else {
			$result = array();
		}
		
		$structEnd = array(self::OBJECT_CLOSE, self::ARRAY_CLOSE);
		
		$tokenCurrent = $this->_getNextToken();
		while ($tokenCurrent && !in_array($tokenCurrent, $structEnd)) {
			
			if ($tokenOpen == self::OBJECT_OPEN) {
				$key = $this->_tokenValue;
				$tokenCurrent = $this->_getNextToken();
			} else {
				++$key;
			}

			$value = $this->_decodeValue();
			$tokenCurrent = $this->_token;
			
			if ($tokenOpen == self::OBJECT_OPEN && $this->_decodeType == self::TYPE_OBJECT) {
				$result->$key = $value;
			} else {
				$result[$key] = $value;
			}
			
			if (in_array($tokenCurrent, $structEnd)) {
				break;
			}
		}
		
		$this->_getNextToken();
		
		return $result;
	}
	
	
	protected function _read($bytes) {
		$result = substr($this->_source, $this->_offset, $bytes);
		$this->_offset += $bytes;
		
		return $result;
	}
}
