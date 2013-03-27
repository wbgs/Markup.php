<?php
namespace Markup;

class Mark
{
	private static $cachedPipes;
	public static $pipes = [];
	public static $includes = [];
	public static $globals = [];
	public static $delimiter = '>';
	public static $compact = false;


	static public function up($template, $context = [], $options = []) {
		// Match all tags like "{{...}}".
		$re = '/\{\{(.+?)\}\}/';
		$i = 0;
		// All tags in the template.
		preg_match_all($re, $template, $tags);

		$tags = $tags[0];

		self::$cachedPipes = [];

		// Set custom pipes, if provided.
		if (isset($options['pipes'])) {
			self::$pipes = $options['pipes'];
		}

		// Set templates to include, if provided.
		if (isset($options['includes'])) {
			self::$includes = $options['includes'];
		}

		// Set global variables, if provided.
		if (isset($options['globals'])) {
			foreach ($options['globals'] as $k => $v)
				self::$globals[$k] = $v;
		}

		// Optionally override the delimiter.
		if (isset($options['delimiter'])) {
			self::$delimiter = $options['delimiter'];
		}

		// Optionally collapse white space.
		if (isset($options['compact'])) {
			self::$compact = $options['compact'];
		}

		// Loop through tags, e.g. {{a}}, {{b}}, {{c}}, {{/c}}.
		while (1) {
			if (!array_key_exists($i, $tags)) break;
			$tag = $tags[$i];
			$i++;

			$result = 'undefined';
			$child = '';

			$selfy = strpos($tag, '/}}') !== false;
			$prop = substr($tag, 2, strlen($tag) - ($selfy ? 5 : 4));
			$prop = preg_replace_callback('/`(.+?)`/', function($s) use ($context) {
				return Mark::up("{{{$s[1]}}}", $context);
			}, $prop);
			$testy = strpos(trim($prop), 'if ') === 0;
			$filters = explode('|', $prop);
			array_shift($filters);
			$prop = preg_replace('/^\s*if/', '', $prop);
			$prop = trim(array_shift(explode('|', $prop)));

			$token = $testy ? 'if' : current(explode('|', $prop));

			if (is_object($context)) {
				$ctx = isset($context->$prop) ? $context->$prop : null;
			} else {
				$ctx = isset($context[$prop]) ? $context[$prop] : null;
			}

			// If an "if" statement without filters, assume "{{if foo|notempty}}"
			if ($testy && !count($filters)) {
				$filters = ['notempty'];
			}

			// Does the tag have a corresponding closing tag? If so, find it and move the cursor.
			if (!$selfy && strpos($template, '{{/'.$token) !== false) {
				$result = self::bridge($template, $token);
				$tag = $result[0];
				$child = $result[1];
				preg_match_all($re, $tag, $matches);
				$i += count($matches[0]) - 1; // fast forward
			}

			// Skip "else" tags. These are pulled out in _test().
			if (preg_match('/^\{\{\s*else\s*\}\}$/', $tag)) {
				continue;
			}

			// Evaluating a global variable.
			elseif (isset(self::$globals[$prop])) {
				$result = self::_eval(self::$globals[$prop], $filters, $child);
			}

			// Evaluating an included template.
			elseif (isset(self::$includes[$prop])) {
				$include = self::$includes[$prop];

				if (is_a($include, 'Closure')) {
					$include = $include();
				}

				$result = self::_pipe(Mark::up($include, $context), $filters);
			}

			// Evaluating a loop counter ("#" or "##").
			else if (strpos($prop, '#') !== false) {
				$options['iter']->sign = $prop;
				$result = self::_pipe($options['iter'], $filters);
			}

			// Evaluating the current context.
			else if ($prop === '.') {
				$result = self::_pipe($context, $filters);
			}

			// Evaluating a variable with dot notation, e.g. "a.b.c"
			elseif (strpos($prop, '.') !== false) {
				$prop = explode('.', $prop);
				$ctx = isset(self::$globals[$prop[0]]) ? self::$globals[$prop[0]] : null;

				if ($ctx) {
					$j = 1;
				} else {
					$j = 0;
					$ctx = $context;
				}

				// Get the actual context
				while ($ctx && $j < count($prop)) {
					$ctx = isset($ctx[$prop[$j]]) ? $ctx[$prop[$j]] : 'undefined';
					$j++;
				}

				$result = self::_eval($ctx, $filters, $child);
			}

			// Evaluating an "if" statement.
			else if ($testy) {
				$result = self::_pipe($ctx, $filters);
			}

			// Evaluating an array, which might be a block expression.
			else if (is_array($ctx) || $child) {
				$result = self::_eval($ctx, $filters, $child);
			}

			// Evaluating a block expression.
			elseif ($child) {
				$result = $ctx ? Mark::up($child, $ctx) : 'undefined';
			}

			// Evaluating anything else.
			elseif (array_key_exists($prop, $context)) {
				$result = self::_pipe($ctx, $filters);
			}

			// Evaluating an "if" statement.
			if ($testy) {
				$result = self::_test($result, $child, $context, $options);
			}

			$result = self::jsBool($result);

			if (is_array($result)) continue;

			// Replace the tag, e.g. "{{name}}", with the result, e.g. "Adam".
			$template = str_replace($tag, $result === 'undefined' ? '???' : $result, $template);
		}

		return self::$compact ? preg_replace('/>\s+</', '><', $template) : $template;
	}


