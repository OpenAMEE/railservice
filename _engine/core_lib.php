<?php

/*

text_blob_enc
text_blob_dec - these are in pline_lib. and start with pnline_ not text_

qlog()
clb_timing($ident=FALSE)
clb_upload_dir($path='', $access=0755, $date=FALSE)
clb_unique($len, $dirpath=FALSE, $pre='', $suf='')
clb_dir($path)
clb_unserial($text, $dflt=FALSE)
clb_serial($var, $text=FALSE)
clb_escape($txt,$quote=TRUE)
clb_parse_query($prms)
clb_get_prm($dflt, $array, $key)
clb_val($default, $struct, $key1)
clb_count($arr)
clb_b64e($input, $dec=1E5)
clb_b64d($str, $dec=1E5) {
clb_entities_enc($text)
clb_tag($tag, $text='', $nodes='', $attr=FALSE, $attr_str='')
clb_redirect($path)
clb_make_url()
clb_json($struct, $quot='"')
	
log_err_msg|qpre|qtxt|cheap_login|qbuffer|timing_points|NOW_UTC|get_http_headers|checksum32|is_all_caps|strconcat|txt_to_num|milliseconds|safe_dir|make_file_exist|test_make_dir|make_path_good|asset_subdir|unique_file|file_extension|absolute_path|relative_path|textfromfile|texttofile|non_null|clean_serial|safe_serial|text_blob_enc|text_blob_dec|prefixsuffix|col_from_arr|value_pairs|parse_url_query|json_response|escape_str|json|struct2html|select_html|is_utf8|cp1252_to_utf8|code2utf|html_ent_utf8_dec|html_ent_utf8_enc|url_query_var|strsub|in_str|html_frag|redirect_exit|safe_count|safe_prm|shallow_tree|pseudo_var|contains

safe_tag|safe_val|get_raw_value|self_url|qlog

*/

