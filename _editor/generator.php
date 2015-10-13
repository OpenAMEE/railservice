<?php

//DEFINE('IS_LOCAL', FALSE);	//uncomment this to stop logging from qlog, change FALSE into another expression for when you want logging

ini_set('memory_limit', '256M');

//scan downwards for config file
if (!defined('DB_NAME'))
{
	$path = dirname(__FILE__);
	while($path && !file_exists($path.'/config.php')) $path = dirname($path);
	require_once($path.'/config.php');
}

//scan downwards for _engine directory
$path = dirname(__FILE__);
while($path && !file_exists($path.'/_engine/')) $path = dirname($path);
DEFINE('CODE_DIR', $path.'/_engine/');

require_once(CODE_DIR.'wp-db.php');
require_once(CODE_DIR.'core_lib.php');
require_once(CODE_DIR.'pline_lib.php'); 
require_once(CODE_DIR.'pfind_lib.php'); 

require_once(CODE_DIR.'pbuild_lib.php'); 	//this file included in builder but not in route finder web service

pfind_def_tables($pfind_defs);

//scan downwards for data directory
$data_path = dirname(__FILE__);
while($data_path && !file_exists($data_path.'/route_data/')) $data_path = dirname($data_path);
$data_path .= '/route_data/';



	//links requiring reversing 
	// <>Newark Castle - Newark North Gate, WJUFPED, DNTPXXC - this is across a junction not from a terminus
	// <>Liskeard- St Keyne, QNUBNAP, RABITVK - backs out of a terminus before splitting off at next junction


$raw_segs = array('rseg');
$metaphone_types = array('rail','tube','tram');

$near_path = $data_path.'rtm_near.dat';
$prim_path = $data_path.'rtm_prim.dat';

switch('rtm'){	//<= change this manaully to select which types to be generated
case('rtm'):
	$route_where = ' area IN '.clb_join(array('L','TUB'), TRUE);
	$node_types = array('rail', 'plat', 'tube', 'tram');
	$stops_path = $data_path.'rtm_stops.dat';
	$p2p_path = $data_path.'rtm_p2p.dat';
	break;
	
case('rail'):
	$route_where = ' area IN '.clb_join(array('L'), TRUE);
	$node_types = array('rail', 'plat');
	$stops_path = $data_path.'rail_stops.dat';
	$p2p_path = $data_path.'rail_p2p.dat';
	break;
	
case('tube'):
	$route_where = ' area IN '.clb_join(array('TUB'), TRUE);	//croyden tram stops included in TUB
	$node_types = array('tube', 'tram');
	$stops_path = $data_path.'tube_stops.dat';
	$p2p_path = $data_path.'tube_p2p.dat';
	break;
}
$metaphones = $data_path.'metaphones.txt';


function inspect_data($path, $depth)
{
	$data = file_get_contents($path);	//, FILE_BINARY);
	$map = clb_blob_dec($data);
	qpre(pfind_shallow($map, $depth));
}

if (($val = clb_val(FALSE, $_REQUEST, 'p')) && in_array($val, array('prim','near','stops','p2p')))
{
	echo clb_tag('a','back to generator','', array('href'=>clb_make_url())).'<br />';
	$path = $val.'_path';
	$data = file_get_contents($$path);	//, FILE_BINARY);
	$map = clb_blob_dec($data);
	qpre($map);
	exit();
}

if (clb_val(FALSE, $_REQUEST, 'p') == 'dist')
{
	$flat = 'wp_mfw_dists';
	$sel1 = $wpdb->get_results('SELECT DISTINCT a FROM '.$flat, ARRAY_A);
	$a = clb_column($sel1, 'a');
	$sel1 = $wpdb->get_results('SELECT DISTINCT b FROM '.$flat, ARRAY_A);
	$b = clb_column($sel1, 'b');
	$a = array_merge($a, $b);
	$a = array_unique($a);
	qpre(count($a), $a);
	exit();

}