	// Get the value of a number or size of an array. This is a helper function for several pipes.
	private static function _size($a) {
		if (is_array($a)) return count($a);

		return $a ? $a : 0;
	}


	private static function _iter($idx, $size) {
		$iter = new Iter;
		$iter->idx = $idx;
		$iter->size = $size;
		return $iter;
	}


	// Pass a value through a series of pipe expressions, e.g. _pipe(123, ["add>10","times>5"]).
	private static function _pipe($val, $expressions) {
		// If we have expressions, pull out the first one, e.g. "add>10".
		if (($expression = array_shift($expressions)) !== null) {
			// Split the expression into its component parts, e.g. ["add", "10"].
			$parts = explode(self::$delimiter, $expression);

			// Pull out the function name, e.g. "add".
			$fn = trim(current($parts));

			try {
				$pipes = self::_pipes();

				// Run the function, e.g. add(123, 10) ...

				if (isset($pipes[$fn]))
					$result = call_user_func_array($pipes[$fn], [$val] + $parts);
				else
					$result = $val;

				// ... then pipe again with remaining expressions.
				$val = self::_pipe($result, $expressions);
			} catch (Exception $e) {
			}
		}

		// Return the piped value.
		return $val;
	}


	private static function _eval($context, $filters, $child) {
		$result = self::_pipe($context, $filters);
		$ctx = $result;
		$i = -1;

		if (is_array($result)) {
			if (key($result) === 0) { // "array", rather than a hash.
				$result = '';
				$j = count($ctx);

				while (++$i < $j) {
					$opts = [
						'iter' => self::_iter($i, $j),
					];

					$result .= $child ? Mark::up($child, $ctx[$i], $opts) : $ctx[$i];
				}
			} else {
				$result = Mark::up($child, $ctx);
			}
		}

		return $result;
	}


	// Process the contents of an IF or IF/ELSE block.
	private static function _test($bool, $child, $context, $options) {
		// Process the child string, then split it into the IF and ELSE parts.
		$str = Mark::up($child, $context, $options);
		$str = preg_split('/\{\{\s*else\s*\}\}/', $str);

		// Return the IF or ELSE part. If no ELSE, return an empty string.

		if ($bool === false)
			return isset($str[1]) ? $str[1] : '';

		return $str[0];
	}