/*
	clb_ - core library

	useful information
	
	mysql_set_charset('utf8',$dbc);
	
	error_log: 0 = console, 1=email, 3=file
	
	empty() - ONLY WORKS ON VAR, values considered empty: (string) "", (int) 0, (string) "0", array(), FALSE, NULL, $unassigned_var

	ini_set('memory_limit', '256M');

	http_build_query(arr,[prefix, delim]) -> query string for url, prefix for array elements with numberical keys so "&prefix0=val" not "&0=val"
	for single variable pairs use url_query_var() in this libriary to make a single "&var=val" pair when building up urls bit by bit
	
	parse_str() in php but use parse_url_query() for more flexibility see notes on method
	
	strip_tags($html [,$allowable_tags='<p><a>']) - might not handle poorly formed html well
	also clb_plaintext($html)->$text in mail_lib.php
	
	number_format($number, $decimals, $dec_point='.',$thousands_sep=',');
	
	//htmlentities replaced by html_ent_utf8_enc()
	htmlentities(str, quotes, charset, double) ENT_COMPAT - " only (default), ENT_QUOTES - ' and ", ENT_NOQUOTES, charset ISO-8859-1 default, use UTF-8, if double=FALSE will not reencode entities
	htmlentities(str, ENT_COMPAT, 'UTF-8')
	html_entity_decode (str, [quotes, charset])
	
	rawurlencode(string) - turns non alpanumerics and dot and underscore into %XX, rawurldecode(), urlencode() uses "+" for spaces

	strpos(hay, needle) - hay must be non-null, not found is FALSE
	str_replace(needle, replace, hay);
	str_repeat(str, count);
	$str[0] -> char, also $str{0} maybe	
	substr(base, start, [len]) -start means from end (-1 is last char), -len omit len from end (-2 skip last two chars)
	
	explode(delim, str) - creates null elements for leading and trailing delim and one null elem for empty strings
	join(delim,arr)= implode()
	
	preg_match(grep, str, [parts]) - initialises array but with no elements if no match eg array()
	preg_match_all(grep, str, [parts]) - initialises array for each submatch but with no elements if no match eg array(0=array(), 1=array())
	PREG_PATTERN_ORDER - default [0]=array('first total match', 'second total match',...), [1]=array('first subpat1', 'second subpat1',...)
	PREG_SET_ORDER - [0]=array('first full match','first subpat1','first subpat2'), [1]=array('second full match','second subpat1','second subpat2')
	PREG_OFFSET_CAPTURE - each node becomes arr(0=string, 1=offset)
	preg_split(grep, string, [limit] , [flags] ) - dont forget the limit of -1 if using flags
	PREG_SPLIT_NO_EMPTY
	PREG_OFFSET_CAPTRUE - as above
	PREG_SPLIT_DELIM_CAPTURE - creates elements for the delimiters in the result array but only fill sit with the part of the delimiter given as a subpattern
	
	(?:pat) grouping only
	(?=pat) positive lookahead
	(?!pat) negative lookahead
	(?<=pat) positive look behind
	(?<!pat) negative look behind	eg /'(.*?(?<!\\\\))'/ finds text between single quotes including escaped quotes, the grep needs '\\' but string parsing changes '\\' to '\' so do '\\\\'
	(? >pat) cut to stop (a+|b+)* backtracking (? >a+|b+)* //no space between ? and > but cannot in comment as it confuses function parsing of BBEdit
	
	array_search(needle, hay) not found (php 4.3 ? FALSE : NULL), returns key not position
	in_array(needle, hay) - bool result
	array_key_exists(key, arr) = isset(arr[key])
	array_merge(a1,a2,...) - keeps instances from later arrays on conflict, renumbers int keys, but preserves assoc keys
	array_keys(arr, val, strict);	//if seaching for values always use strict as number 0 as a val will match any string element!
	ord('A') -> 65, chr(65) ->'A'
	set_time_limit(30);
	
	dirname(): ''=>'', '.'=>'.', '/'=>'/', 'file'=>'.', '/dir'=>'/', '/dir/'=>'/'
	rtim(dirname($path),'./') is safer and what one expects
	
	$_SERVER['PHP_SELF'] 			= /~tobylewis/bristolstreets/admin/test.php
	$_SERVER['REQUEST_URI'] 		= /~tobylewis/bristolstreets/admin/test.php?m=what
	$_SERVER['QUERY_STRING'] 		= m=what

	$_SERVER['SCRIPT_FILENAME'] 	= /Users/tobylewis/Sites/bristolstreets/admin/test.php
	__FILE__ 						= /Users/tobylewis/Sites/bristolstreets/admin/test.php (assuming called in the original script)
	$_SERVER['DOCUMENT_ROOT'] 		= /Library/WebServer/Documents


	$_SERVER['SCRIPT_FILENAME']		= /kunden/homepages/13/d173572893/htdocs/bristolstreets/test.php
	__FILE__ 						= /homepages/13/d173572893/htdocs/bristolstreets/test.php

	$_SERVER['DOCUMENT_ROOT']		= /kunden/homepages/13/d173572893/htdocs/bristolstreets

	//if script on different volume to server SCRIPT_FILENAME may have additional directory levels
	$test = realpath(dirname($_SERVER['SCRIPT_FILENAME']));


	Files for including are first looked for in each include_path entry relative to the current working directory, and then in the directory of current script. E.g. 
	include_path = "libraries"
	/www/myscript.php : include(includes/a.php);
	/www/includes/a.php : include(b.php);
	b.php searched for in /www/libraries/ and then in /www/include/
	
	If filename begins with ./ or ../, it is looked only in the current working directory.
	
	$text = file_get_contents($filename  [, $flags= FILE_TEXT|FILE_BINARY  [, $context=NULL  [, int $offset= -1  [, int $maxlen= -1  ]]]] )
	$len = file_put_contents($filename , $data  [, int $flags=FILE_TEXT|FILE_BINARY  [, resource $context  ]] ) returns FALSE on failure
*/

if (!defined('IS_LOCAL')) DEFINE('IS_LOCAL',preg_match('!^/Users/!i', __FILE__));	//files in user folders indicates local test system
if (!defined('IS_CLI')) DEFINE('IS_CLI',isset($argv));


if (!function_exists('qlog')) : function qlog()
{
	if (!defined('IS_LOCAL') || !IS_LOCAL) return;

	$txt = '';
	$sep = '';
	$count = func_num_args();
	for ($i=0; $i<$count; $i++)
	{
		$val = func_get_arg($i);
		if (is_array($val) || is_object($val)) {
			$val = var_export($val,TRUE);
		} else if ($val === FAlSE) {
			$val = 'FALSE';
		} 
		$txt .= $sep.$val;
		$sep = ' +|+ ';
	}
	
	while ($txt)	//logs limited to 520 characters so make several
	{
		$val = substr($txt,0,500);
		$txt = substr($txt,500);
		error_log($val,0);
	}
}
endif;

if (!function_exists('qpre')) : function qpre() {
	$txt = '';
	$sep = '';
	$count = func_num_args();
	for ($i=0; $i<$count; $i++)
	{
		$val = func_get_arg($i);
		if (is_array($val) || is_object($val)) {
			$val = var_export($val,TRUE);
		} else if ($val === FAlSE) {
			$val = 'FALSE';
		} 
		$txt .= $sep.$val;
		$sep = ' +|+ ';
	}
	if (defined('IS_CLI') && IS_CLI) {
		echo $txt."\n";
	} else {
		echo '<div style="border: 1px solid red; padding: 0.5em; margin: 0.5em;"><pre>'."\n".htmlentities($txt)."\n</pre></div>\n";
	}
}
endif;



