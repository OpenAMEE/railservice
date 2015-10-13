<?php

if (!defined('IS_LOCAL')) DEFINE('IS_LOCAL',preg_match('!^/Users/!i', __FILE__));	//files in user folders indicates local test system

DEFINE('SITE_LIB', rtrim(dirname(dirname(__FILE__)),'/').'/_lib/');		//this is the _lib folder of the current site
DEFINE('THIS_DIR', rtrim(dirname(__FILE__),'/').'/');		//same folder as this file
DEFINE('CODE_DIR', rtrim(dirname(dirname(SITE_LIB)),'/').'/_engine/');	//this is the shared library folder
if (!defined('DIR_ROOT_ABS')) DEFINE('DIR_ROOT_ABS', rtrim(dirname(SITE_LIB),'/').'/');	//doing this so that mobile gets normal value and not one deeper which causes havock.

$path = rtrim(dirname(rtrim(DIR_ROOT_ABS,'/')),'/').'/secret/bus.inc';
include_once($path);	//depends on IS_LOCAL from above


require_once(SITE_LIB.'pathbuilder.php'); 
require_once(SITE_LIB.'pathfinder.php'); 
require_once(SITE_LIB.'polylines.php'); 

pfind_def_tables($pfind_defs);

db_open();

$folder = '/Users/tobylewis/Sites/buses_sched/';

/*
	$map_path - used on 'near' and 'links'
	$links_path - used on 'raw' and 'links'
	$stops_path - used on 'stops' and 'p2p' (and 'interchanges')
	$p2p_path - used on 'p2p' and 'interchanges'

	
*/
switch('rail') {

	case('tube'):
		$map_path = $folder.'tube_near.dat';
		$links_path = $folder.'tube_raw.dat';
		$stops_path = $folder.'tube_stops.dat';
		$p2p_path = $folder.'tube_p2p.dat';
		
		$raw_segs = array('rseg');
		
		$node_types = array('tube','tram');	//croydon tramlink routes are entered as "TUB" routes
		//croydon tramlink is part of TUB
		$route_where = ' area IN '.clb_join(array('TUB'), TRUE);	//$route_where selects routes for links generation and rp_stops
		
		
		// nodes not found +|+ 3 +|+ 308 +|+ AMUZGRP=Rotherhithe, VMYBAHJ=Surrey Quays, TSIDMTF=Wapping east london line waiting rail conversion
		//AHVTNK=Wallsend in newcastle
		break;
		
	case('rail'):
		$map_path = $folder.'rail_near.dat';
		$stops_path = $folder.'rail_stops.dat';
		$links_path = $folder.'rail_raw.dat';
		$p2p_path = $folder.'rail_p2p.dat';
		
		$raw_segs = array('rseg');
		
		$node_types = array('rail', 'plat');
		
		//$route_where selects routes for links generation and rp_stops
		$route_where = ' area IN '.clb_join(array('L'), TRUE);	//no need to include LON (London Overground Network) routes as these are in the L area records 

		//nodes not found +|+ 4 +|+ 2545 +|+ SABONPB, YQYLPPJ, ZPWEPAB, STJNGSB	alton towers, wedgewood etc
		
		//links requiring reversing 
		// <>Newark Castle - Newark North Gate, WJUFPED, DNTPXXC - this is across a junction not from a terminus
		// <>Liskeard- St Keyne, QNUBNAP, RABITVK - backs out of a terminus before splitting off at next junction
		break;
		
	case('rtm'):	
		//only process stops and p2p
		$p2p_path = $folder.'rtm_p2p.dat';
		$stops_path = $folder.'rtm_stops.dat';
		$route_where = ' area IN '.clb_join(array('L','TUB'), TRUE);	//LON = London Overground Network should be included in the L routes made from rail schedules
		
		$node_types = array('rail', 'walk', 'tube', 'tram');
		break;
		
	case('bike'):	
		/* 
			this is not fully worked out, roads and qsegs work from end points 
			csegs will get regenerated using bike nodes to identify entry points and to connect to the road end points 
			
			will not follow exactly same steps as rail  
			
			will also need to change
		*/
		$map_path = $folder.'bikentries.dat';
		$links_path = $folder.'rawlinks.dat';
		$p2p_path = '';	//not used since bikes are always raw nodes
		
		$raw_segs = array('cseg', 'qseg', 'road');			//seg types for locating segs near bike nodes
		$raw_segs = array(		  'qseg', 'road', 'bike');	//seg types for route finding
		
		$node_types = array('bike');
		break;
		
	case('bus'):	
		$map_path = $folder.'bus_near.dat';
		$stops_path = $folder.'bus_stops.dat';
		$links_path = $folder.'bus_raw.dat';
		$p2p_path = $folder.'bus_p2p.dat';
		
		$raw_segs = array('stop', 'walk');
		
		$node_types = array('stop');
		break;
		
	case('bristol'):	
		//only process point to point links and creation of interchanges
		$p2p_path = $folder.'multi_p2p.dat';
		
		$node_types = array('stop', 'rail', 'walk');
		break;
		
}