	// Determine the extent of a block expression, e.g. "{{foo}}...{{/foo}}"
	private static function bridge($tpl, $tkn) {
		$exp = "/{{\\s*" . $tkn . "([^\/}]+\\w*)?}}|{{\/" . $tkn . "\\s*}}/";
		preg_match_all($exp, $tpl, $tags);
		$tags = $tags[0];

		$a = 0;
		$b = 0;
		$c = -1;
		$d = 0;

		for ($i = 0; $i < count($tags); $i++) {
			$t = $i;
			$c = strpos($tpl, $tags[$t], $c + 1);

			if (strpos($tags[$t], '{{/') !== false) {
				$b++;
			} else {
				$a++;
			}

			if ($a === $b) {
				break;
			}
		}

		$a = strpos($tpl, $tags[0]);
		$b = $a + strlen($tags[0]);
		$d = $c + strlen($tags[$t]);

		// Return the block, e.g. "{{foo}}bar{{/foo}}" and its child, e.g. "bar".
		return [substr($tpl, $a, $d - $a), substr($tpl, $b, $c - $b)];
	}


	// Helper function to convert true, false and null into "true", "false" and "null".
	// This is to keep with JS string coercion where (true + " " + false + " " + null) === "true false null".
	private static function jsBool($str) {
		if ($str === true) return 'true';
		if ($str === false) return 'false';
		if ($str === null) return 'null';
		return $str;
	}