if (!function_exists('clb_timing')) : function clb_timing($ident=FALSE)
{
	static $point, $log;
	$now = 1000 * microtime(true);
	if (!isset($point) || !$ident)
	{
		$log = '';
	}
	else
	{
		$log .= round($now-$point, 3).' ('.$ident.'); ';
	}
	$point = $now;
	return $log;
}
endif;


if (!function_exists('clb_now_utc')) : function clb_now_utc($stamp=FALSE) 
{
	if (!$stamp) $stamp = time();
	return gmdate('Y-m-d H:i:s', $stamp);
}
endif;


//this converts a date or date time into a unix timestamp
if (!function_exists('clb_get_stamp')) : function clb_get_stamp($str, $default=0)
{
	$str = trim($str);
	if (preg_match('/^(\d+)\/(\d+)\/(\d+)\s*((\d+):(\d+))?/', $str, $p))
	{
		//31/3/2008	//day/month/year with '/' separators
		$t[0] = $p[3];	//year
		$t[1] = $p[2];	//month
		$t[2] = $p[1];	//day
		if (isset($p[5]) && isset($p[6])) {
			$t[3] = $p[5];	//hour
			$t[4] = $p[6];	//min
		}
	}
	else if (preg_match('/^(\d+)\D+(\d+)\D+(\d+)(?:\D+(\d+)\D+(\d+)\D+(\d+)(?:\.\d+)?\s*([-+\d:]+)?)?\s*$/',$str,$p))
	{
		//2006-10-13
		//2006-10-13T14:12:22.213-01:00
		$t = array_slice($p,1);
	} 
	else if (preg_match('/^\s*(\w+\W+)(\d+)\W+(\w+)\W+(\d+)\W+((\d+):(\d+):(\d+)(.\d+)?)?(\s+\w{3}|[-+]\d+:?\d+)\s*$/', $str, $p))
	{
		//Fri, 13 Oct 2006 15:12:22 +0000
		$t[0] = $p[4];	//year
		$t[1] = 1+array_search(substr(strtolower($p[3]),0,3),array('jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec',));	//month
		$t[2] = $p[2];	//day
		if (isset($p[6]) && $p[6])
		{
			$t[3] = $p[6];	//hour
			$t[4] = $p[7];	//min
			$t[5] = $p[8];	//sec, $p[9] = microseconds
			$t[6] = $p[10];	
		}
	}
	else if (preg_match('/^\s*(\d{4})(\d{2})(\d{2})\D*(\d{2})(\d{2})(\d{2})\s*([-+]\d+:?\d+)?\s/', $str, $p))
	{
		//20070811 123459 -0500, where separating chars between date/time and time/zone can be any non digit or omitted.  zone may also be omited
		$t = array_slice($p,1);
	}
	
	if (!isset($t)) return $default;
	if (count($t) >= 6)
	{
		$time = gmmktime( $t[3], $t[4], $t[5], $t[1], $t[2], $t[0]); //h,m,s m,d,y
		if (count($t)>6)
		{
			$offset = str_replace(':','',$t[6][0]);
			$time -= (floor($offset/100)*3600)+(($offset % 100)*60);
		}
		return $time;
	}
	else if (count($t) >= 3)
	{
		return gmmktime(0, 0, 0, $t[1], $t[2], $t[0]); //h,m,s m,d,y
	}
	return $default;
}
endif;


if (!function_exists('clb_upload_dir')) : function clb_upload_dir($path='', $access=0755, $date=FALSE)
{
	if (!$path || !is_dir($path) || !is_writable($path)) return FALSE;
	 
	if (!$date) $date = time();

	$path = rtrim($path,'/').date('/Y/',$date);
	if(!file_exists($path)) mkdir($path, $access);
	$path = rtrim($path,'/').date('/m/',$date);
	if(!file_exists($path)) mkdir($path, $access);
	
	if(file_exists($path)) return $path;
	return FALSE;
}
endif;


/*
	make a uniue file name for a given directory, or just a random name if no dirpath
	prefix and suffix are just appended so if the suffix is an extension caller must include '.'
	the length is the amount of random name to use and does not include pre and suf
	
	$path = tempnam(sys_get_temp_dir(), 'pic');	//quick way to get path for temp files
*/
if (!function_exists('clb_unique')) : function clb_unique($len, $dirpath=FALSE, $pre='', $suf='')
{
	if ($dirpath) $dirpath = rtrim($dirpath,'/').'/';
	do {
		$name = '';
		$a = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';	//no capital i or capital O, lowercase L
		$n = '';
		while($len--)
		{
			$name .= substr($a.$n,mt_rand(0,strlen($a.$n)-1),1);
			$n = '23456789';	//no zero or one
		}
		if (!$dirpath) return $pre.$name.$suf;
		
		$filename = $dirpath.$pre.$name.$suf;
	} while (file_exists($filename));
	return $filename;
}
endif;

