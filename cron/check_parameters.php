<?php
/*
  +----------------------------------------------------------------------+
  | PHP QA GCOV Website                                                  |
  +----------------------------------------------------------------------+
  | Copyright (c) 2005-2006 The PHP Group                                |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | http://www.php.net/license/3_01.txt                                  |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: Nuno Lopes <nlopess@php.net>                                 |
  +----------------------------------------------------------------------+
*/

/* $Id$ */

// check for zend_parse_parameters*() and related functions usage
// TODO: add support for zend_get_parameters*()


define('REPORT_LEVEL', 2); // 0 reports less false-positives. up to level 5.
define('VERSION', $phpver == 'PHP_HEAD' ? '6' : strtr(substr($phpver, 4), '_', '.'));

// be sure you have enough memory and stack for PHP. pcre will push the limits!
ini_set('pcre.backtrack_limit', 10000000);


// ------------------------ end of config ----------------------------


$API_params = array(
	'a' => array('zval**'), // array as zval*
	'b' => array('zend_bool*'), // boolean
	'C' => array('zend_class_entry**'), // class
	'd' => array('double*'), // double
	'f' => array('zend_fcall_info*', 'zend_fcall_info_cache*'), // function
	'h' => array('HashTable**'), // array as an HashTable*
	'l' => array('long*'), // long
	'o' => array('zval**'), //object
	'O' => array('zval**', 'zend_class_entry*'), // object of given type
	'r' => array('zval**'), // resource
	's' => array('char**', 'int*'), // string
	'z' => array('zval**'), // zval*
	'Z' => array('zval***') // zval**
);

// specific to PHP >= 6
if (version_compare(VERSION, '6', 'ge')) {
	$API_params['S'] = $API_params['s']; // binary string
	$API_params['t'] = array('zstr*', 'int*', 'zend_uchar*'); // text
	$API_params['T'] = $API_params['t'];
	$API_params['u'] = array('UChar**', 'int*'); // unicode
	$API_params['U'] = $API_params['u'];
}

$errors = array();

/** reports an error, according to its level */
function error($str, $level = 0)
{
	global $current_file, $current_function, $line, $errors, $phpdir;

	if ($level <= REPORT_LEVEL) {
		if (strpos($current_file, $phpdir) === 0) {
			$filename = substr($current_file, strlen($phpdir)+1);
		} else {
			$filename = $current_file;
		}
		$errors[$filename][] = array($line, $current_function, $str);
	}
}


/** this updates the global var $line (for error reporting) */
function update_lineno($offset)
{
	global $lines_offset, $line;

	$left  = 0;
	$right = $count = count($lines_offset)-1;

	// a nice binary search :)
	do {
		$mid = intval(($left + $right)/2);
		$val = $lines_offset[$mid];

		if ($val < $offset) {
			if (++$mid > $count || $lines_offset[$mid] > $offset) {
				$line = $mid;
				return;
			} else {
				$left = $mid;
			}
		} else if ($val > $offset) {
			if ($lines_offset[--$mid] < $offset) {
				$line = $mid+1;
				return;
			} else {
				$right = $mid;
			}
		} else {
			$line = $mid+1;
			return;
		}
	} while (true);
}


/** parses the sources and fetches its vars name, type and if they are initialized or not */
function get_vars($txt)
{
	$ret =  array();
	preg_match_all('/((?:(?:unsigned|struct)\s+)?\w+)(?:\s*(\*+)\s+|\s+(\**))(\w+(?:\[\s*\w*\s*\])?)\s*(?:(=)[^,;]+)?((?:\s*,\s*\**\s*\w+(?:\[\s*\w*\s*\])?\s*(?:=[^,;]+)?)*)\s*;/S', $txt, $m, PREG_SET_ORDER);

	foreach ($m as $x) {
		// the first parameter is special
		if (!in_array($x[1], array('else', 'endif', 'return'))) // hack to skip reserved words
			$ret[$x[4]] = array($x[1] . $x[2] . $x[3], $x[5]);

		// are there more vars?
		if ($x[6]) {
			preg_match_all('/(\**)\s*(\w+(?:\[\s*\w*\s*\])?)\s*(=?)/S', $x[6], $y, PREG_SET_ORDER);
			foreach ($y as $z) {
				$ret[$z[2]] = array($x[1] . $z[1], $z[3]);
			}
		}
	}

//	if ($GLOBALS['current_function'] == 'for_debugging') { print_r($m);print_r($ret); }
	return $ret;
}