if (IS_CLI) {	//(isset($argv)) {
	$mode = safe_val($argv, 1, 1);
	qlog(__LINE__, __FILE__, $mode);	
} else {

	$mode = safe_val($_REQUEST, 'm', 1);
	$path = '';
}


switch($mode) {
	case ('names'):
		//copied code from find which should be here to build list of sound alike names
	//$index = pfind_sound_index($path, array('rail','tube','tram'), $table);
		$index = array();
		$sel = do_query('SELECT pnum, title, ptype FROM '.$table.' WHERE ptype IN '.clb_join($ptypes, TRUE), __FILE__, __LINE__,'');
		if (is_array($sel)) {
			foreach($sel AS $row) {
				$name = pfind_unify_names($row['name']);
				$words = preg_split('/\W+/',$name,-1,PREG_SPLIT_NO_EMPTY);
				foreach($words AS $w) {
					if ($w == '&') $w = 'and';
					$sound = metaphone($w);	//sometimes the sound is nothing like a number or 'y'
					if ($sound) $index[$sound][$row['ptype']][] = $row['pnum'];	// alternative $index[$sound][$row['ptype']]['pnum'] = $name;
				}
			}
			if ($path && is_dir(dirname($path))) {
				$blob = clb_blob_enc($index, TRUE); //TRUE=binary
				file_put_contents($path, $blob, FILE_BINARY);
			}			
		}
		break;
		
	case ('near'): //create mapping from raw segs to nodes 
		if (IS_CLI) {
			$map = pbuild_stop2segs($node_types, $raw_segs, $map_path);
		} else {
			$path = $map_path;
		}
		break;
		
		
	case ('raw'):	//build array with end points of raw links so we can build node to node links
		if (IS_CLI) {
			$map = pbuild_primitives($raw_segs, $links_path);
		} else {
			$path = $links_path;
		}
		break;
		
	case ('stops'):	//build array of rnum/pnum stops so we can look up which stops are on which routes
		if (IS_CLI) {
			$sel = do_query('SELECT rnum FROM routes WHERE '.$route_where, __FILE__, __LINE__,'rnum');
			$rnums = array_keys($sel);
			$map = pbuild_stops_xref($rnums, $stops_path);
		} else {
			$path = $stops_path;
		}
		break;
		
		
	case ('links'): //build ACTUAL node to node link reocrds
		if (IS_CLI)
		{
			//arrays connecting nodes to raw segs
			$data = file_get_contents($map_path, FILE_BINARY);
			$stopsegs = clb_blob_dec($data);
			
			//array listing end points of raw segs
			$data = file_get_contents($links_path, FILE_BINARY);
			$links = clb_blob_dec($data);
			
			$sel = do_query('SELECT rnum FROM routes WHERE '.$route_where, __FILE__, __LINE__,'rnum');
			//$sel = do_query('SELECT rnum FROM routes WHERE rnum = '.clb_escape('TUBDIS_WIM'), __FILE__, __LINE__,'rnum');
			
			$rnums = array_keys($sel);
			//$rnums = array('test');
			pbuild_daisychain($rnums, $links, $stopsegs);
			
		} else {
			qpre($mode.' mode in the CLI generates the node to node link segments');
		}
		break;
		
		
	case ('p2p'):	//build array of node to node links that will be used when finding shortest paths
		if (IS_CLI) {
			//array listing rnum/pnum intersections
			$data = file_get_contents($stops_path, FILE_BINARY);
			$stops_xref = clb_blob_dec($data);
			
			//the rp_stops list is built based on a selection of routes and links only included if they are on a route
			$map = pbuild_p2p_links($node_types, $stops_xref, $p2p_path);
			
		} else {
			$path = $p2p_path;
		}
		break;
		
	case ('interchange'):	//check for nodes near other nodes and CREATE INTERCHANGE LINKS
		//this requires the p2p file so have to generate it, run this and then rerun the p2p file
		if (IS_CLI) {
		
			//load list of links so we do not make interchanges on nodes that are already linked
			$data = file_get_contents($p2p_path, FILE_BINARY);
			$links = clb_blob_dec($data);
						
			//since interchange switches between modes, uses its own list of interchange types
			$ptypes = array('rail','tube','tram');
			pbuild_walk_links($ptypes, $links);
			
		} else {
			qpre($mode.' mode in the CLI generates the interchange "walk" link segments');
			
		}
		break;
		
		
	
	
	case (7):
		if (IS_CLI) {
			pbuild_check_ends($ptype);
			
		} else {
			qpre($mode.' mode in the CLI check_raw_ends');
			
		}
		break;
		
		
	case (8):
		if (IS_CLI) {
			echo $mode.' no function for this mode, tests route finder in browser';
			
		} else {
			$entries = array('southfields to amersham');
			$entries = array('ealing broadway to upminster bridge');
			$entries = array('earls to heathrow terminal 5');
			//$entries = array('camden town to stratford');
			//$entries = array('southfields to east putney');
			//$entries = array('waterloo to paddington');
			$waypoints = array();
			$result = make_rail_waypoints($entries, $waypoints);
			if ($result['error'] == 200) {
	//qlog(__LINE__, $waypoints);
				$data = file_get_contents($p2p_path, FILE_BINARY);
				$links = clb_blob_dec($data);	
	$prop = array();
	// 	$prop['units']  = 'km';
	$prop['opto']  = 'best';
	// 	$prop['opacity']  = 0.5;
	// 	$prop['color']  = '#880088';
	// 	$prop['getSteps']  = 1;
	// 	$prop['getPolyline']  = 1;
				$result = route_request($waypoints, $links, $prop);
			}
			qpre($result);
			
		}
		break;
		
		
	case (9):
		if (IS_CLI) {
			echo 'no function for mode 9';
			
		} else {
			//get array of pnum=>tubename
			$stops = do_query('SELECT pnum, name FROM places WHERE ptype in ("tube")',__FILE__, __LINE__,'*');
			$stops = array_combine($stops['pnum'], $stops['name']);
			
			//get array of links for tube and rail
			$sel = do_query('SELECT pnum, end1, end2 FROM links WHERE created>"2008-07-01" AND ptype in ("tube","rail")',__FILE__, __LINE__,'');
			
			//counts counts number of uses of each end point
			$counts = array();
			if (is_array($sel)) foreach($sel AS $row)
			{
				foreach(array('end1', 'end2') AS $fld)
				{
					if (!isset($counts[$row[$fld]])) $counts[$row[$fld]] = 0;
					$counts[$row[$fld]]++;
				}
			}
			
			//sort by number of uses and convert value from just count to count and stop/station name
			arsort($counts);
			foreach($counts AS $pnum=>$val) $counts[$pnum] = $val.' ' .safe_val($stops, $pnum);
			
			//go through list of stops and find any stop not in the counts
			//all stop records of a given type
			$missing = array();
			foreach($stops AS $pnum => $stop) if (!isset($counts[$pnum])) $missing[$pnum] = $stop;
			
			qpre(count($missing), $missing, count($sel), count($counts), $counts);
		
		}
		break;
		
	
	case (10):
		//used to run isolated shortest path test for specific nodes
		$data = file_get_contents($links_path, FILE_BINARY);
		$links = clb_blob_dec($data);	
// UCRWTGQ +|+ SQOIZXN +|+ 8LUYZJMF +|+ 8ARPUPCN +|+ 51.405979817868, -0.67608986875607, 51.392030059687, -0.63289401448655
// SQOIZXN +|+ OZLVBAK +|+ 8ARPUPCN +|+ 8ARPUPCN +|+ 51.405979817868, -0.67608986875607, 51.392030059687, -0.63289401448655
// OZLVBAK +|+ INBGMEM +|+ 8ARPUPCN +|+ 8ARPUPCN +|+ 51.405979817868, -0.67608986875607, 51.392030059687, -0.63289401448655
// INBGMEM +|+ VNOYWKD +|+ 8ARPUPCN +|+ 8HLZZLKD +|+ 51.405979817868, -0.67608986875607, 51.392030059687, -0.63289401448655
// VNOYWKD +|+ SFTHVFF +|+ 8HLZZLKD +|+ 8ELUJDKV +|+ 51.405979817868, -0.67608986875607, 51.392030059687, -0.63289401448655
// SFTHVFF +|+ VNOYWKD +|+ 8ELUJDKV +|+ 8HLZZLKD +|+ 51.405979817868, -0.67608986875607, 51.392030059687, -0.63289401448655
// VNOYWKD +|+ INBGMEM +|+ 8HLZZLKD +|+ 8ARPUPCN +|+ 51.405979817868, -0.67608986875607, 51.392030059687, -0.63289401448655
// INBGMEM +|+ OZLVBAK +|+ 8ARPUPCN +|+ 8ARPUPCN +|+ 51.405979817868, -0.67608986875607, 51.392030059687, -0.63289401448655
// OZLVBAK +|+ SQOIZXN +|+ 8ARPUPCN +|+ 8ARPUPCN +|+ 51.405979817868, -0.67608986875607, 51.392030059687, -0.63289401448655
// SQOIZXN +|+ UCRWTGQ +|+ 8ARPUPCN +|+ 8LUYZJMF +|+ 51.405979817868, -0.67608986875607, 51.392030059687, -0.63289401448655

		$answers = raw_path('8LUYZJMF', '8ARPUPCN', $links, array( 51.405979817868, -0.67608986875607, 51.392030059687, -0.63289401448655));
		qlog(__LINE__, $answers);
	
		$best = reset($answers);
		$data = file_get_contents($map_path, FILE_BINARY);
		$stopsegs = clb_blob_dec($data);	
// 		$data = splice_links($best, 'LTNDIFO', 'MDDEVXH', $stopsegs);
// 		qlog(__LINE__, $data);
		
		break;
		
		
	case (11):
		//arrays connecting nodes to raw segs
		$data = file_get_contents($map_path, FILE_BINARY);
		$stopsegs = clb_blob_dec($data);
		
		$dupes = array();
		foreach($stopsegs['stoppnums'] AS $key=>$stoppnum) {
			if (in_array($stoppnum, $dupes)) continue;
			$times = array_keys($stopsegs['stoppnums'], $stoppnum);
			if (count($times) >1) $dupes[] = $stoppnum;
		}
		echo join(', ',$dupes);
		break;
	case (12):
		$pnum1 = 'QNUBNAP';
		$pnum2 = 'RABITVK';
		
		$set = array();
		$set['modified'] = 'NOW()';
		$set['name'] = clb_escape('<>');
		$set['ptype'] = clb_escape('rail');
		$set['pnum'] = clb_escape(new_pnum('links'));
		$set['created'] = 'NOW()';
		$set['end1'] = clb_escape($pnum1);
		$set['end2'] = clb_escape($pnum2);
		$query = 'INSERT INTO links SET '.clb_join($set,'"',',','=');
		do_query($query, __FILE__, __LINE__);
		qpre($set);
	
	case ('test'):
		//arrays connecting nodes to raw segs
		$data = file_get_contents($map_path, FILE_BINARY);
		$stopsegs = clb_blob_dec($data);
		
		$best = array('8OPGJHWH');
		$aoe_data = splice_links($best, 'IEIQTRQ', 'DAKSXUO', $stopsegs);
		
		$polyline = make_polyline($aoe_data, array('color'=>'#0000FF'));
		qpre($aoe_data);
		
		//remove any unused points from the points list and save as a structure
		$temp = $aoe_data;
		$aoe_data = array();
		foreach($temp as $i=>$pt) if (isset($pt['gran'])) {
			$aoe_data[] = $pt;
			qlog(__LINE__, $i);
		} else {
			qlog(__LINE__, $i);											
		}

		break;
		
	case ('metaphone'):
		$name = 'cambridge';
		$name = unify_names($name);
		$name = metaphone($name);
	qpre($name);
	$name = metaphone('(wales)');
	qpre($name);
	
	$path = __FILE__;
	$folder = '/Users/tobylewis/Sites/buses_sched/temp/';	//if local use a folde that can be written to
	$folder =  (is_dir($folder) ? $folder.'file' : $path);	//otherwise use same folder as the p2p files
	$path = clb_dir(dirname($folder)).'metaphone.txt';
	
		
		$data = file_get_contents($path, FILE_BINARY);	//need different p2p files for different combinations of modes
		$index = clb_blob_dec($data);	
		ksort($index);
		qpre($index);

}
	