/*
	makes directory paths from parts and ensures trailing '/' but does not change leading '/'
	pass any number of arguments, all will be assumed to be directories not files
	
*/
if (!function_exists('clb_dir')) : function clb_dir($path)
{
	$count = func_num_args();
	if (($count == 1) && empty($path)) return '';
	for ($i=1; $i<$count; $i++) 
		$path = rtrim($path,'/').'/'.ltrim(func_get_arg($i),'/');
	return rtrim($path,'/').'/';
}
endif;



if (!function_exists('clb_unserial')) : function clb_unserial($text, $dflt=FALSE)
{
	if (empty($text)) return $dflt;
	if (!preg_match('/^\w:-?[.\d].*[;}]$/s',$text)) return $dflt;	//very crude test for valid serialised value
	
	//editing records with myPHPadmin converts \n to \r\n which wrecks the length counts in serialised string.
	if (is_int(strpos($text, "\r\n"))) $text = clb_serial(FALSE, $text);
	return unserialize($text);
}
endif;


if (!function_exists('clb_serial')) : function clb_serial($var, $text=FALSE)
{
	//$var is usually an array not just string so need to serialise first and then manipulate
	//clb_unserial calls this to check already serialised string, so dont convert twice
	if (!$text) $text = serialize($var);
	
	//ensure no returns in the block but can only do so after serialising which means we need to adjust lengths if we remove chars
	if (is_int(strpos($text, "\r\n")))
	{
		$text = str_replace("\r\n", "\n", $text);	
		if (preg_match_all('/\bs:(\d+):"([^"\n]*\n[^"]*)";(?=}|\w:)/s', $text, $matches, PREG_SET_ORDER+PREG_OFFSET_CAPTURE)) foreach(array_reverse($matches) AS $set)
		{
			//PREG_OFFSET_CAPTURE - each node becomes arr(0=string, 1=offset)
			//offset to string + stated length should be followed by the end quote and semi-colon
			//if not and ourcalculation of the string length is followed by ";
			$len = strlen($set[2][0]);
			if (($len != $set[1][0]) && (substr($text, $set[2][1]+$set[1][0], 2) != '";') && (substr($text, $set[2][1]+strlen($set[2][0]), 2) == '";'))
			{
				$text = substr_replace($text, $len, $set[1][1], strlen($set[1][0]));	//replaceold length with new $set[1][0]=length, $set[1][1]=offset
			}
			if ($set[1] != $len) $text = str_replace('s:'.$set[1].':"'.$set[2].'";', 's:'.$len.':"'.$set[2].'";', $text);
		}
	}
	return $text;
}
endif;




DEFINE('BLOB2TEXT_VERSION','B2Tv1.0:');
DEFINE('BLOB2TEXT_BINARY','BINARY:');

/*
	B2Tv1.0 is the string that allows us to identify our blob to text data and the v1.0 alows us to support future mods
	serialise, compress, encode data for efficient storage of blob type data in text fields.
*/
if (!function_exists('clb_blob_enc')) : function clb_blob_enc($var, $binary=FALSE)
{
	if (is_string($var) && (substr($var,0,strlen(BLOB2TEXT_VERSION)) == BLOB2TEXT_VERSION)) return $var;	//dont double wrap if already an encoded/shrunk/serialsied blob
	$text = clb_serial($var);	//always serializing so we always get the same thing back even if null false or empty, this preserves type too.
	$dense = gzcompress($text, 6);	//compressionlevel 0=none, 10=max, 6 is the default and give best ratio 7-10 give little better
	if ($binary) return BLOB2TEXT_VERSION.BLOB2TEXT_BINARY.$dense;
	return BLOB2TEXT_VERSION.base64_encode($dense);
}
endif;

//decode, decompress, unserialize data for efficient storage of blob type data in text fields.
if (!function_exists('clb_blob_dec')) : function clb_blob_dec($text, $dflt=FALSE)
{
	if (substr($text,0,strlen(BLOB2TEXT_VERSION)) == BLOB2TEXT_VERSION)
	{
		$text = substr($text,strlen(BLOB2TEXT_VERSION));
		if (substr($text,0,strlen(BLOB2TEXT_BINARY)) == BLOB2TEXT_BINARY)
		{
			$dense = substr($text,strlen(BLOB2TEXT_BINARY));
		}
		else
		{
			$dense = base64_decode($text);
		}
		if (!$dense) return $dflt;
		$text = gzuncompress($dense);
		if ($text === FALSE) return $dflt;
	}
	return clb_unserial($text, $dflt);
}
endif;


if (!function_exists('clb_escape')) : function clb_escape($txt,$quote=TRUE)
{
	if ($quote === TRUE) $quote = '"';	//quote normally litteral but TRUE will be taken to mean use double quotes
	if ($quote === FALSE) $quote = '';
	return $quote.str_replace(array("\\","'","\"","\n","\r","\t","\x0B","\0"), array('\\\\','\\\'','\\"','\\n','\\r','\\t','\\x0B','\\0'), $txt).$quote;
}
endif;