/** run diagnostic checks against one var. */
function check_param($db, $idx, $exp, $optional)
{
	global $error_few_vars_given;

	if ($idx >= count($db)) {
		if (!$error_few_vars_given) {
			error("too few variables passed to function");
			$error_few_vars_given = true;
		}
		return;
	} elseif ($db[$idx][0] === '**dummy**') {
		return;
	}

	if ($db[$idx][1] != $exp) {
		error("{$db[$idx][0]}: expected '$exp' but got '{$db[$idx][1]}' [".($idx+1).']');
	}

	if ($optional && !$db[$idx][2]) {
		error("optional var not initialized: {$db[$idx][0]} [".($idx+1).']', 1);

	} elseif (!$optional && $db[$idx][2]) {
		error("not optional var is initialized: {$db[$idx][0]} [".($idx+1).']', 2);
	}
}


/** fetch params passed to zend_parse_params*() */
function get_params($vars, $str)
{
	$ret = array();
	preg_match_all('/(?:\([^)]+\))?(&?)([\w>.()-]+(?:\[\w+\])?)\s*,?((?:\)*\s*=)?)/S', $str, $m, PREG_SET_ORDER);

	foreach ($m as $x) {
		$name = $x[2];

		// little hack for last parameter
		if (strpos($name, '(') === false) {
			$name = rtrim($name, ')');
		}

		if (empty($vars[$name][0])) {
			error("variable not found: '$name'", 3);
			$ret[][] = '**dummy**';

		} else {
			$ret[] = array($name, $vars[$name][0] . ($x[1] ? '*' : ''), $vars[$name][1]);
		}

		// the end (yes, this is a little hack :P)
		if ($x[3]) {
			break;
		}
	}

//	if ($GLOBALS['current_function'] == 'for_debugging') { var_dump($m); var_dump($ret); }
	return $ret;
}