if (IS_CLI) {
	db_close();
	exit();
	
} else {
	if ($path) {
		clb_timing(__LINE__);

		$data = file_get_contents($path, FILE_BINARY);
		clb_timing('load');
		$map = clb_blob_dec($data);
		qpre(clb_timing('decode'), 'count', count($map,1));

		qpre('file content length/array', $path, strlen($data), $map);
	}
}
db_close();

$base = clb_make_url().'?m=';

echo clb_tag('p','',clb_tag('a','near','',array('href'=>$base.'near')));
echo clb_tag('p','',clb_tag('a','raw','',array('href'=>$base.'raw')));
echo clb_tag('p','',clb_tag('a','stops','',array('href'=>$base.'stops')));
echo clb_tag('p','',clb_tag('a','links','',array('href'=>$base.'links')));
echo clb_tag('p','',clb_tag('a','p2p','',array('href'=>$base.'p2p')));
echo clb_tag('p','',clb_tag('a','interchange','',array('href'=>$base.'interchange')));
echo clb_tag('p','',clb_tag('a','check_raw_ends','',array('href'=>$base.'7')));
echo clb_tag('p','',clb_tag('a','hardcoded test case','',array('href'=>$base.'8')));
echo clb_tag('p','',clb_tag('a','audit of tyube stops','',array('href'=>$base.'9')));
echo clb_tag('p','',clb_tag('a','hardcoded tests for specific short paths','',array('href'=>$base.'10')));
echo clb_tag('p','',clb_tag('a','arrays connecting nodes to raw segs','',array('href'=>$base.'11')));
echo clb_tag('p','',clb_tag('a','hardcoded insert of link record','',array('href'=>$base.'12')));
echo clb_tag('p','',clb_tag('a','hardcoded test','',array('href'=>$base.'test')));
?>
<pre>
/usr/local/php5/bin/php -f ~/Sites/bristolstreets/admin/pathinspector.php
</pre>