if (!function_exists('clb_unescape')) : function clb_unescape($txt)
{
	return stripcslashes($txt);	//str_replace(array('\\\\','\\\'','\\"','\\n','\\r','\\t','\\x0B','\\0','\\'), array("\\","'","\"","\n","\r","\t","\x0B","\0",''), $txt);
}
endif;

/*
	take an array and return a value string.  by default "val1","val2", pass different quote or sep values
	clb_join($arr, '', '&', '=') gives a url query string, use clb_json() for object style {"key1":"val1", "key2":"val2"}
	clb_join($arr, TRUE) -> ("val1","val2") //for use with queries
*/
if (!function_exists('clb_join')) : function clb_join($arr, $quot='"', $sep=',', $def=FALSE)
{
	$txt = '';
	if ($paren = ($quot === TRUE)) $quot = '"'; 
	foreach($arr AS $key=>$val) if (!$def)
	{
		$txt .= clb_escape($val, $quot).$sep;
	}
	else if ($sep === '&')
	{
		$txt .= rawurlencode($key).$def.rawurlencode($val).$sep;
	}
	else
	{
		$txt .=  $key.$def.clb_escape($val, $quot).$sep;
	}
	if ($paren) return ' ('.rtrim($txt,$sep).') ';
	return rtrim($txt,$sep);
}
endif;



/*
	given a query string split into an array of variables.  
	if more than one instance result is returned as an array
	http_build_query(arr,[prefix, delim]) -> query string for url, prefix for array elements with numberical keys so "&prefix0=val" not "&0=val"
*/
if (!function_exists('clb_parse_query')) : function clb_parse_query($prms)
{
	$arr = array();
	if (preg_match_all('/([^&?=]+)=([^&?=]*)/', $prms, $m, PREG_SET_ORDER)) foreach($m AS $set)
	{
		$var = $set[1];
		$val = rawurldecode($set[2]);

		if (!isset($arr[$var]))
		{
			$arr[$var] = $val;
		}
		else 
		{
			if (!is_array($arr[$var])) $arr[$var] = array($arr[$var]);
			$arr[$var][] = $val;
		}
	}
	return $arr;
}
endif;



/*
	intelligently get rid of any automatic escaping, 
	call on the $_GET, $_POST, $_REQUEST, $_COOKIE, $_SERVER globals
*/
if (!function_exists('clb_get_prm')) : function clb_get_prm($dflt, $array, $key)
{
	$val = clb_val($dflt, $array, $key);
	if (get_magic_quotes_gpc() || ini_get('magic_quotes_sybase') || function_exists('add_magic_quotes'))
	{
		$val = stripslashes($val);
	}
	return $val;
}
endif;

/*
	ingnore non numerical characters and get first number from string.
*/
if (!function_exists('clb_get_num')) : function clb_get_num($txt)
{
	if (is_numeric($txt)) return 1*$txt;
	if (preg_match('/-?\d+(\.\d+)?/', $txt, $m)) return 1*$m[0];
	return 0;
}
endif;



/*
	allow access to deeper structures like clb_safe_val
	as this function is called a lot, it has been optimised by not calling
	any php functions other than isset() when handling the first two key values
	after that the generalised case kicks in
*/
if (!function_exists('clb_val')) : function clb_val($default, $struct, $key1=NULL, $key2=NULL, $key3=NULL)
{
	if (($key1 === TRUE) || ($key1 === FALSE) || ($key1 === '') || !isset($struct[$key1])) return $default;
	if ($key2 === NULL) return $struct[$key1];
	$struct = $struct[$key1];
	
	if (($key2 === TRUE) || ($key2 === FALSE) || ($key2 === '') || !isset($struct[$key2])) return $default;
	if ($key3 === NULL) return $struct[$key2];
	$struct = $struct[$key2];
	
	$count = func_num_args();
	for ($i=4; $i<$count; $i++)
	{
		$prm = func_get_arg($i);
		if (($prm === TRUE) || ($prm === FALSE) || ($prm === '') || !isset($struct[$prm])) return $default;
		$struct = $struct[$prm];
	}
	return $struct;
}
endif;

/*
	rekeys an array of arrays with values from the second level array
	used to put key field as the index on selection arrays returned from db queries
	$fld - fld to provide value, will use first field by default
	$rebuild - if false returns new array with index to original in values (useful when more than one index needed)
*/
if (!function_exists('clb_rekey')) : function clb_rekey($arr, $fld=FALSE, $rebuild=TRUE)
{
	$new_array = array();
	if (is_array($arr)) foreach($arr as $i=>$row)
	{
		$key = (($fld && isset($row[$fld])) ? $row[$fld] : reset($row));
		$new_array[$key] = ($rebuild ? $row : $i);
	}
	return $new_array;
}
endif;