/** run tests on a function. the code is passed in $txt */
function check_function($name, $txt, $offset)
{
	global $API_params;

	if (preg_match_all('/zend_parse_parameters(?:_ex\s*\([^,]+,[^,]+|\s*\([^,]+),\s*"([^"]*)"\s*,\s*([^{;]*)/S', $txt, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {

		$GLOBALS['current_function'] = $name;

		foreach ($matches as $m) {
			$GLOBALS['error_few_vars_given'] = false;
			update_lineno($offset + $m[2][1]);

			$vars = get_vars(substr($txt, 0, $m[0][1])); // limit var search to current location
			$params = get_params($vars, $m[2][0]);
			$optional = $varargs = false;
			$last_last_char = $last_char = '';
			$j = -1;
			$len = strlen($m[1][0]);

			for ($i = 0; $i < $len; ++$i) {
				switch($char = $m[1][0][$i]) {
					// separator for optional parameters
					case '|':
						if ($optional) {
							error("more than one optional separator at char #$i");
						} else {
							$optional = true;
							if ($i == $len-1) {
								error("unnecessary optional separator");
							}
						}
					break;

					// separate_zval_if_not_ref
					case '/':
						if (!in_array($last_char, array('r', 'z'))) {
							error("the '/' specifier cannot be applied to '$last_char'");
						}
					break;

					// nullable arguments
					case '!':
						if (!in_array($last_char, array('a', 'C', 'f', 'h', 'o', 'O', 'r', 's', 't', 'z', 'Z'))) {
							error("the '!' specifier cannot be applied to '$last_char'");
						}
					break;

					case '&':
						if (version_compare(VERSION, '6', 'ge')) {
							if ($last_char == 's' || ($last_last_char == 's' && $last_char == '!')) {
								check_param($params, ++$j, 'UConverter*', $optional);

							} else {
								error("the '&' specifier cannot be applied to '$last_char'");
							}
						} else {
							error("unknown char ('&') at column $i");
						}
					break;

					case '+':
					case '*':
						if (version_compare(VERSION, '6', 'ge')) {
							if ($varargs) {
								error("A varargs specifier can only be used once. repeated char at column $i");
							} else {
								check_param($params, ++$j, 'zval****', $optional);
								check_param($params, ++$j, 'int*', $optional);
								$varargs = true;
							}
						} else {
							error("unknown char ('$char') at column $i");
						}
					break;

					default:
						if (isset($API_params[$char])) {
							foreach($API_params[$char] as $exp) {
								check_param($params, ++$j, $exp, $optional);
							}
						} else {
							error("unknown char ('$char') at column $i");
						}
				}

				$last_last_char = $last_char;
				$last_char = $char;
			}
		}
	}
}


/** the main recursion function. splits files in functions and calls the other functions */
function recurse($path)
{
	foreach (scandir($path) as $file) {
		if ($file == '.' || $file == '..' || $file == 'CVS') continue;

		$file = "$path/$file";
		if (is_dir($file)) {
			recurse($file);
			continue;
		}

		// parse only .c and .cpp files
		if (substr_compare($file, '.c', -2) && substr_compare($file, '.cpp', -4) || strpos($file, 'lcov_data')) continue;

		$txt = file_get_contents($file);
		// remove comments (but preserve the number of lines)
		$txt = preg_replace(array('@//.*@S', '@/\*.*\*/@SsUe'), array('', 'preg_replace("/[^\r\n]+/S", "", \'$0\')'), $txt);


		$split = preg_split('/PHP_(?:NAMED_)?(?:FUNCTION|METHOD)\s*\((\w+(?:,\s*\w+)?)\)/S', $txt, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE);

		if (count($split) < 2) continue; // no functions defined on this file
		array_shift($split); // the first part isn't relevant


		// generate the line offsets array
		$j = 0;
		$lines = preg_split("/(\r\n?|\n)/S", $txt, -1, PREG_SPLIT_DELIM_CAPTURE);
		$lines_offset = array();

		for ($i = 0; $i < count($lines); ++$i) {
			$j += strlen($lines[$i]) + strlen(@$lines[++$i]);
			$lines_offset[] = $j;
		}

		$GLOBALS['lines_offset'] = $lines_offset;
		$GLOBALS['current_file'] = $file;


		for ($i = 0; $i < count($split); $i+=2) {
			// if the /* }}} */ comment is found use it to reduce false positives
			// TODO: check the other indexes
			list($f) = preg_split('@/\*\s*}}}\s*\*/@S', $split[$i+1][0]);
			check_function(preg_replace('/\s*,\s*/S', '::', $split[$i][0]), $f, $split[$i][1]);
		}
	}
}


// this script produces the same results on all machines, so don't run it on non-master servers
if($is_master)
{
	recurse($phpdir);

	if ($errors) {
		$total  = 0;
		$output = <<< HTML
<table border="1">
 <tr>
  <td>File</td>
  <td>Number of detected problems</td>
 </tr>
HTML;

		// $errors[$filename][] = array($line, $function, $str);
		foreach ($errors as $filename => $err) {
			$dir   = dirname($filename);
			$hash  = 'c' . md5($filename);
			$count = count($err);

			$total += $count;

			if(substr($filename, 0, 5) == 'Zend/') {
				$lxrpath = str_replace('Zend/','ZendEngine2/', $filename);
				$lxrpath = "http://lxr.php.net/source{$lxrpath}#";
			} else {
				$lxrpath = "http://lxr.php.net/source/php-src/{$filename}#";
			}


			$output .= <<< HTML
 <tr>
  <td><a href="viewer.php?version=$phpver&func=params&file=$hash">$filename</a></td>
  <td>$count</td>
 </tr>
HTML;

			$file = '<?php $filename="'.basename($filename).'"; ?>'.<<< HTML

<table border="1">
 <tr>
  <td>Function</td>
  <td>Line</td>
  <td>Message</td>
 </tr>
HTML;

			foreach ($err as $error) {

				$file .= <<< HTML
 <tr>
  <td>$error[1]</td>
  <td><a href="{$lxrpath}{$error[0]}">$error[0]</a></td>
  <td>$error[2]</td>
 </tr>
HTML;
			}
			$file .= '</table>'.html_footer();
			file_put_contents("$outdir/$hash.inc", $file);
		}

		$output = "<p>Total: $total</p>\n$output</table>\n";

	} else {
		$output = "<p>Congratulations! Currently no problems were found!</p>\n";
	}

	file_put_contents("$outdir/params.inc", $output.html_footer());
}