<?php
/**
 * config file to load database
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'railservice');

/** MySQL database username */
define('DB_USER', '');

/** MySQL database password */
define('DB_PASSWORD', '');

/** MySQL hostname */
define('DB_HOST', '');

/** Database Charset to use in creating database tables. */
define('DB_CHARSET', 'utf8');

/** The Database Collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each a unique
 * prefix. Only numbers, letters, and underscores please!
 */
$table_prefix  = 'wp_';


DEFINE('GOOGLE_API_KEY',''); //railservice.amee.com

$editor_map_options = array('zoom'=>7, 'lat'=>53.252069, 'lng'=>-2.059937);		//uk
//$editor_map_options = array('zoom'=>12, 'lat'=>51.44938, 'lng'=>-2.59479);	//bristol


//================== things below here only change if the tables are changed.

//this is used for the map editor not in the live system

$editor_tables = array();
$editor_tables['wp_mfw_places'] = array('polyline'=>FALSE, 'prefix'=>'P', 'select'=>array('pnum','lat','lng','ptype','title','more','notes','weblink','audit'));
$editor_tables['wp_mfw_links'] = array('polyline'=>'line/points', 'prefix'=>'L', 'select'=>array('pnum','lat','lng','end1','end2','ptype','title','line'));
$editor_tables['wp_mfw_routes'] = array('polyline'=>FALSE, 'prefix'=>'R', 'select'=>array('rnum','ptype','title','orig','dest'), 'routes'=>'stops_list/stops_back');

$editor_types = array();
//$editor_types['cust'] =  array('table'=>'wp_mfw_places', 'color'=>'888888', 'drag'=>TRUE, 'label'=>'temporary');	//grey
$editor_types['rail'] =  array('table'=>'wp_mfw_places', 'color'=>'880000', 'drag'=>TRUE, 'label'=>'rail stations');	//garnet
$editor_types['tube'] =  array('table'=>'wp_mfw_places', 'color'=>'880088', 'drag'=>TRUE, 'label'=>'tube stops');	//purple
$editor_types['tram'] =  array('table'=>'wp_mfw_places', 'color'=>'ff00ff', 'drag'=>TRUE, 'label'=>'tram/metro stops');	//fuschia
$editor_types['plat'] =  array('table'=>'wp_mfw_places', 'color'=>'FFA07A', 'drag'=>TRUE, 'label'=>'platform');	//salmon
$editor_types['rlnk'] =  array('table'=>'wp_mfw_places', 'color'=>'00ffff', 'drag'=>FALSE, 'label'=>'rail endpoints');	//green
$editor_types['rseg'] =  array('table'=>'wp_mfw_links', 'color'=>'88ff00', 'drag'=>FALSE, 'label'=>'rail primitives');	//olive
$editor_types['walk'] =  array('table'=>'wp_mfw_links', 'color'=>'FFD700', 'drag'=>FALSE, 'label'=>'walk links');	//gold
$editor_types['L_rail'] =  array('table'=>'wp_mfw_links', 'color'=>'4682B4', 'drag'=>FALSE, 'label'=>'rail 2 rail');	//SteelBlue
$editor_types['L_tube'] =  array('table'=>'wp_mfw_links', 'color'=>'AFEEEE', 'drag'=>FALSE, 'label'=>'tube 2 tube');	//PaleTurquoise
$editor_types['L_tram'] =  array('table'=>'wp_mfw_links', 'color'=>'B0C4DE', 'drag'=>FALSE, 'label'=>'tram 2 tram');	//LightSteelBlue


//these definitions are used by the generator and the actual route finder

$pfind_defs = array();
$pfind_defs['NODES'] = array(
	'FROM' =>	'wp_mfw_places',
	'SELECT' =>	'pnum, ptype, title, more, lat, lng',
	'KEY' =>	'pnum',
	'TYPE' =>	'ptype',
	'NAME' =>	'title',
	'DESC' =>	'more',
);
$pfind_defs['ROUTES'] = array(
	'FROM' =>	'wp_mfw_routes',
	'SELECT' =>	'rnum, title, orig, dest, ptype',
	'KEY' =>	'rnum',
	'TYPE' =>	'ptype',
	'ORIG' =>	'orig',
	'DEST' =>	'dest',
	'NAME' =>	'title',
	'STOPS_LIST' =>	'stops_list',
	'STOPS_BACK' =>	'stops_back',
);

$pfind_defs['LINKS'] = array(
	'FROM' =>	'wp_mfw_links',
	'SELECT' =>	'pnum, ptype, title, end1, end2, reverse, dist, weight, time, created, modified',
	'KEY' =>	'pnum',
	'TYPE' =>	'ptype',
	'NAME' =>	'title',
	'END1' =>	'end1',
	'END2' =>	'end2',
	'REVERSE'=>	'reverse',
	'DIST' =>	'dist',
	'WEIGHT' =>	'weight',
	'TIME' =>	'time',
	'MODIFIED' =>	'modified',
	'CREATED' =>	'created',
	'LINE' =>	'line',
	'POINTS' =>	'points',
);

$pfind_defs['DISTS'] = array(
	'FROM' =>	'wp_mfw_dists',
);

$cachetype = ''; //Leave blank for no caching
// $cachetype = 'apc'; //Use the APC PHP cache
// $cachetype = 'memcache'; //Use the memcache distributed cache
//Array of IP addresses of memcache servers
// $memcacheservers = array(
	// 'railservice.dyhdwb.cfg.use1.cache.amazonaws.com'
// );
?>