//extract a column from a database selection array, use the same key as on the selection array
if (!function_exists('clb_column')) : function clb_column($arr, $fld=FALSE)
{
	$new_array = array();
	if (is_array($arr)) foreach($arr as $i=>$row)
	{
		$new_array[$i] = (($fld && isset($row[$fld])) ? $row[$fld] : reset($row));
	}
	return $new_array;
}
endif;



if (!function_exists('clb_count')) : function clb_count($arr, $index=FALSE)
{
	if (is_array($arr) && !is_bool($index)) $arr = (isset($arr[$index]) ? $arr[$index] : FALSE);
	return (is_array($arr) ? count($arr) : 0);
}
endif;




/*
	encode single number to base64, decimals multiplied by a set factor
	$dec = multiply by 1E5 = 100000 to keep 5 decimal places, decode does the reverse
*/
if (!function_exists('clb_b64e')) : function clb_b64e($input, $dec=1E5)
{
	$val = $input;
	$neg = ($input < 0);
	if ($dec) {	//convert 5 decimal places for long/lat values
		$val = ($val * $dec);	//convert real to integer with 5 decimal places
	//	$val = floor(round($val,1));	//round before floor to correct precission error in floor
		$val = (int) (round($val,1));	//round before floor to correct precission error in floor
	
		$val <<= 1;	//shift left
		//php was keeping high bit set but we never want it set so mask it off.
		if ($neg) $val = ($val ^ 0xFFFFFFFF) & 0x7FFFFFFF;	//invert bits if negative
	}
	$txt = '';
	if ($val<0) {
		qlog('negative value mid calculation in val2base64, something has gone wrong:', $input, $val);
		return '?';
	}
	do {
		$digit = $val & 0x0000001F;	//mask of all but lowest 5 bits
		$val >>= 5;
		if ($val) $digit |= 0x00000020;	//if there are more bits to come mask on 32
		$txt .= chr(63+$digit);
	} while ($val);
	return $txt;
}
endif;


if (!function_exists('clb_b64d')) : function clb_b64d($str, $dec=1E5) {
	$results = array();
	list($val, $mult) = array(0, 1);	//reset working values
	for($i=0;$i<strlen($str);$i++) {
		$c = ord($str{$i})-63;	//get the digit value
		$val += ($mult * ($c & 0x0000001F));	//multiply/shift current total and add new digit
		$mult <<=5;	//each char is 5 bits
		if (($c & 0x00000020) == 0)	//no more values
		{	
			if ($val & 0x00000001) $val = -$val;	// ^ 0xFFFFFFFF;	//invert bits if negative
			$val >>= 1;				//shift right to remove the sign bit
			if ($dec) $val /= $dec;
			$results[] = $val;		//save in result
			list($val, $mult) = array(0, 1);	//reset working values
		}
	}
	return $results;
}
endif;


//replacement for htmlentities() but extremely slow
if (!function_exists('clb_entities_enc')) : function clb_entities_enc($text)
{		
	//literal entities translation
	static $utf8_to_html_tt;
	if (!isset($utf8_to_html_tt))  {
		$utf8_to_html_tt = array();
		foreach (get_html_translation_table(HTML_ENTITIES) as $val=>$key) $utf8_to_html_tt[utf8_encode($val)] = $key;
	}
	
	// replace literal entities
	return strtr($text, $utf8_to_html_tt);
}
endif;


