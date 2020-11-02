<?php
/**
 * api:		php
 * title:	upgrade.php
 * description:	Emulates functions from new PHP versions on older interpreters.
 * version:	17
 * license:	Public Domain
 * url:		http://freshmeat.net/projects/upgradephp
 * type:	functions
 * category:	library
 * priority:	auto
 * load_if:     (PHP_VERSION<5.2)
 * sort:	-255
 * provides:	upgrade-php, api:php5, json
 *
 *
 * By loading this library you get PHP version independence. It provides
 * downwards compatibility to older PHP interpreters by emulating missing
 * functions or constants using IDENTICAL NAMES. So this doesn't slow down
 * script execution on setups where the native functions already exist. It
 * is meant as quick drop-in solution. It spares you from rewriting code or
 * using cumbersome workarounds instead of the more powerful v5 functions.
 *
 * It cannot mirror PHP5s extended OO-semantics and functionality into PHP4
 * however. A few features are added here that weren't part of PHP yet. And
 * some other function collections are separated out into the ext/ directory.
 * It doesn't produce many custom error messages (YAGNI), and instead leaves
 * reporting to invoked functions or for native PHP execution.
 *
 * And further this is PUBLIC DOMAIN (no copyright, no license, no warranty)
 * so therefore compatible to ALL open source licenses. You could rip this
 * paragraph out to republish this instead only under more restrictive terms
 * or your favorite license (GNU LGPL/GPL, BSDL, MPL/CDDL, Artistic/PHPL, ..)
 *
 * Any contribution is appreciated. <milky*users#sf#net>
 *
 */
use Piwik\SettingsServer;

/**
 *                                   ------------------------------ 5.2 ---
 * @group 5_2
 * @since 5.2
 *
 * Additions of PHP 5.2.0
 * - some listed here might have appeared earlier or in release candidates
 *
 * @emulated
 *    error_get_last
 *    preg_last_error
 *    lchown
 *    lchgrp
 *    E_RECOVERABLE_ERROR
 *    M_SQRTPI
 *    M_LNPI
 *    M_EULER
 *    M_SQRT3
 *
 * @missing
 *    sys_getloadavg
 *    inet_ntop
 *    inet_pton
 *    array_fill_keys
 *    array_intersect_key
 *    array_intersect_ukey
 *    array_diff_key
 *    array_diff_ukey
 *    array_product
 *    pdo_drivers
 *    ftp_ssl_connect
 *    XmlReader
 *    XmlWriter
 *    PDO*
 *
 * @unimplementable
 *    stream_*
 *
 */


/**
 * glob() replacement.
 * Behaves like glob($pattern, $flags)
 *
 * @author BigueNique AT yahoo DOT ca
 * @author anthon (dot) pang (at) gmail (dot) com
 *
 * @param string $pattern
 * @param int $flags GLOBL_ONLYDIR, GLOB_MARK, GLOB_NOSORT (other flags not supported; defaults to 0)
 * @return array
 */
if(function_exists('glob')) {
	// provide a wrapper
	function _glob($pattern, $flags = 0) {
		return glob($pattern, $flags);
	}
} else if(function_exists('opendir') && function_exists('readdir')) {
	// we can't redefine glob() if it has been disabled
	function _glob($pattern, $flags = 0) {
		$path = dirname($pattern);
		$filePattern = basename($pattern);
		if(is_dir($path) && ($handle = opendir($path)) !== false) {
			$matches = array();
			while(($file = readdir($handle)) !== false) {
				if(($file[0] != '.')
						&& fnmatch($filePattern, $file)
						&& (!($flags & GLOB_ONLYDIR) || is_dir("$path/$file"))) {
					$matches[] = "$path/$file" . ($flags & GLOB_MARK ? '/' : '');
				}
			}
			closedir($handle);
			if(!($flags & GLOB_NOSORT)) {
				sort($matches);
			}
			return $matches;
		}
		return false;
	}
} else {
	function _glob($pattern, $flags = 0) {
		return false;
	}
}

/**
 * Reads entire file into a string.
 * This function is not 100% compatible with the native function.
 *
 * @see http://php.net/file_get_contents
 * @since PHP 4.3.0
 *
 * @param string $filename Name of the file to read.
 * @return string The read data or false on failure.
 */
if (!function_exists('file_get_contents'))
{
	function file_get_contents($filename)
	{
		$fhandle = fopen($filename, "r");
		$fcontents = fread($fhandle, filesize($filename));
		fclose($fhandle);
		return $fcontents;
	}
}

