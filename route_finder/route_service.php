<?php

//DEFINE('IS_LOCAL', false); //uncomment this to stop logging from qlog, change FALSE into another expression for when you want logging

//scan downwards for config file
if (!defined('DB_NAME'))
{
	$path = dirname(__FILE__);
	while($path && !file_exists($path.'/config.php')) $path = dirname($path);
	require $path.'/config.php';
}

///scan downwards for _engine directory
$path = dirname(__FILE__);
while($path && !file_exists($path.'/_engine/')) $path = dirname($path);
DEFINE('CODE_DIR', $path.'/_engine/');

require CODE_DIR.'wp-db.php';
require CODE_DIR.'core_lib.php';


function rs_cache_set($key, $value, $ttl = 0) {
	global $cachetype; //Set in config
	global $memcache;
	switch($cachetype) {
		case 'memcache':
			rs_memcache_init();
			$memcache->set($key, $value, MEMCACHE_COMPRESSED, $ttl);
			break;
		case 'apc':
			$value = gzdeflate($value); //Compress
			apc_store($key, $value, $ttl);
			break;
	}
}

function rs_cache_get($key) {
	global $cachetype; //Set in config
	global $memcache;
	switch($cachetype) {
		case 'memcache':
			rs_memcache_init();
			return $memcache->get($key);
			break;
		case 'apc':
			$value = apc_fetch($key);
			if ($value !== false) {
				$value = gzinflate($value); //Uncompress
			}
			return $value;
			break;
	}
	return false; //No cache, so return false for not found
}

function rs_memcache_init() {
	global $memcache;
	global $memcacheservers; //Set in config
	if (!isset($memcache)) { //Init cache instance on first hit
		$memcache = new Memcache();
		foreach($memcacheservers as $server) {
			$memcache->addServer($server);
		}
		$memcache->setCompressThreshold(1000, 0.2);
	}
}

function rs_response($type, $data, $encoding='') {
	@ob_end_clean(); //should be buffering but @ stops warning if not
	
	if ($encoding) $encoding = '; charset='.$encoding;
	$ctype = (is_int(strpos($type,'/')) ? $type : 'text/'.$type);
	header('Content-type: '.$ctype.$encoding, TRUE); //true indicates that previous content type should be replaced.
 //Commented out so this can be controlled upstream
 //header('Cache-Control: no-cache, must-revalidate'); // HTTP/1.1
 //header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
	
	switch($type) {
		case 'html':
		case 'text/html':
			$data = '<html><head></head><body>'.$data.'</body></html>';
			$typehint = 'h';
			break;
		case 'xml':
		case 'text/xml':
			$typehint = 'x';
			break;
		case 'json':
		case 'application/json':
			$typehint = 's';
			break;
		case 'javascript':
		case 'application/javascript':
			$typehint = 'j';
			break;
		case 'php':
		default:
			$typehint = 'p';
			break;
	}
	//If it's a web request, cache it
	if (isset($_SERVER['QUERY_STRING']) and !empty($_SERVER['QUERY_STRING'])) {
		$cache_key = 'rs_'.substr(md5($_SERVER['QUERY_STRING']), 0, 16); //64 bits should be enough
		rs_cache_set($cache_key, $typehint.$data, 86400); //Cache for a day
	}
	if ($typehint == 'p') {
		return $data;
	} else {
		echo $data;
		exit(0);
	}
}

//Configure cache and see if we know the answer to this request already
if (isset($_SERVER['QUERY_STRING']) and !empty($_SERVER['QUERY_STRING'])) {
	$cache_key = 'rs_'.substr(md5($_SERVER['QUERY_STRING']), 0, 16); //64 bits should be enough
	$response = rs_cache_get($cache_key);
	if ($response !== false) {
		$type = substr($response, 0, 1); //First char used to denote content type
		$data = substr($response, 1);
		switch($type) {
			case 'x':
				header('Content-Type: text/xml; charset=UTF-8');
				break;
			case 'j':
				header('Content-Type: application/javascript; charset=UTF-8');
				break;
			case 's':
				header('Content-Type: application/json; charset=UTF-8');
				break;
			case 'h':
				header('Content-Type: text/html; charset=UTF-8');
				break;
			case 'p':
			default:
				header('Content-Type: text/plain; charset=UTF-8');
				break;
		}
		header('Content-Length: '.strlen($data));
		echo $data;
		exit;
	}
}

require CODE_DIR.'pline_lib.php'; 
require CODE_DIR.'pfind_lib.php'; 

//scan downwards for data directory
$path = dirname(__FILE__);
while($path && !file_exists($path.'/route_data/')) $path = dirname($path);
$path .= '/route_data/';

$p2p_paths = array();
// $p2p_paths['tube'] = array('path'=>$path.'tube_p2p.dat', 'types'=>'tube');
// $p2p_paths['rail'] = array('path'=>$path.'rail_p2p.dat', 'types'=>'rail');
$p2p_paths['rtm'] = array('path'=>$path.'rtm_p2p.dat', 'types'=>array('rail','tube','tram'));

$ip = clb_val('', $_SERVER, 'HTTP_CLIENT_IP');
if (empty($ip)) $ip = clb_val('', $_SERVER, 'HTTP_X_FORWARDED_FOR');
if (empty($ip)) $ip = clb_val('', $_SERVER, 'REMOTE_ADDR');

$log_path = dirname(__FILE__).'__logs/';
if (is_dir($log_path) && is_writable($log_path))
{
	$log_path .= 'webservice'.date('Ymd').'.log';
	error_log(date('Y-m-d G:i:s').' '.$ip.' '.clb_val('', $_SERVER, 'HTTP_REFERER').' '.clb_get_prm('', $_REQUEST, 'q')."\n", 3, $log_path);
}

$service = strtolower($_REQUEST['s']); //keep the type of service requested by caller

$_REQUEST['s'] = 'php'; //service: json, xml, php - using php internally so we get the returned structure and can despatch as we need
$_REQUEST['callback'] = 'mfw_dir_result'; //function in javascript api to handle json result (if 

/*
	$pfind_defs and $editor_types defined in config.php
	
	normally pfind_service() sends the result and exists, but if the web service mode is "php" the structure is returned.
*/

$result = pfind_service($_REQUEST, $pfind_defs, $editor_types, $p2p_paths);

if ($result['error'] == 604) {
	$_REQUEST['gd'] = 0; //turn off distance only
	$_REQUEST['gp'] = 0; //turn off polyline
	$_REQUEST['gs'] = 0; //turn off steps
	$result = pfind_service($_REQUEST, $pfind_defs, $editor_types, $p2p_paths);
}

switch($service) {
	case('javascript'):
		$sid = clb_val('', $_REQUEST, 'sid');
		$func = clb_val('mfw_dir_result', $_REQUEST, 'callback');
		$return_data = clb_json($result, "'");
		$callback = $func.'(\''.$sid.'\','.$return_data.');';
		rs_response('application/javascript', $callback, 'UTF-8');
		break;
	case('json'):
		$return_data = json_encode($result);
		rs_response('application/json', $return_data, 'UTF-8');
		break;
	case('xml'):
		$return_data = clb_xml($result, 'RouteFinder');
		rs_response('xml', clb_tag('xml?', '','', array('version'=>'1.0', 'encoding'=>'utf-8')).clb_tag('responses','', $return_data), 'UTF-8');
		break;
	default:
		header('x', TRUE, 400); //Deliver a 'bad request' response
}
?>