	private static function _pipes() {
		if (self::$cachedPipes) return self::$cachedPipes;

		$pipes = [
			'empty' => function($obj) {
				if (is_string($obj) && trim($obj) == '') return $obj;
				return empty($obj) ? $obj : false;
			},
			'notempty' => function($obj) {
				if (is_string($obj) && trim($obj) == '') return false;
				return empty($obj) ? false : $obj;
			},
			'blank' => function($str, $val) {
				return !!$str || $str === 0 ? $str : $val;
			},
			'more' => function($a, $b) {
				return self::_size($a) > $b ? $a : false;
			},
			'less' => function($a, $b) {
				return self::_size($a) < $b ? $a : false;
			},
			'ormore' => function($a, $b) {
				return self::_size($a) >= $b ? $a : false;
			},
			'orless' => function($a, $b) {
				return self::_size($a) <= $b ? $a : false;
			},
			'between' => function($a, $b, $c) {
				$a = self::_size($a);
				return $a >= $b && $a <= $c ? $a : false;
			},
			'equals' => function($a, $b) {
				return $a == $b ? $a : false;
			},
			'notequals' => function($a, $b) {
				return $a != $b ? $a : false;
			},
			'like' => function($str, $pattern) {
				return preg_match('/'.$pattern.'/i', $str) > 0;
			},
			'notlike' => function($str, $pattern) {
				return preg_match('/'.$pattern.'/i', $str) === 0;
			},
			'upcase' => function ($str) {
				return strtoupper($str);
			},
			'downcase' => function ($str) {
				return strtolower($str);
			},
			'capcase' => function($str) {
				return ucwords($str);
			},
			'chop' => function($str, $n) {
				$n = (int) $n;
				return strlen($str) > $n ? substr($str, 0, $n) . '...' : $str;
			},
			'tease' => function($str, $n) {
				$n = (int) $n;
				$parts = preg_split('/\s+/', $str);
				$count = count($parts);
				$parts = array_slice($parts, 0, $n);
				return implode(' ', $parts) . ($count > $n ? '...' : '');
			},
			'trim' => function ($str) {
				return trim($str);
			},
			'pack' => function($str) {
				return preg_replace('/\s{2,}/', ' ', trim($str));
			},
			'round' => function($num) {
				return round($num);
			},
			'clean' => function($str) {
				return preg_replace('/<\/?[^>]+>/i', '', $str);
			},
			'size' => function($obj) {
				if ($obj instanceof Iter) $obj = $obj->getIdx();
				return is_array($obj) ? count($obj) : strlen($obj);
			},
			'length' => function($obj) {
				if ($obj instanceof Iter) $obj = $obj->getIdx();
				return is_array($obj) ? count($obj) : strlen($obj);
			},
			'reverse' => function($arr) {
				return array_reverse($arr);
			},
			'join' => function($arr, $separator = null) {
				if ($separator === null) $separator = ',';
				return implode($separator, $arr);
			},
			'limit' => function($arr, $count, $idx = 0) {
				return array_slice($arr, $idx, $count);
			},
			'split' => function($str, $separator = ',') {
				return explode($separator, $str);
			},
			'choose' => function($bool, $iffy, $elsy = false) {
				if (!!$bool) return $iffy;
				return $elsy ? $elsy : '';
			},
			'toggle' => function($obj, $csv1, $csv2, $str = '') {
				$obj = (string) $obj;
				$parts = explode(',', $csv2);
				preg_match_all('/\w+/', $csv1, $options);

				foreach ($options[0] as $k => $v) {
					if ($v == $obj) return $parts[$k];
				}

				return $str;
			},
			'sort' => function($arr, $prop = null) {
				usort($arr, function($a, $b) use ($prop) {
					if (is_array($a) && is_array($b) && isset($a[$prop]) && isset($b[$prop]))
						return $a[$prop] > $b[$prop] ? 1 : -1;
					else
						return $a > $b ? 1 : -1;
				});

				return $arr;
			},
			'fix' => function($num, $n) {
				if ($num instanceof Iter) $num = $num->getIdx();

				$num = (float) $num;
				$n = (int) $n;

				return number_format($num, $n);
			},
			'mod' => function($num, $n) {
				if ($num instanceof Iter) $num = $num->getIdx();

				$num = (int) $num;
				$n = (int) $n;

				return $num % $n;
			},
			'divisible' => function($num, $n) {
				if ($num === false) return false;

				if ($num instanceof Iter) $num = $num->getIdx();

				$num = (int) $num;
				$n = (int) $n;

				return $num % $n === 0 ? true : false;
			},
			'even' => function($num) {
				if ($num instanceof Iter) $num = $num->getIdx();

				return $num % 2 === 0 ? $num : false;
			},
			'odd' => function($num) {
				if ($num instanceof Iter) $num = $num->getIdx();

				return $num % 2 === 0 ? false : $num;
			},
			'number' => function($str) {
				return (float) preg_replace('/[^\-\d\.]/', '', $str);
			},
			'url' => function($str) {
				$unescaped = array(
					'%2D'=>'-','%5F'=>'_','%2E'=>'.','%21'=>'!', '%7E'=>'~', '%2A'=>'*', '%27'=>"'", '%28'=>'(', '%29'=>')'
				);
				$reserved = array(
					'%3B'=>';','%2C'=>',','%2F'=>'/','%3F'=>'?','%3A'=>':', '%40'=>'@','%26'=>'&','%3D'=>'=','%2B'=>'+','%24'=>'$'
				);
				$score = array(
					'%23'=>'#'
				);
				return strtr(rawurlencode($str), array_merge($reserved, $unescaped, $score));
			},
			'bool' => function($obj) {
				return !!$obj;
			},
			'falsy' => function($obj) {
				return !$obj;
			},
			'first' => function($iter) {
				return $iter->idx === 0;
			},
			'last' => function($iter) {
				return $iter->idx === $iter->size - 1;
			},
			'call' => function($obj, $fn) {
				return call_user_func_array(array($obj, $fn), array_slice(func_get_args(), 2));
			},
			'set' => function($obj, $key) {
				self::$globals[$key] = $obj;
				return '';
			},
		];

		return self::$cachedPipes = array_merge($pipes, self::$pipes);
	}
}


class Iter
{
	public $idx;
	public $size;
	public $sign = '#';

	// Print the index if "#" or the count if "##".
	public function __toString() {
		return (string) $this->getIdx();
	}

	public function getIdx() {
		return $this->idx + (strlen($this->sign) - 1);
	}
}
?>