/**
 * Safe serialize() and unserialize() replacements
 *
 * @license Public Domain
 *
 * @author anthon (dot) pang (at) gmail (dot) com
 */


/**
 * Safe serialize() replacement
 * - output a strict subset of PHP's native serialized representation
 * - does not serialize objects
 *
 * @param mixed $value
 * @return string
 * @throw Exception if $value is malformed or contains unsupported types (e.g., resources, objects)
 */
function _safe_serialize( $value )
{
	if(is_null($value))
	{
		return 'N;';
	}
	if(is_bool($value))
	{
		return 'b:'.(int)$value.';';
	}
	if(is_int($value))
	{
		return 'i:'.$value.';';
	}
	if(is_float($value))
	{
		return 'd:'.str_replace(',', '.', $value).';';
	}
	if(is_string($value))
	{
		return 's:'.strlen($value).':"'.$value.'";';
	}
	if(is_array($value))
	{
		$out = '';
		foreach($value as $k => $v)
		{
			$out .= _safe_serialize($k) . _safe_serialize($v);
		}

		return 'a:'.count($value).':{'.$out.'}';
	}

	// safe_serialize cannot serialize resources or objects
	return false;
}

/**
 * Wrapper for _safe_serialize() that handles exceptions and multibyte encoding issue
 *
 * @param mixed $value
 * @return string
 */
function safe_serialize( $value )
{
	// ensure we use the byte count for strings even when strlen() is overloaded by mb_strlen()
	if (function_exists('mb_internal_encoding') &&
		(((int) ini_get('mbstring.func_overload')) & 2))
	{
		$mbIntEnc = mb_internal_encoding();
		mb_internal_encoding('ASCII');
	}

	$out = _safe_serialize($value);

	if (isset($mbIntEnc))
	{
		mb_internal_encoding($mbIntEnc);
	}
	return $out;
}


/**
 * readfile() replacement.
 * Behaves similar to readfile($filename);
 *
 * @author anthon (dot) pang (at) gmail (dot) com
 *
 * @param string $filename
 * @param bool $useIncludePath
 * @param resource $context
 * @return int the number of bytes read from the file, or false if an error occurs
 */
function _readfile($filename, $byteStart, $byteEnd, $useIncludePath = false, $context = null)
{
	$count = @filesize($filename);

	// built-in function has a 2 MB limit when using mmap
	if (function_exists('readfile')
        && $count <= (2 * 1024 * 1024)
        && $byteStart == 0
        && $byteEnd == $count
    ) {
		return @readfile($filename, $useIncludePath, $context);
	}

	// when in doubt (or when readfile() function is disabled)
	$handle = @fopen($filename, SettingsServer::isWindows() ? "rb" : "r");
	if ($handle) {
        fseek($handle, $byteStart);

        for ($pos = $byteStart; $pos < $byteEnd && !feof($handle); $pos = ftell($handle)) {
			echo fread($handle, min(8192, $byteEnd - $pos));

			@ob_flush();
			@flush();
		}

		fclose($handle);
		return $byteEnd - $byteStart;
	}
	return false;
}


/**
 * Use strtolower if mb_strtolower doesn't exist (i.e., php not compiled with --enable-mbstring)
 * This is not a functional replacement for mb_strtolower.
 *
 * @param string $input
 * @param string $charset
 */
if(!function_exists('mb_strtolower')) {
    function mb_strtolower($input, $charset = '') {
        return strtolower($input);
    }
}

/**
 * Use strlen if mb_strlen doesn't exist (i.e., php not compiled with --enable-mbstring)
 * This is not a functional replacement for mb_strlen.
 *
 * @param string $input
 * @param string $charset
 */
if(!function_exists('mb_strlen')) {
    function mb_strlen($input, $charset = '') {
        return strlen($input);
    }
}

/**
 * On ubuntu in some cases, there is a bug that gzopen does not exist and one must use gzopen64 instead
 */
if (!function_exists('gzopen')
    && function_exists('gzopen64')) {
    function gzopen($filename , $mode = 'r', $use_include_path = 0 )
    {
        return gzopen64($filename , $mode, $use_include_path);
    }
}

if (!function_exists('dump')) {
    function dump () {

    }
}


if(!function_exists('fnmatch')) {

	function fnmatch($pattern, $string) {
		return preg_match("#^".strtr(preg_quote($pattern, '#'), array('\*' => '.*', '\?' => '.'))."$#i", $string);
	} // end

} // end if