if (!function_exists('clb_checksum32')) : function clb_checksum32($val)
{
	//give a 32bit checksum for a block of text
	
	$len = strlen($val);
	$sum1 = ($len & 0xFFFF);
	$sum2 = ($len >> 16);
	
	switch ($len%8) {  //use summation order independance to add last bytes first
		case (0): break; // length evenly divisible by $bytes
		case (1):
			$sum1 = $sum1+(ord($val[$len-1]) << 8);
			break;
		case (2):
			$sum1 = $sum1+(ord($val[$len-2]) << 8)+ord($val[$len-1]);
			break;
		case (3):
			$sum1 = $sum1+(ord($val[$len-3]) << 8)+ord($val[$len-2]);
			$sum2 = $sum2+(ord($val[$len-1]) << 8);
			break;
		case (4):
			$sum1 = $sum1+(ord($val[$len-4]) << 8)+ord($val[$len-3]);
			$sum2 = $sum2+(ord($val[$len-2]) << 8)+ord($val[$len-1]);
			break;
		case (5):
			$sum1 = $sum1+(ord($val[$len-5]) << 8)+ord($val[$len-4])+(ord($val[$len-1]) << 8);
			$sum2 = $sum2+(ord($val[$len-3]) << 8)+ord($val[$len-2]);
			break;
		case (6):
			$sum1 = $sum1+(ord($val[$len-6]) << 8)+ord($val[$len-5])+(ord($val[$len-2]) << 8)+ord($val[$len-1]);
			$sum2 = $sum2+(ord($val[$len-4]) << 8)+ord($val[$len-3]);
			break;
		case (7):
			$sum1 = $sum1+(ord($val[$len-7]) << 8)+ord($val[$len-6])+(ord($val[$len-3]) << 8)+ord($val[$len-2]);
			$sum2 = $sum2+(ord($val[$len-5]) << 8)+ord($val[$len-4])+(ord($val[$len-1]) << 8);
			break;
	} 
	$len -= ($len%8);
	
	for ($block=0; $block<$len; $block+=32000) {	//workin in chunks of 32k to avoid overflow in $sum1 & $sum2
		$chunk = min($block + 32000, $len);
		for ($i = $block; $i < $chunk; $i+=8) {
			$sum1 = $sum1 + (ord($val[$i]) << 8) + ord($val[$i+1]) + (ord($val[$i+4]) << 8) + ord($val[$i+5]);
			$sum2 = $sum2 + (ord($val[$i+2]) << 8) + ord($val[$i+3]) + (ord($val[$i+6]) << 8) + ord($val[$i+7]);
		}
		$over1 = ($sum1 >> 16);
		$over2 = ($sum2 >> 16);
		$sum1 = ($sum1 & 0xFFFF);
		$sum2 = ($sum2 & 0xFFFF);
		If ($over1 != 0) $sum2 = $sum2 + $over1;
		If ($over2 != 0) $sum1 = $sum1 + $over2;
	}
	
	return (($sum1 << 16)+$sum2);
}
endif;


/*
	pass '/' at the end of the tag name for empty tags like 'img/'
	pass '*' at the end of the tag name to simply create the opening tag (without the close tag)
	pass '?' at the end of the tag name to create xml header type tag
	
	htmlspecialchars() will return a null string if it is given an encoding value and hits an invalid char, hence the removal of the utf-8 params
*/
if (!function_exists('clb_tag')) : function clb_tag($tag, $text='', $nodes='', $attr=FALSE, $attr_str='')
{
	$close = '';
	$empty = '';
	if (preg_match('!^(\w+)([/*?])?!', $tag, $m)) {
		$tag = $m[1];
		if (!isset($m[2]) || empty($m[2])) {
			$close = '</'.$m[1].'>';
		} else if ($m[2]=='?') {
			$tag = '?'.$tag;
			$empty = '?';
		} else if ($m[2]=='/') {
			$empty = ' /';
		}
	}

	if (is_array($attr)) foreach($attr AS $attr_name => $attr_val) $attr_str .= ' '.$attr_name.'="'.htmlspecialchars($attr_val, ENT_COMPAT).'"';	//, 'UTF-8').'"';
	if (is_int(strpos($text, "\r\n"))) $text = str_replace("\r\n", "\n", $text);	
	if (!in_array($tag, array('pre', 'textarea')))
	{
		//convert entities, replace line breaks with br but if making a paragraph tag, take multiple breaks as a paragraph
		$text = htmlspecialchars($text, ENT_COMPAT);	//, 'UTF-8');
		if ($tag == 'p') $text = preg_replace('/\n{2,}/', '</p><p>', $text);
		$text = nl2br($text);
	}
	$text = preg_replace('/&amp;(#?\w{1,8};)/i','&$1', $text);	//revert any entities that got double entitied
	if ($empty && ($text || $nodes))
	{
		$attr_str .= ' '.$text.$nodes;
		$text = $nodes = '';
	}
	$html = '<'.$tag.$attr_str.$empty.'>'.$text.$nodes.$close;
	
	return $html;
}
endif;

if (!function_exists('clb_img')) : function clb_img($src, $path='', $attr=FALSE, $width=FALSE, $height=FALSE)
{
	if (!is_array($attr)) $attr = array();
	if (!isset($attr['alt'])) $attr['alt'] = '';
	$attr['src'] = $src;
	if (file_exists($path) && ($info = getImageSize($path)))
	{
		list($w, $h) = $info;
		$r = 1;
		if ($w && $width && ($w != $width)) $r = $width/$w;
		if ($h && $height && ($h != $height)) $r = $height/$h;
		if (is_numeric($width) && ($height === TRUE)) $r = $width / max($w, $h);
		$attr['width'] = round($w * $r);
		$attr['height'] = round($h * $r);
	}
	return clb_tag('img/', '', '', $attr);
}
endif;

/*
	pass in path on current host or full url to redirect to. 
	pass additional path parts to have them intelligently appended to the path
*/
if (!function_exists('clb_redirect')) : function clb_redirect($path)
{
	@ob_end_clean(); //should be buffering but @ stops warning if not
	for ($i=1; $i<func_num_args(); $i++) $path = rtrim($path,'/').'/'.ltrim(func_get_arg($i),'/');
	if (!preg_match('%^http://%i',$path)) $path = 'http://'.$_SERVER['HTTP_HOST'].'/'.ltrim($path,'/');
	header('Location: '.$path);
	error_log('redirect path:'.$path,0);
	exit(0);
}
endif;