if (isset($argv))
{
	$action = clb_val('', $argv, 1);
	
	switch($action){
		case ('names'):
			//always index all three station/stop types
			$index = array();
			$query = 'SELECT '.RF_NODES_SELECT.' FROM '.RF_NODES_FROM.' WHERE '.RF_NODES_TYPE.' IN '.clb_join($metaphone_types, TRUE);
			$sel = $wpdb->get_results($query, ARRAY_A);
			if (is_array($sel))
			{
				foreach($sel AS $row)
				{
					$name = pfind_unify_names($row[RF_NODES_NAME]);
					$words = preg_split('/\W+/',$name,-1,PREG_SPLIT_NO_EMPTY);
					foreach($words AS $w)
					{
						if ($w == '&') $w = 'and';
						$sound = metaphone($w);	//sometimes the sound is nothing like a number or 'y'
						if ($sound) $index[$sound][$row[RF_NODES_TYPE]][] = $row[RF_NODES_KEY];	// alternative $index[$sound][$row['ptype']]['pnum'] = $name;
					}
				}
				$blob = clb_blob_enc($index, TRUE); //TRUE=binary
				file_put_contents($metaphones, $blob);	//, FILE_BINARY);
			}
			break;
			
		case ('near'): //create mapping from raw segs to nodes 
			
			$stopsegs = pbuild_stop2segs($node_types, $raw_segs, $near_path);
			break;
			
			
		case ('prim'):	//build array with end points of raw links so we can build node to node links
			
			$primitives = pbuild_primitives($raw_segs, $prim_path);
			break;
			
			
		case ('links'): //build ACTUAL node to node link reocrds
			
			$data = file_get_contents($near_path);	//, FILE_BINARY);
			$stopsegs = clb_blob_dec($data);
			
			$data = file_get_contents($prim_path);	//, FILE_BINARY);
			$primitives = clb_blob_dec($data);
			
			$query = 'SELECT '.RF_ROUTES_KEY.' FROM '.RF_ROUTES_FROM.' WHERE '.$route_where;
			$sel = $wpdb->get_results($query, ARRAY_A);
			$rnums = clb_column($sel, RF_ROUTES_KEY);
			
			//creates node to node links from the primitive ones
			pbuild_daisychain($rnums, $primitives, $stopsegs);
			break;
			
			
		case ('stops'):	//build array of rnum/pnum stops so we can look up which stops are on which routes
			
			$query = 'SELECT '.RF_ROUTES_KEY.' FROM '.RF_ROUTES_FROM.' WHERE '.$route_where;
			$sel = $wpdb->get_results($query, ARRAY_A);
			$rnums = clb_column($sel, RF_ROUTES_KEY);
			$stops_xref = pbuild_stops_xref($rnums, $stops_path);
			break;
			
			
		case ('p2p'):	//build array of node to node links that will be used when finding shortest paths
			
			$data = file_get_contents($stops_path);	//, FILE_BINARY);
			$stops_xref = clb_blob_dec($data);
			
			//the p2p_links list is built based on a selection of routes and links only included if they are on a route
			$p2p_links = pbuild_p2p_links($node_types, $stops_xref, $p2p_path);
			break;
			
			
		case ('walk'):	//check for nodes near other nodes and CREATE INTERCHANGE LINKS
		
			$data = file_get_contents($p2p_path);	//, FILE_BINARY);
			$p2p_links = clb_blob_dec($data);
			pbuild_walk_links($node_types, $p2p_links);
			break;
			
		case ('clean'):
			global $editor_types, $editor_tables;
			$types = array();
			foreach($editor_types AS $type=>$info) if ((strlen($type)==4) && (clb_val(FALSE, $info, 'table') == RF_LINKS_FROM)) $types[] = $type;
			
			$query = 'SELECT '.RF_LINKS_KEY.','.RF_LINKS_POINTS.' FROM '.RF_LINKS_FROM.' WHERE '.RF_LINKS_TYPE.' IN '.clb_join($types, TRUE);
			$sel = $wpdb->get_results($query, ARRAY_A);
			$max = clb_count($sel);
			if ($max) foreach($sel AS $i=>$rec)
			{
				if (($i % round($max/10)) == 0) echo round(100*($i/$max)).'% complete'."\n";
				$pnum = clb_val('', $rec, RF_LINKS_KEY);
				
				$query = '';
				$points = pline_pts_arr(clb_val('', $rec, RF_LINKS_POINTS));
				$pt = reset($points);
				$query .= ','.RF_LINKS_END1.'='.clb_escape(clb_b64e($pt[0], 1E4).clb_b64e($pt[1], 1E4));
				$pt = end($points);
				$query .= ','.RF_LINKS_END2.'='.clb_escape(clb_b64e($pt[0], 1E4).clb_b64e($pt[1], 1E4));
				
				$query = 'UPDATE '.RF_LINKS_FROM.' SET '.trim($query,', ').' WHERE pnum='.clb_escape($pnum);
				$wpdb->query($query);
			}
			break;
			
		case ('blowup'):
			$path = dirname(__FILE__);
			require_once($path.'/blowup.php');
			break;
	}	
}
else
{
	
	$cmd = (clb_contains(__FILE__, 'toby') ? '/usr/local/php5/bin/php' : 'php').' -f '.__FILE__.' -- ';
	
	echo clb_tag('h1', 'Route finder lookup table generator');

	echo clb_tag('p', 'This page lists the processes and files that need to be generated to prepare the route finder to function.
	Generally things higher on the page need to be done before things lower down.
	When things need to be done, you should copy and paste the command into command line, and then refresh this page to see the new statuses.');
	
	//CHECK IF NEAR ARRAY NEEDS REBUILDING
	
	
	echo clb_tag('h3', 'Metaphone File');
	echo clb_tag('p', 'This file assists with looking up stations by name by keeping an index of the words and sounds in the names. 
	You only need this file if you are doing named lookups and it should be regenerated if you have changed or added stations.');

	$path = $metaphones;
	if (!is_file($path))
	{
		echo clb_tag('p', 'There is no file, you need to create this', '', array('style'=>'color:red;'));
	} 
	else
	{
		//check nodes of all stop/station types
		$time = clb_escape(clb_now_utc(filemtime($path)));
		$query = 'SELECT 1 FROM '.RF_NODES_FROM.' WHERE modified > '.$time.' AND '.RF_NODES_TYPE.' IN '.clb_join($metaphone_types, TRUE);
		$sel = $wpdb->get_results($query, ARRAY_A);
		if (clb_count($sel) > 0)
		{
			echo clb_tag('p', 'Stop/Station nodes have been edited and the metaphones file may need rebuilding', '', array('style'=>'color:#f1a629;'));
		}
		else
		{
			echo clb_tag('p', 'This file seems up to date', '', array('style'=>'color:green;'));
		}
	}
	echo clb_tag('pre','', clb_tag('code',$cmd.'names'));
	

	echo clb_tag('h3', 'Proximity File');
	echo clb_tag('p', 'This file tracks which stops/stations are near to which primitive link lines.  
	It is used when constructing station to station links.
	This can take ten minutes or so to run depending on machine speed and number of data points.');

	$path = $near_path;
	if (!is_file($path))
	{
		echo clb_tag('p', 'There is no file, you need to create this', '', array('style'=>'color:red;'));
	} 
	else
	{
		$time = clb_escape(clb_now_utc(filemtime($path)));

		//check nodes of all stop/station types
		$query = 'SELECT 1 FROM '.RF_NODES_FROM.' WHERE modified > '.$time.' AND '.RF_NODES_TYPE.' IN '.clb_join($metaphone_types, TRUE);
		$nodes = $wpdb->get_results($query, ARRAY_A);
		
		//check all raw segments
		$query = 'SELECT 1 FROM '.RF_LINKS_FROM.' WHERE '.RF_LINKS_MODIFIED.' > '.$time.' AND '.RF_LINKS_TYPE.' IN '.clb_join($raw_segs, TRUE);
		$links = $wpdb->get_results($query, ARRAY_A);
		if ((clb_count($nodes) > 0) || (clb_count($links) > 0))
		{
			echo clb_tag('p', clb_count($nodes).' station/stops and '.clb_count($links).' primitive links have been added or edited so this should be rebuilt.', '', array('style'=>'color:#f1a629;'));
		}
		else
		{
			echo clb_tag('p', 'This file seems up to date', '', array('style'=>'color:green;'));
		}
		inspect_data($path, 1);
	}
	echo clb_tag('pre','', clb_tag('code',$cmd.'near'));
	
	


	echo clb_tag('h3', 'Primitive Links File');
	echo clb_tag('p', 'This file holds a list of all "rseg" type links and their end points to accellerate the construction of station to station links.');

	$path = $prim_path;
	if (!is_file($path))
	{
		echo clb_tag('p', 'There is no file, you need to create this', '', array('style'=>'color:red;'));
	} 
	else
	{
		$time = clb_escape(clb_now_utc(filemtime($path)));

		//check all raw segments
		$query = 'SELECT 1 FROM '.RF_LINKS_FROM.' WHERE '.RF_LINKS_MODIFIED.' > '.$time.' AND '.RF_LINKS_TYPE.' IN '.clb_join($raw_segs, TRUE);
		$links = $wpdb->get_results($query, ARRAY_A);
		
		if (clb_count($links) > 0)
		{
			echo clb_tag('p', clb_count($links).' primitive links have been added or edited so this should be rebuilt.', '', array('style'=>'color:#f1a629;'));
		}
		else
		{
			echo clb_tag('p', 'This file seems up to date', '', array('style'=>'color:green;'));
		}
		inspect_data($path, 1);
	}
	echo clb_tag('pre','', clb_tag('code',$cmd.'prim'));
	
	
	echo clb_tag('h3', 'Build Station to Station Links');
	echo clb_tag('p', 'This process generates linke records in the data as needed and does not produce a file.  It relies on the primitives and the proximity files.
	This needs to be run if those files contain new information. The links need to be generated before generating the P2P file, but do not need to be regenerated 
	unless the underlying stops or links have been changed in the map editor.');

	if (!is_file($prim_path) || !is_file($near_path))
	{
		if (!is_file($near_path)) echo clb_tag('p', 'This process cannot be run because the proximity file on which it depends is not present', '', array('style'=>'color:red;'));
		if (!is_file($prim_path)) echo clb_tag('p', 'This process cannot be run because the primitive links file on which it depends is not present', '', array('style'=>'color:red;'));
	} 
	else
	{
		//get the later file
		$time = clb_escape(clb_now_utc(filemtime($prim_path)));
		$test = clb_escape(clb_now_utc(filemtime($near_path)));
		if ($test > $time) $time = $test;

		//check station to station links of all stop/staion types
		$query = 'SELECT 1 FROM '.RF_LINKS_FROM.' WHERE '.RF_LINKS_MODIFIED.' > '.$time.' AND '.RF_LINKS_TYPE.' IN '.clb_join($metaphone_types, TRUE);
		$links = $wpdb->get_results($query, ARRAY_A);
		
		if (clb_count($links) <= 0)
		{
			echo clb_tag('p', clb_count($links).' no new links have been added since the poximity and primitive link files were last built, you may need to run this process.', '', array('style'=>'color:#f1a629;'));
		}
		else
		{
			echo clb_tag('p', 'This process appears to have been run.', '', array('style'=>'color:green;'));
		}
		
		//show breakdown
		$query = 'SELECT '.RF_LINKS_TYPE.', count(*) AS c FROM '.RF_LINKS_FROM.' WHERE '.RF_LINKS_TYPE.' IN '.clb_join($metaphone_types, TRUE).' GROUP BY '.RF_LINKS_TYPE;
		$links = $wpdb->get_results($query, ARRAY_A);
		$type = clb_column($links, RF_LINKS_TYPE);
		$count = clb_column($links, 'c');
		qpre(array_combine($type, $count));

	}
	echo clb_tag('pre','', clb_tag('code',$cmd.'links'));
	
	

	echo clb_tag('h3', 'Stops Cross Reference File');
	echo clb_tag('p', 'This file holds a list of all station/stops that are to be accessable from the route finder and cross references them with routes.  
	This file is used when generating the final route finder lookup table.');

	$path = $stops_path;
	if (!is_file($path))
	{
		echo clb_tag('p', 'There is no file, you need to create this', '', array('style'=>'color:red;'));
	} 
	else
	{
		$time = clb_escape(clb_now_utc(filemtime($path)));

		//check nodes of all stop/station types
		$query = 'SELECT 1 FROM '.RF_NODES_FROM.' WHERE modified > '.$time.' AND '.RF_NODES_TYPE.' IN '.clb_join($node_types, TRUE);
		$nodes = $wpdb->get_results($query, ARRAY_A);
		
		if (clb_count($nodes) > 0)
		{
			echo clb_tag('p', clb_count($nodes).' station/stops have been added or edited so this should be rebuilt.', '', array('style'=>'color:#f1a629;'));
		}
		else
		{
			echo clb_tag('p', 'This file seems up to date', '', array('style'=>'color:green;'));
		}
		inspect_data($path, 1);
	}
	echo clb_tag('pre','', clb_tag('code',$cmd.'stops'));
	
	


	echo clb_tag('h3', 'Route Finder P2P File');
	echo clb_tag('p', 'This file (and the metaphones) are used by the actual route finder and are the only ones that need to be present when running the web service, the others are all precursor files.');

	$path = $p2p_path;
	if (!is_file($path))
	{
		echo clb_tag('p', 'There is no file, you need to create this', '', array('style'=>'color:red;'));
	} 
	else
	{
		$time = $test = clb_escape(clb_now_utc(filemtime($path)));
		if (is_file($stops_path)) $test = clb_escape(clb_now_utc(filemtime($stops_path)));

		//check station to station links of given types
		$query = 'SELECT 1 FROM '.RF_LINKS_FROM.' WHERE '.RF_LINKS_MODIFIED.' > '.$time.' AND '.RF_LINKS_TYPE.' IN '.clb_join($node_types, TRUE);
		$links = $wpdb->get_results($query, ARRAY_A);
		
		if ($time < $test)
		{
			echo clb_tag('p', 'The stops file is more recent than this file and this file should be regenerated.', '', array('style'=>'color:red;'));
		}
		else if (clb_count($links) > 0)
		{
			echo clb_tag('p', clb_count($links).' station to station links have been added or updated so this should be rebuilt.', '', array('style'=>'color:#f1a629;'));
		}
		else
		{
			echo clb_tag('p', 'This file seems up to date', '', array('style'=>'color:green;'));
		}
		inspect_data($path, 1);
	}
	echo clb_tag('pre','', clb_tag('code',$cmd.'p2p'));




	echo clb_tag('h3', 'Interchanges Process');
	echo clb_tag('p', 'This process looks at stations/stops accessed by routes (as given in the stops file) and determines which nodes are
	close but not connected.  It then creates walk links if the nodes are within 400m of each other.  This process relies on the p2p file 
	so walk links cannot be generated before the p2p, but the p2p file needs to be regenerated after running this process.');

	if (!is_file($p2p_path))
	{
		echo clb_tag('p', 'There is no p2p file, you cannot run this process until it exists', '', array('style'=>'color:red;'));
	} 
	else
	{
		$time = $test = clb_escape(clb_now_utc(filemtime($p2p_path)));
		if (is_file($stops_path)) $test = clb_escape(clb_now_utc(filemtime($stops_path)));

		//check station to station links of given types
		$query = 'SELECT 1 FROM '.RF_LINKS_FROM.' WHERE '.RF_LINKS_MODIFIED.' > '.$time.' AND '.RF_LINKS_TYPE.' IN '.clb_join($node_types, TRUE);
		$links = $wpdb->get_results($query, ARRAY_A);
		
		if ($time < $test)
		{
			echo clb_tag('p', 'The stops file is more recent than this file and this file should be regenerated.', '', array('style'=>'color:red;'));
		}
		else if (clb_count($links) > 0)
		{
			echo clb_tag('p', clb_count($links).' station to statio links have been added or updates so this should be rebuilt.', '', array('style'=>'color:#f1a629;'));
		}
		else
		{
			echo clb_tag('p', 'This file seems up to date', '', array('style'=>'color:green;'));
		}
	}
	echo clb_tag('pre','', clb_tag('code',$cmd.'walk'));


}


?>