if (!function_exists('clb_response')) : function clb_response($type, $data, $encoding='') {
	@ob_end_clean(); //should be buffering but @ stops warning if not
	
	if ($encoding) $encoding = '; charset='.$encoding;
	$ctype = (is_int(strpos($type,'/')) ? $type : 'text/'.$type);
	header('Content-type: '.$ctype.$encoding, TRUE);	//true indicates thatprevious content type should be replaced.
	header('Cache-Control: no-cache, must-revalidate'); // HTTP/1.1
	header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
	
	switch($type) {
	case('xml'): echo clb_tag('xml?', '','', array('version'=>'1.0', 'encoding'=>'utf-8')).clb_tag('responses','', $data); break;
	case('html'): echo '<html><head></head><body>'.$data.'</body></html>'; break;
	default: echo $data; break;
	}	
	exit(0);
}
endif;


/*
	pass in path parts as separate params or pass nothing to get current script url 
	first path part can include http://host but current host added if not.
*/
if (!function_exists('clb_make_url')) : function clb_make_url()
{
	$path = (func_num_args() ? '' : $_SERVER['PHP_SELF']);
	for ($i=0; $i<func_num_args(); $i++) $path = rtrim($path,'/').'/'.ltrim(func_get_arg($i),'/');
	if (!preg_match('%^http://%i',$path)) $path = 'http://'.$_SERVER['HTTP_HOST'].'/'.ltrim($path,'/');
	return $path;
}
endif;

if (!function_exists('clb_contains')) : function clb_contains($hay, $needle, $case=TRUE)
{
	if ($case) return is_int(strpos($hay, $needle));
	return is_int(stripos($hay, $needle));
}
endif;

/*
	class used to wrap values that should not be quoted when passed to JSON
	eg clb_json(array('num'=77, 'string'=>'hello', 'constant'=>new clb_lit('MY_CONSTANT')))
*/
if (!function_exists('clb_lit')) : function clb_lit($v) { return array('literal'=>$v); } endif;


/*
	convert a php array structure intoa JSON scructure
	all associative keys are quoted
*/
if (!function_exists('clb_json')) : function clb_json($struct, $quot='"')
{
	$result = '';
	if (is_array($struct)) 
	{
		$test = join('',array_keys($struct));
		//values packaged by clb_lit() are returned without quotes
		if ((count($struct) == 1) && ($test == 'literal')) return reset($struct);
		
		$is_arr = is_numeric($test);	//if all keys are numbers assume a sequential array
		foreach($struct as $key => $val) if ($is_arr)
		{
			$result .= clb_json($val, $quot).', ';
		}
		else
		{
			$result .= $quot.$key.$quot.':'.clb_json($val, $quot).', ';
		}
		return ($is_arr ? '[':'{').trim($result,', ').($is_arr ? ']':'}');
	}
	else if (is_bool($struct))
	{
		return ($struct ? 'true' : 'false');
	}
	else if (is_numeric($struct) && preg_match('/^[-1-9]|^0\./',$struct))	//if leading zeros other than 0.1 then treat as string.
	{
		return $struct;
	}

	return clb_escape($struct, $quot);
}
endif;


/*	
	converts a php structure into an xml file.  pass the root tag name in second parameter.
	any element that is an array will be treated as a tag, elements with a numberical key 
	will get their parents name less the end char, so a child of "Steps" becomes "Step".
	attributes with the "html" in the name or '<>&"' in the value are added as child tags
	to force consistency pass an array of attribute names that are to be handled as child tags
*/
if (!function_exists('clb_xml')) : function clb_xml($struct, $tagname='root', $types=FALSE)
{
	$attrs = '';
	$children = '';
	if (!is_array($struct))
	{
		$children = htmlspecialchars($struct);
	}
	else 
	{
		foreach($struct AS $key=>$val)
		{
			//normally anything that is not an array will be added as an attribute within the open tag, some things are forced to be child nodes
			$force = (is_array($types) ? in_array($key, $types) : (preg_match('/html/i', $key) || (is_string($val) && preg_match('/&<>"/', $val)) || (strlen($val)>20)));
			if (!is_array($val) && !$force)
			{
				$attrs .= $key.'="'.htmlspecialchars($val).'" ';
			}
			else 
			{
				if (is_numeric($key)) $key = substr($tagname,0,-1);
				$children .= clb_xml($val, $key, $types);
			}
		}
		
	}
	return '<'.$tagname.rtrim(' '.$attrs).'>'.$children.'</'.$tagname.'>';
}
endif;


?>