<?php
/*

	possible optimisation is to make links from each major london station to the others to 
	simplify the cross london mapping and can then swap in pre created routes
	
	in current route lists, Shotton and Shotton High Level have been merged because they are so close
	that the code could not tell which was on which line. P_BXJNWCJ and P_ARGEBNT
	
	processing links
	
	/usr/local/php5/bin/php -f ~/Sites/bristolstreets/admin/pathinspector.php
	 1 - create mapping between stops and segments - must be recompiled after any data changes in places or routes
	 2 - load raw links into file - must be recompiled after changes in links
	 3 - load stops rnum/pnum cross reference (gets data from routes) - recompile after changes in routes or stop lists
	 4 - create node to node links - recompile after changes in stops, routes or links
	 5 - load p2p links into file - recompile after changes in stops, routes or links
	 6 - test run in browser

	treat tube and rail branches as separate sub routes.  give routes a common name with an "_" and then some sub identifier for the branch
	this allows the system to signal possible changes and gives the user useful information about stages
	

*/
DEFINE('PT_PRECISION', 6);	//precision on points, 6 digits is enough for roads


function pbuild_del_rec($table, $where)
{
	global $wpdb;
	$wpdb->query('DELETE FROM '.$table.' WHERE '.$where);
}

function pbuild_new_pnum($table, $fld='pnum')
{
	global $wpdb;
	global $editor_tables;
	$code = clb_val(FALSE, $editor_tables, $table, 'prefix');
	if (!$code) return FALSE;
	$pnum = '';
	while (!$pnum || $wpdb->query('SELECT `'.$fld.'` FROM `'.$table.'` WHERE '.$fld.'="'.$pnum.'"'))
	{
		$pnum = strtoupper($code.'_'.substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890'),0,7));
	}
	return $pnum;
}


/*
	to handle multi platform stations places of ptype 'plat' are added directly on the different line segments
	in route finding they work just like stations, but any station with multiple platforms will be converted
	into platforms (the whole set) prior to route finding and then any platforms in the result mapped back to the station
	
	this method makes the bi directional mappings
*/
function pbuild_platforms()
{
	global $wpdb;
	
	$plat2stat = array();
	$stat2plat = array();
	$query = 'SELECT '.RF_NODES_KEY.','.RF_NODES_DESC.' FROM '.RF_NODES_FROM.' WHERE '.RF_NODES_TYPE.'='.clb_escape('plat');
	$sel = $wpdb->get_results($query, ARRAY_A);
	if (is_array($sel)) foreach($sel AS $rec)
	{
		$plat = clb_val(FALSE, $rec, RF_NODES_KEY);
		$stat = clb_val(FALSE, $rec, RF_NODES_DESC);
		if ($stat)
		{
			$plat2stat[$plat] = $stat;	//platforms can only belong to a single station
			$stat2plat[$stat][] = $plat;	//but a station can have many platfroms
		}
	}
	return array('plat2stat' => $plat2stat, 'stat2plat' => $stat2plat);
}



/*
	raw segment ends need to have exact matching end coordinates in order to match in route finding
	this method checks that all ends are correctly rounded to 5 decimal places
	this was written mainly to handle existing records, but could be a useful check in future as mapedit now does rounding during input
*/
function pbuild_check_ends($ptype)
{
	global $wpdb;
	
	//ensures segment end codes are from coordinates rounded to 5 digits exactly.
	if (!is_array($ptypes)) $ptypes = array($ptypes);
	$ok = 0;
	$query = 'SELECT '.RF_LINKS_SELECT.' FROM '.RF_LINKS_FROM.' WHERE ptype IN '.clb_join($ptypes, TRUE);
	$sel = $wpdb->get_results($query, ARRAY_A);
	
	if (is_array($sel))  foreach($sel AS $pnum => $row)
	{
		$pnum = clb_val('', $row, RF_LINKS_KEY);
		$old1 = clb_val('', $row, RF_LINKS_END1);
		$old2 = clb_val('', $row, RF_LINKS_END2);
		$points = pline_pts_arr(clb_val('', $row,RF_LINKS_POINTS));
		if (is_array($points))
		{
			$query = '';
			$pt = reset($points);
			$end1 = clb_b64e(round($pt[0], PT_PRECISION)).clb_b64e(round($pt[1], PT_PRECISION));
			$query .= ', '.RF_LINKS_END1.'='.clb_escape($end1);
			$pt = end($points);
			$end2 = clb_b64e(round($pt[0], PT_PRECISION)).clb_b64e(round($pt[1], PT_PRECISION));
			$query .= ', '.RF_LINKS_END2.'='.clb_escape($end2);
			if (($old1 == $end1) && ($old2==$end2))
			{
				//qlog('same ', $pnum);
				$ok++;
			}
			else
			{
				qlog('change ', $pnum, ($old1 == $end1), $old1, $end1, ($old2 == $end2), $old2, $end2);
			}
		}
	}
	qlog('unchanged', $ok);
}


/*
	create a list of the nearest segment to each stop, indicating the nearest prior point 
	with the segment and the coordinate on the segment where a cut needs to be made.
	
	$mtype - the stop marker ptype
	$stype - the segment ptype 
	$path - a path on which to save the resulting array
	
	the output array has two index arrays to allow array_search() for 'stoppnums' or 'segpnums' and a 'data' array for all other fields
	
	$map = array(
		'stoppnums' => array(), 
		'segpnums' => array(), 
		'data' => array(
			'pos' => number of points in segment before the tengent point for this stop
			'lat' => tangent point on the segment
			'lng' => tangent point on the segment
			'title' => stop name, mainly for debugging
			
		)
	);
	
	$key is the numerical position that links the two index arrays with the data array
	there will be potentially be duplicate pnums in the both index because there can 
	be multiple stops on a segment, and stops can be near to multiple segments like cross routes 
	
	$map['stoppnums'][$key] = pnum	//stop pnum
	$map['segpnums'][$key] = pnum 	//link pnum
	$map['data'][$key]['pos'] = point in the list of points 
	$map['data'][$key]['lat/lng'] = coordinates 
	$map['data'][$key]['dist1/2'] = distance from end 1 to tanges and from end 2 to tangent
	$map['data'][$key]['title'] = to help see what is going on 
	
	use example:
	$found = array_keys($map['stoppnum'], $pnum);	//could be more than one
	foreach($found AS $key) 
	{
		$station = $map['stoppnums'][$key];
		$seg = $map['segpnums'][$key];
		$lat = $map['data'][$key]['lat'];
	}
*/
function pbuild_stop2segs($mtype, $stype, $path='')
{
	global $wpdb;

	if (!is_array($mtype)) $mtype = array($mtype);
	if (!is_array($stype)) $stype = array($stype);
	
	$tolerance = 20 * 0.00001;	//0.00001 happens to equate to 1 screen pixel at zoom level 17
	if (in_array('rail', $mtype) || in_array('tube', $mtype)) $tolerance *= 4;	//allow railways to be further from the railway line

	$map = array(
		'stoppnums' => array(), 
		'segpnums' => array(), 
		'data' => array()
	);
	$unique = array();	//just going to use this to count unique stop pnums
	
	$query = 'SELECT '.RF_LINKS_SELECT.','.RF_LINKS_POINTS.' FROM '.RF_LINKS_FROM.' WHERE '.RF_LINKS_TYPE.' IN '.clb_join($stype, TRUE);
	$segs = $wpdb->get_results($query, ARRAY_A);
	
	$query = 'SELECT '.RF_NODES_SELECT.' FROM '.RF_NODES_FROM.' WHERE '.RF_NODES_TYPE.' IN '.clb_join($mtype, TRUE);
	$stops = $wpdb->get_results($query, ARRAY_A);
	
	$max_segs = count($segs);
	$max_stops = count($stops);

	foreach($segs AS $seg_no=>$seg)
	{
		//qlog(__LINE__, $seg_no, $max_segs, $max_stops, count($map['stoppnums']), count($unique));
		
		if (($seg_no % round($max_segs/100)) == 0) echo round(100*($seg_no/$max_segs)).'% complete'."\n";
		
		$travel = 0;	//distance along segment
		$full_len = $seg[RF_LINKS_DIST];	//use difference to find distance to other end from any point
		
		$found_stops = array();
		$points = pline_pts_arr($seg[RF_LINKS_POINTS]);
		if (clb_count($points)) foreach($points as $no => $pt)
		{	
			$pt[0] = round($pt[0], PT_PRECISION);
			$pt[1] = round($pt[1], PT_PRECISION);
			if ($no == 0)	//skipping first
			{	
				$prev = $pt;
				continue;
			}
			
			//these are used later when calculating distance along segment for each piont
			$lat2 = deg2rad($prev[0]);
			$lng2 = deg2rad($prev[1]);
			
			
			$adjust = cos(deg2rad(($pt[0] + $prev[0])/2));	//using the average latitude to work out scaling on longitude
			$dlat = ($pt[0] - $prev[0]);
			$dlng = ($pt[1] - $prev[1]) * $adjust;
			$alpha = atan2($dlat, $dlng);

							
			//these will test distances from segment and adjusted point to make sure on correct side of segment
			$span = array(0, 0, $dlat, $dlng);	//0 = y, 1 = x
			
			$bounds = array();
			$bounds['top'] = max($pt[0], $prev[0]) + $tolerance;
			$bounds['bot'] = min($pt[0], $prev[0]) - $tolerance;
			$bounds['lft'] = min($pt[1], $prev[1]) - $tolerance;
			$bounds['rgt'] = max($pt[1], $prev[1]) + $tolerance;
			
			foreach($stops AS $stop)	//all stop records of a given type
			{
				//check if point within bounding box around segment
				if ($stop['lat'] > $bounds['top']) continue;
				if ($stop['lat'] < $bounds['bot']) continue;
				if ($stop['lng'] > $bounds['rgt']) continue;
				if ($stop['lng'] < $bounds['lft']) continue;
// qlog(__LINE__, $bounds['lft'], $stop['lng'], $bounds['rgt'], $bounds['bot'], $stop['lat'], $bounds['top']);					
				
				$stop_y = ($stop['lat'] - $prev[0]);
				$stop_x = ($stop['lng'] - $prev[1]) * $adjust;	//adjust origin of stop to get relative position to beginning of segment
				
				//check that stop is within tollerance range of our line (extended infinitely)
				$dist = pline_normal($span, $stop_y,  $stop_x);
// qlog(__LINE__, $no, $dist, $tolerance);

				if ($dist > $tolerance) continue;
				
				$key = count($map['stoppnums']);
				//if we have seen this seg before, reuse key to overwrite previous if previous was off end of line (limit=1) or dont overwrite if fully within prev seg
				if (isset($found_stops[$stop[RF_NODES_KEY]]))
				{
					if (!$found_stops[$stop[RF_NODES_KEY]]['limit']) continue;
					$key = $found_stops[$stop[RF_NODES_KEY]]['key'];	//reuse old key value to replace old record
				}
									
				$data = array();
				$data['pos'] = $no;	//the number of points prior to this one (this point is after but since we count from 0 this is number before)
				$data['ptype'] = $stop[RF_NODES_TYPE];
				
				
				//now need coordinates for point on seg where stop/station is closest
				//handle special cases of horizontal or vertical line first
				if ($dlat == 0)	//east west segment, just take stop lng
				{
					$data['lat'] = $pt[0];
					$data['lng'] = $stop['lng'];
					//if the point is off the end of the segment restrain coord and indicate we have limited
					if ($pt[1] > $prev[1])
					{
						if ($stop['lng'] < $pt[1]) $data['lng'] = $pt[1];
						if ($stop['lng'] > $prev[1]) $data['lng'] = $prev[1];
					}
					else	//($pt[1] <= $prev[1])
					{
						if ($stop['lng'] > $pt[1]) $data['lng'] = $pt[1];
						if ($stop['lng'] < $prev[1]) $data['lng'] = $prev[1];
					
					}
					if ($data['lng'] != $stop['lng']) $limit = 1;
					
				}
				else if ($dlng == 0)	//north south, just take stop lat
				{
					$data['lat'] = $stop['lat'];
					$data['lng'] = $pt[1];
					//if the point is off the end of the segment restrain coord and indicate we have limited
					if ($pt[0] > $prev[0]) {
						if ($stop['lat'] < $pt[0]) $data['lat'] = $pt[0];
						if ($stop['lat'] > $prev[0]) $data['lat'] = $prev[0];
					} else {	//($pt[0] < $prev[0])
						if ($stop['lat'] > $pt[0]) $data['lat'] = $pt[0];
						if ($stop['lat'] < $prev[0]) $data['lat'] = $prev[0];
					
					}
					if ($data['lat'] != $stop['lat']) $limit = 1;
					
				}
				else	//use algebra to work out intersection of segment and perpendicular vector going through station point.
				{
					/*
						equation for main segment, no constant because it starts at origin
						y_seg = (dlat/dlng) * x_seg 	
						
						equation for perpendicular line, note slope is negative inverse of main seg, intercept c is unknown
						y_perp = (-dlng/dlat) * x_perp + c
						
						plug station point into perpendicular and solve for c
						c = y_stat + (dlng/dlat) * x_stat
						
						set equations for segment and perpendicular equal to each other (ie y_seg = y_perp and x_seg = x_perp) so
						(dlat/dlng) * x_seg = (-dlng/dlat) * x_perp + c
						(dlat/dlng) * x = (-dlng/dlat) * x + c
						
						solve for x
						x = c / ((dlat/dlng) + (dlng/dlat))
						
						plug x into original segment equation for final position
					*/
					
					$c = $stop_y + (($dlng/$dlat) * $stop_x);	//plug in stop coords to get intercept of perpendicular line
					$x = $c / (($dlat/$dlng) + ($dlng/$dlat));	//set formular for original segment and perpendicular to have euql y values and solve for x
					$y = ($dlat/$dlng) * $x;
					
					//normally the point just found will be between the end points of the current line, 
					//but can be past the beginning or end, so need to check distsances
					$dist_line = sqrt(pow($dlat, 2) + pow($dlng, 2));	//length of the line
					
					$limit = 0;
					if ($dist_line < sqrt(pow($y, 2) + pow($x, 2)))	//if length point from origin is greater than line itself
					{
						$data['lat'] = $pt[0];	//use the end point of the line as the station point
						$data['lng'] = $pt[1];
						$limit = 1;	//shows we limited the position to the end of the line
					}
					else if ($dist_line < sqrt(pow($y - $dlat, 2) + pow($x - $dlng, 2)))
					{
						//if distance from point to end point of line is longer than line
						$data['pos'] = $no-1;	//do not include previous point
						$data['lat'] = $prev[0];	//use the start point of the line as the station point
						$data['lng'] = $prev[1];
						$limit = 1;	//shows we limited the position to the end of the line
					}
					else
					{
						$data['lat'] = round($prev[0] + $y, PT_PRECISION);
						$data['lng'] = round($prev[1] + ($x / $adjust), PT_PRECISION);	//need to scale the longitude result
					}
					
					/*
						sum of angles, to subtract angles reverse all +/- on both sides
						sin(a + b) = sin(a) * cos(b) + cos(a) * sin(b)
						cos(a + b) = cos(a) * cos(b) - sin(a) * sin(b)	//note reversal of sign on right
						tan(a + b) = (tan(a) + tan(b)) / (1 - tan(a) * tan(b))
						cot(a + b) = (cot(a) * cot(b) - 1) / (tan(b) + tan(a))
						
						we will use the sin of difference of angles
					*/
					$beta = atan2($y, $x);
					//if this is negative the second vector was anti clockwise (on left) of the first vector
					$sin = asin((sin($alpha) * cos($beta)) - (cos($alpha) * sin($beta)));
					$data['left'] = (($sin <= 0) ? 1 : 0);	//on left if negative
					
				}
				//get the length of the cord from the stop and the cut point on the line segment
				$data['near'] = round(pline_surface_dist($stop['lat'], $stop['lng'], $data['lat'], $data['lng']), PT_PRECISION);
				
				//calculate distance from this piont to the beginning and end of the segment
				$lat1 = deg2rad($pt[0]);
				$lng1 = deg2rad($pt[1]);
				$data['dist1'] = round($travel+abs(acos(sin($lat1)*sin($lat2)+cos($lat1)*cos($lat2)* cos($lng2-$lng1)) * EARTH_RADIUS_M), 3);
				$data['dist2'] = round($full_len - $data['dist1'], 3);

				$found_stops[$stop[RF_NODES_KEY]] = array('limit'=>$limit, 'key'=>$key);	//prevent same stop getting added to same seg more than once
				$map['stoppnums'][$key] = $stop[RF_NODES_KEY];
				$map['segpnums'][$key] = $seg[RF_LINKS_KEY];
				$map['data'][$key] = $data;					
				
				$unique[$stop[RF_NODES_KEY]] = TRUE;	//just to count stops
			}	//scan of stops
			
			//calc the distance from this point to the previous one IN METERS
			$lat1 = deg2rad($pt[0]);
			$lng1 = deg2rad($pt[1]);
			$travel += abs(acos(sin($lat1)*sin($lat2)+cos($lat1)*cos($lat2)* cos($lng2-$lng1)) * EARTH_RADIUS_M);
			
			$prev = $pt;
		}	//points in seg / reg exp points
	}	//segments
	
	if ($path && is_dir(dirname($path)))
	{
		$blob = clb_blob_enc($map, TRUE); //TRUE=binary
		file_put_contents($path, $blob);	//, FILE_BINARY);
	}
	
	//all stop records of a given type
	$missing = array();
	foreach($stops AS $stop) if (!isset($unique[$stop[RF_NODES_KEY]])) $missing[] = $stop[RF_NODES_KEY];
	
	qpre(__LINE__, 'nodes that did not appear near lines', count($missing), count($stops), join(', ', $missing));
	
	return $map;
}


/*
	primitive segments use the base64 encoding of their end point coordinates as their end codes
	segments with common end points will have the same end codes.
	
	$primitives = array(
		'nodes' => array(endcode => array(linkpnums)),
		'links' => array(pnum => array(dist, end1, end2, pt1, pt2, ptype, angle1, angle2);
	);
	
	$code = 'yxkuH|ehF';
	$pnums = clb_val(FALSE, $primitives, 'nodes', $code);
	if ($pnums) foreach($pnums AS $pnum) 
	{
		$data = $primitives['links'][$pnum];
	}

*/
function pbuild_primitives($stype, $path='')
{
	global $wpdb;
	
	if (!is_array($stype)) $stype = array($stype);
	
	$primitives = array();

	$query = 'SELECT '.RF_LINKS_SELECT.','.RF_LINKS_POINTS.' FROM '.RF_LINKS_FROM.' WHERE '.RF_LINKS_TYPE.' IN '.clb_join($stype, TRUE);
	$segs = $wpdb->get_results($query, ARRAY_A);
	
	$max_segs = count($segs);
	
	foreach($segs AS $seg_no=>$seg)
	{
		if (($seg_no % round($max_segs/10)) == 0) echo round(100*($seg_no/$max_segs)).'% complete'."\n";
			
		$points = pline_pts_arr($seg[RF_LINKS_POINTS]);
		if (clb_count($points)>=2)	//need two points to make a line, and only interested in first and last
		{
			$data = array();
			$pnum = $seg[RF_LINKS_KEY];
			$data['dist'] = $seg[RF_LINKS_DIST];
			$data['end1'] = $end1 = $seg[RF_LINKS_END1];	//these are base64 points
			$data['end2'] = $end2 = $seg[RF_LINKS_END2];
			$data['ptype'] = $seg[RF_LINKS_TYPE];
			$data['reverse'] = $seg[RF_LINKS_REVERSE];
			
			$a = reset($points);
			$b = next($points);
			$data['pt1'] = $a;	//remember the first point so we can do distance checking
			
			$adjust = cos(deg2rad($a[0]));	//using first latitude to work out scaling on longitude as difference between ends will be trivial

			if ((($b[0] - $a[0]) ==0)  && (($b[1] - $a[1]) == 0)) qpre(__FUNCTION__, __LINE__, 'segment with double start point', $seg[RF_LINKS_KEY]);
			
			$data['angle1'] = round(atan2(($b[0] - $a[0]), (($b[1] - $a[1]) * $adjust)), 3);
			
			//repeat for other end
			
			$a = end($points);
			$b = prev($points);
			$data['pt2'] = $a;	//remember the first point so we can do distance checking
			
			$adjust = cos(deg2rad($a[0]));	//using the average latitude to work out scaling on longitude
			
			if ((($b[0] - $a[0]) ==0)  && (($b[1] - $a[1]) == 0)) qpre(__FUNCTION__, __LINE__, 'segment with double end point', $seg[RF_LINKS_KEY]);
			
			$data['angle2'] = round(atan2(($b[0] - $a[0]), (($b[1] - $a[1]) * $adjust)), 3);

			$primitives['nodes'][$end1][] = $pnum;
			$primitives['nodes'][$end2][] = $pnum;
			$primitives['links'][$pnum] = $data;
		}
		else
		{
			qpre(__FUNCTION__, __LINE__, 'segment with 1 or fewer points', $seg[RF_LINKS_KEY], $seg[RF_LINKS_POINTS]);
		}
		//qlog(__LINE__, $seg_no, clb_count($points));
	}
	
	if ($path && is_dir(dirname($path)))
	{
		$blob = clb_blob_enc($primitives, TRUE); //TRUE=binary
		file_put_contents($path, $blob);	//, FILE_BINARY);
	}
	return $primitives;
}


/*
	given an array of route rnums, get stops list from route records and daisy chain primitive 
	links into station to station links.  the new links will have the ptype of the markers they connect
	
	the original segment point lists will be used and a new polyline will be generated and stored in "line"
	the  points field will hold a serialised version of the point data which will include granularity and diff (the base64 pair) info.
	this array can then be used to quickly make new polylines in either direction just by looping through these arrays
	
	$rnums - array of rnum(s) of routes
	$primitives - links array as created by pbuild_primitives() and ptype of segments was chosen when populating $primitives
	$stopsegs - array matching stops to primitive segments pbuild_stop2segs()
		
*/
function pbuild_daisychain($rnums, $primitives, $stopsegs)
{
	global $wpdb;
	
	if (!is_array($rnums)) $rnums = array($rnums);
	$now = time();
	$processed = clb_now_utc($now);
	$links_count = 0;
	
	//platforms mapped to stations with converging lines
	$plats = pbuild_platforms();
	$stat2plat = $plats['stat2plat'];
	$plat2stat = $plats['plat2stat'];
	
	//select route records that list stops rather than segments
	$query = 'SELECT '.RF_ROUTES_SELECT.','.RF_ROUTES_STOPS.','.RF_ROUTES_SBACK.' FROM '.RF_ROUTES_FROM.' WHERE '.RF_ROUTES_KEY.' IN '.clb_join($rnums, TRUE);
	$routes = $wpdb->get_results($query, ARRAY_A);
	
	if (is_array($routes))
	{
		$max_routes = count($routes);
		foreach($routes AS $rt_no=>$route)
		{
			if (($rt_no % round($max_routes/100)) == 0) echo round(100*($rt_no/$max_routes)).'% complete'."\n";
			
			$rnum = clb_val(FALSE, $route, RF_ROUTES_KEY);
			$ptype = clb_val(FALSE, $route, RF_ROUTES_TYPE);
			if (!$ptype)
			{
				qlog(__FUNCTION__, __LINE__, 'route did not have ptype which is necessary to give the result links types', $route);
				continue;
			}
			
			//assume a route is reversable unless it is blocked (by '*' in the field making it not 
			//empty but not listing anythign) or explicitly lists the reverse version
			if (empty($route[RF_ROUTES_SBACK]))
			{
				$lines = preg_split('/[\r\n]+/', $route[RF_ROUTES_STOPS]);
				$route[RF_ROUTES_SBACK] = join("\n", array_reverse($lines))."\n";
			}
			
			//scanning directions separately
			foreach(array(RF_ROUTES_STOPS=>'', RF_ROUTES_SBACK=>'.') AS $dir=>$dot)
			{
				$last_comment = '';
				
				//split out stop pnums from the segs list on the route record
				if (preg_match_all('/^([\w<>]+)(\S*\s(.*))?$/m', $route[$dir], $lines, PREG_SET_ORDER))
				{
					$nodes1 = FALSE;
					$max_segs = count($lines);
					qlog(__FUNCTION__, __LINE__, $max_routes, $rt_no, $max_segs, $rnum);
					
					//loop thorugh each stop/station pnum just extracted from stops_list / stops_back
					foreach($lines AS $ln_no=>$stop)
					{
						$nodes2 = clb_val(FALSE, $stop, 1);	//get stop pnum from the regular expression above
						if (preg_match('/<\w+>/', $nodes2))	//any string betweeen <> like "<break>"
						{
							//this is a section breaker, this stops the previous and next stops from getting a connecting link
							//at 09/09/09 there were no routes using this feature.
							$nodes1 = $nodes2 = FALSE;
							continue;
						}
						
						/*
							node2 is the stop/station pnum just extracted from the list above
							if the station has plaforms we will make nodes2 an array of plafroms
							but if not we just wrap the station pnum in an array
						*/
						$nodes2 = (isset($stat2plat[$nodes2]) ? $stat2plat[$nodes2] :  array($nodes2));
						
						//get the comments after the pnum and look for a time in minutes
						$comment = clb_val(FALSE, $stop, 3);
						$mins = FALSE;	//get time to travel path as minutes from the end of the comment which will end "*22" 
						if (preg_match('/\*(\d+)$/', $comment, $parts)) $mins = $parts[1];
						$comment = preg_replace('/\s+\*(\d+)$/','',$comment);
						
						/*
							$segs1/2 will hold an array of segment pnums that are near to the station or platforms in $node1/2
							the key of each $segs2 element is also the key to the data for that segment/node intersection in $stopsegs
							ie $segs1[stop_pnum][$k] = seg_pnum, $stopsegs['data'][$k] == segment data
						*/
						$segs2 = array();	
						foreach($nodes2 AS $k=>$pnum2)
						{
							$keys = array_keys($stopsegs['stoppnums'], $pnum2);
							
							if (!count($keys))
							{
								qlog(__FUNCTION__, __LINE__, 'stop not found in segmap: ', $pnum2);
								//assume this is a garbage line and remove from array as it will cause more errors later if kept
								unset($nodes2[$k]);
								continue;
							}
							else	//normally there will only be one match, but sometimes a station will be on two lines and we need to do separate searches
							{
								foreach($keys AS $key) $segs2[$pnum2][$key] = $stopsegs['segpnums'][$key];
							}
						}
						
						//when we have two stops (ie after first loop) we need to find/make a link
						$links_made = 0;	//since matching to any given platform may legitmately fail, count total links within loop and give err after if still 0
						$dist = 0;
						$linkrec = FALSE;
						
						if ($nodes1 != FALSE)
						{
							$set_set = array();	//in case we get more than one path because of multiple platforms accumulate them in an array first
							$best_dist = FALSE;	//will track the best distance on a set
							
							//producting platforms but this will usually be only one node in each array
							foreach($nodes1 AS $pnum1) foreach($nodes2 AS $pnum2)
							{
								//see if we already have such a link and update it if necessary
								$linkpnum = FALSE;
								$process = TRUE;
								$use_angles = TRUE;
								$forward = TRUE;
								$set = array();
								$query = 'SELECT '.RF_LINKS_SELECT.','.RF_LINKS_POINTS.' FROM '.RF_LINKS_FROM.' WHERE '.RF_LINKS_TYPE.'= '.clb_escape($ptype);
								$query .= ' AND (('.RF_LINKS_END1.'='.clb_escape($pnum2).' AND '.RF_LINKS_END2.'='.clb_escape($pnum1).') ';
								$query .= 'OR ('.RF_LINKS_END1.'='.clb_escape($pnum1).' AND '.RF_LINKS_END2.'='.clb_escape($pnum2).'))';
								$linkrec = $wpdb->get_results($query, ARRAY_A);//should only be one
								
								if (clb_count($linkrec) > 1)
								{
									//bad, dont want multiple links between same nodes
									qlog(__FUNCTION__, __LINE__, 'multiple links for same nodes and mode', $pnum1, $pnum2, array_keys($linkrec));
									do_query('DELETE FROM '.RF_LINKS_FROM.' WHERE pnum IN '.clb_join(array_keys($linkrec), TRUE), __FILE__, __LINE__);	//delete them all and start again
									$wpdb->query($query);
									$linkrec = FALSE;
								} 
								
								if (is_array($linkrec))	// a single existing record
								{
									//links requiring reversing need to have "<>" added to the title field of the generated link
									// <>Newark Castle - Newark North Gate - this is across a junction not from a terminus
									// <>Liskeard- St Keyne - backs out of a terminus before splitting off at next junction

									$linkrec = reset($linkrec);
									$set[RF_LINKS_KEY] = $linkrec[RF_LINKS_KEY];
									$set[RF_LINKS_DIST] = $linkrec[RF_LINKS_DIST];
									$use_angles = !preg_match('/^<>/',$linkrec[RF_LINKS_NAME]);	//hand altered links that have <> at the beginning of the name do not require angles to match as trains must reverse
									$process = (clb_get_stamp(clb_val(FALSE, $linkrec, RF_LINKS_MODIFIED)) < $now);	//0 if fails
									
									/*
										reverse value of 1 means not in reverse direction, 2 means not in forward direction
										If the record has not been processed in this session, we start by blocking the other direction
										but if we end up processing it in the other direction too, we will unblock it.
									*/
									$end1 = clb_val(FALSE, $linkrec, RF_LINKS_END1);
									$forward = ($end1 == $pnum1);	//direction we are going this time
									$reverse = clb_val(FALSE, $linkrec, RF_LINKS_REVERSE);	//the existing reverse value on the link
									if ($process)
									{
										$set[RF_LINKS_REVERSE] = ($forward ? 1 : 2);	//first run, block the other direction
									}
									else if (($reverse == 1) && (!$forward))	//reverse blocked but this is reverse
									{
										$set[RF_LINKS_REVERSE] = 0;	//seen both directions, unblock
									}
									else if (($reverse == 2) && ($forward))	//forward blocked but this is forward
									{
										$set[RF_LINKS_REVERSE] = 0;	//seen both directions, unblock
									} 
								}
								else
								{
									$linkrec = FALSE;
									$set[RF_LINKS_REVERSE] = 1;	//new records are forwards not reverse by definition
								}
								
								
								/*
									the only time we dont process is if this has been processed in this run, 
									such as the reverse direction of same route, or two routes with a common path section
								
									both stations can be on multiple segs (when near junctions or cross roads) so product 
									combinations and accumulate resulting paths but usually only going to get connections 
									on one of the producted list of segments, 
									so if point A on segs 1 & 2 and point B on segs 3 & 4 may only get connection on 1 with 3

								*/
								if ($process)
								{	
									$thru_paths = array();
									/*
										we will look at the closeness of stops to different segments and try the one that the 
										stops are closest to first.  this not only saves time as we dont try to connect unconnected
										segs but prevents stops near junctions getting attached to the one they are not closest too
										$k1 & $k2 are the numerical key values that link the separate parts of $stopsegs
										$n1 & $n2 are the segment pnums on which the $pnum1 and $pnum2 nodes are near.
									*/
									if ((count($segs1[$pnum1])>1) || (count($segs2[$pnum2])>1))
									{
										$c1 = $c2 = FALSE;
										foreach($segs1[$pnum1] AS $k1=>$n1)
										{
											if (isset($stopsegs['data'][$k1]) && (is_bool($c1)
												|| ($stopsegs['data'][$k1]['near'] < $stopsegs['data'][$c1]['near']))) $c1 = $k1;
										}
										
										foreach($segs2[$pnum2] AS $k2=>$n2)
										{
											if (isset($stopsegs['data'][$k2]) && (is_bool($c2) 
												|| ($stopsegs['data'][$k2]['near'] < $stopsegs['data'][$c2]['near']))) $c2 = $k2;
										}
										//	ACTUAL CALL TO SHORTEST PATH: pbuild_shortpath(seg_pnum1, seg_pnum2, seg1_data, seg2_data)
										$thru_paths = pbuild_shortpath($segs1[$pnum1][$c1], $segs2[$pnum2][$c2], $stopsegs['data'][$c1], $stopsegs['data'][$c2], $primitives, $use_angles);			//ACTUAL CALL TO SHORTEST PATH
									}
									
									/*
										if the above managed to find a path from the segments closest to each station then we are done, but if not
										we now need to loop through the possibilities
									*/
									if (count($thru_paths) == 0) foreach($segs1[$pnum1] AS $k1=>$n1) foreach($segs2[$pnum2] AS $k2=>$n2)	//$n1 and $n2 are link pnums
									{
										/*
											because we are going to do shortest path on segments we need to pass in the actual 
											stop end points because they could be on very long segments
											ACTUAL CALL TO SHORTEST PATH: pbuild_shortpath(seg_pnum1, seg_pnum2, seg1_data, seg2_data)
										*/
										$temp = pbuild_shortpath($n1, $n2, $stopsegs['data'][$k1], $stopsegs['data'][$k2], $primitives, $use_angles);			
										if (clb_count($temp)) $thru_paths = array_merge($thru_paths, $temp);	//add results after each loop
									}
									
									if (count($thru_paths) <= 0)	//no connecting paths found
									{
										//if one or both end points was a platform, dont give an error as we expect some platforms to fail
										if (!array_key_exists($pnum1, $plat2stat) && !array_key_exists($pnum2, $plat2stat))
										{
											qlog(__FUNCTION__, __LINE__,'no link path found for',$dir, $pnum1, $pnum2,($use_angles?'angles':'flex'), array_key_exists($pnum1,$plat2stat), array_key_exists($pnum2,$plat2stat) );
										}
										$set = array();	//clear this to prevent record being saved, because the reverse will have been set
									}
									else	//get path with lowest score and turn it into polyline
									{
										/*
											although each call to pbuild_shortpath can at most return one path, multiple lines or platforms means
											there are several calls and can result in more than one path.  Need to scan results to find shortest.
										*/
										$best = FALSE;
										$min = clb_val(FALSE, $thru_paths, 0, 'dist');
										foreach($thru_paths AS $rec) if (clb_val(FALSE, $rec, 'dist') <= $min) $best = clb_val(FALSE, $rec, 'path');
										
										$links_count++;
										//qlog(__FUNCTION__, __LINE__, count($thru_paths), 'path found: ', $pnum1, $pnum2, $links_count, $ln_no, $best);
										
										/*
											if we are processing the path, it is the first pass which is usually the forward pass
											but if one route considers this backwards and another one considers it forwards and the backwards one
											processes first, then need to reverse this result rather than swapping the end points on the record
										*/
										$points = pline_splice($wpdb, $best, $pnum1, $pnum2, $stopsegs);
										if (!$forward) $points = array_reverse($points);
										
										if (clb_count($points))
										{
											if (is_numeric($mins)) $set[RF_LINKS_TIME] = $mins;
											
											if (!$linkrec)	//only set these if new record but do it here so that the pnum values are current loop not endo of loop
											{
												$set[RF_LINKS_END1] = $pnum1;	//may already be set
												$set[RF_LINKS_END2] = $pnum2;
											}
											
											//the polyline is only made for debugging and inspection not for final display
											$polyline = pline_make($points, array('color'=>'#0000FF'));
											$set[RF_LINKS_LINE] = clb_join($polyline,'','&','=');
											
											$dist = clb_val(0, $polyline, 'meters');
											$set[RF_LINKS_DIST] = $dist;
											
											//remove any unused points from the points list and save as a structure
											$temp = $points;
											$points = array();
											foreach($temp as $pt) if (isset($pt['gran'])) $points[] = $pt;

											$set[RF_LINKS_POINTS] = clb_blob_enc($points);
											
											//set the "position" for the segment marker as the mid point
											if ($midpoint = pline_midpoint($points))
											{
												$set['lat'] = $midpoint[0];
												$set['lng'] = $midpoint[1];
												$set['elev'] = $midpoint[2];
											}
											
											//last comment is the previous station/stop name so that this link gets the "A - B" name
											$name = $last_comment.' - '.$comment;
											if (!$use_angles) $name = '<>'.$name;
											$set[RF_LINKS_NAME] = substr($name,0, 70);
										}
									}
								}
								
								
								/*
									we may have more than one set of results if there were multiple routes
									save each set and remember best distance so we can find the best one below
								*/
								if (clb_count($set))
								{
									$set_set[] = $set;
									if (is_bool($best_dist) || ($set[RF_LINKS_DIST] < $best_dist)) $best_dist = $set[RF_LINKS_DIST];	//will track the best distance on a set
								}
							}	//loop producting platforms, only 1 loop if both stations
							
							//now only create best distance link
							$links_made = 0;
							foreach($set_set AS $set)
							{
								/*
									may need to allow more than one connection eg waterloo to clapham junction, some trails take north platform some south
									*** the above is true and important, lines may come in as one but then diverge at a station.  there needs
									to be a line in to each of the platforms so that journeys can continue out from both platforms.
									limit to within 200m of best distance which should include all platforms but wont allow for long alternative routes.
								*/
								if ($set[RF_LINKS_DIST] <= ($best_dist+200))
								{
									$links_made++;
									
									//if the direction is not being set then this signals that nothing else needs saving either
									if (isset($set[RF_LINKS_REVERSE]))
									{
										$set[RF_LINKS_MODIFIED] = $processed;
										$set[RF_LINKS_TYPE] = $ptype;
										if (isset($set[RF_LINKS_KEY]))
										{
											$query = 'UPDATE '.RF_LINKS_FROM.' SET '.clb_join($set,'"',',','=').' WHERE '.RF_LINKS_KEY.'='.clb_escape($set[RF_LINKS_KEY]);
										}
										else
										{
											$set[RF_LINKS_KEY] = pbuild_new_pnum(RF_LINKS_FROM, RF_LINKS_KEY);
											$set[RF_LINKS_CREATED] = $processed;
											$query = 'INSERT INTO '.RF_LINKS_FROM.' SET '.clb_join($set,'"',',','=');
											qlog(__LINE__, 'inserting', count($set_set), $nodes1, $nodes2, $set[RF_LINKS_DIST],  $set[RF_LINKS_END1],  $set[RF_LINKS_END2],  $set[RF_LINKS_KEY]);
										}
										$wpdb->query($query);
									}
									
								}
								else if (isset($set[RF_LINKS_KEY]))
								{
									//this is a saved record that has been bettered so must be removed
									qlog(__FUNCTION__, __LINE__, 'deleting bettered link to a platform', $set[RF_LINKS_KEY], $set[RF_LINKS_DIST], $best_dist);
									pbuild_del_rec(RF_LINKS_FROM, RF_LINKS_KEY.'='.clb_escape($set[RF_LINKS_KEY]));
								}
							}
							
							//if we found an existing link then $linkrec is not false and if links_made >0 then we made one
							if ($links_made <= 0) qpre(__FUNCTION__, __LINE__, '***** no paths found between nodes: ', $rnum, join(', ',$nodes1), join(', ',$nodes2), 'p1 on segs: '.join(', ',$segs1[$pnum1]), 'p2 on segs: '.join(', ',$segs2[$pnum2]), $comment);
							
						}	//have two nodes to link

						//carry values to the next loop
						$segs1 = $segs2;
						$nodes1 = $nodes2;
						$last_comment = $comment;	//carry comment (ie station name) over so next link can get name "this - next"						
					}
				}
			}
		}
	}
}


/*
	shortest path for segments using end coordinates to link together
	
	$seg1, $seg2 - start and end primitive segemnts
	$data1, $data2 - entries from $stopsegs giving the nearest point number and lng/lat of the tangent point
	$primitives - the array of primitive segment ends passed by reference for efficiency not because it will be altered.
	$use_angles - if true ensures angle of two segment end points is within a tollerance, stations that require reversing pass false
	
	$primitives = array(
		'nodes' => array(end_code => array(seg_pnum1, seg_pnum2,...)),
		'links' => array(seg_pnum => array(dist, end_code1, end_code2, ptype, reverse, angle1, angle2)),
	);
	
	shortest path collects traversal info in the $leaves array.  The keys are sequential, 'par' holds the key of the predecessor node
	$leaves[$node]['par'] = parent leave node key (as in the $node not the pnum)
	$leaves[$node]['node'] = pnum
	$leaves[$node]['link'] = pnum
	$leaves[$node]['dist'] = score
	$leaves[$node]['changes'] = value
	
	$queue[$node] = $dist;	//holds a list of unprocessed leave nodes, not strictly a queue as we will asort it and work from the low scores

*/

function pbuild_shortpath($seg1, $seg2, $data1, $data2,  & $primitives, $use_angles=TRUE)
{
	$allowance = 4;	//factor of crow fly distance allowed before abandoning route, if (dist > (allowance * crowfly)) dont follow
	$margin = 1.2;	//factor of the shortest path to stop collecting alternatives.
	$bends = 80;	//bend angles up to 80 degrees
	
//qlog(__FUNCTION__, __LINE__, $node1, $node2, $target);	
	clb_timing(__LINE__);
	
	//check we have entries in the primitives for the start and end segments in the first two params
	if (!isset($primitives['links'][$seg1]))
	{
		qlog(__FUNCTION__, __LINE__, 'raw path not found because end node unknown', $seg1, $seg1.'->'.$seg2);	
		return array();
	}

	if (!isset($primitives['links'][$seg2]))
	{
		qlog(__FUNCTION__, __LINE__, 'raw path not found because end node unknown', $seg2, $seg1.'->'.$seg2);	
		return array();
	}

	//if start and end segments are the same we are done.
	if ($seg1 == $seg2) return array(array('dist'=>0, 'path'=>array($seg1)));
	
	//keep track of actual end point locations which may not be near the end of the start and end segments
	$starter = array($data1['lat'], $data1['lng']);
	$target = array($data2['lat'], $data2['lng']);
	
	//the direct distance gives some indication of reasonable tollarances when evaluating segments
	$crowfly = pline_surface_dist($target[0], $target[1], $starter[0], $starter[1]);
	
	if ($crowfly == 0) return array();	//if no distance from start to end then no path to be found

	$seq = 0;	//key for the $leaves array
	$leaves = array();	//info on nodes visited by shortest path

	//holds info on segments to be evaluated, always pick best ones not first added.
	//key values are 'x'.leaf_key to make them strings and preserve them when sorting by values
	//and values are distance from leaf to target
	$queue = array();
	
	/*
		we don't know which end of the start segment is going to lead to the end segment, so add both ends
		to the queue, so long as the distance to each end is not already beyond the tollerance
	*/
	$pt = $primitives['links'][$seg1]['pt1'];
	$pythag = pline_surface_dist($target[0], $target[1], $pt[0], $pt[1]);	//distance from end point to target
	if (($data1['dist1'] + $pythag) < ($crowfly * $allowance))	//dist from tangent to end and from end to target within tollerance
	{
		$node = $seq++;
		$leaves[$node]['par'] = FALSE;	//root node has no parent
		$leaves[$node]['node'] = $primitives['links'][$seg1]['end1'];
		$leaves[$node]['link'] = $seg1;
		$leaves[$node]['dist'] = $data1['dist1'];
		$leaves[$node]['angle'] = clb_val(FALSE, $primitives,'links', $seg1, 'angle1');
		
		$queue['x'.$node] = round($leaves[$node]['dist'] + $pythag);
	}
	
	$pt = $primitives['links'][$seg1]['pt2'];
	$pythag = pline_surface_dist($target[0], $target[1], $pt[0], $pt[1]);
	if (($data1['dist2'] + $pythag) < ($crowfly * $allowance))	//dist from tangent to end and from end to target within tollerance
	{
		$node = $seq++;
		$leaves[$node]['par'] = FALSE;	//root node has no parent
		$leaves[$node]['node'] = $primitives['links'][$seg1]['end2'];
		$leaves[$node]['link'] = $seg1;
		$leaves[$node]['dist'] = $data1['dist2'];
		$leaves[$node]['angle'] = clb_val(FALSE, $primitives, 'links', $seg1, 'angle2');
		
		$queue['x'.$node] = round($leaves[$node]['dist'] + $pythag);
	}
	
	/*
		if neither segment end was added then both were too long to reach the target within tollerance
		this is not an error as some segments are simply not in the route we are looking for
	*/
	//if ($seq == 0) qlog(__FUNCTION__, __LINE__, 'raw path rejected because ends of first segment beyond tollerances',$seg1, $seg2);	
	if ($seq == 0) return array();
	
	
	$complete = array();	//collects end nodes of one or more "shortest" paths
	$best = FALSE;	//will hold the lowest score of an actual match so we can stop looking after other options get to large to be contenders

	$used = array();	//real shortest path does not backtrack or reuse segments, so once seen add to used list

	while (count($queue))
	{
		asort($queue);	//put lowest score first
//qlog(__LINE__, $crowfly, $queue);

		$dist = reset($queue);	//set first element current
		$leaf = (int) trim(key($queue),'x');	//get the key removing 'x' which makes the keys strings and prevents renumbering on arrays shift.
		array_shift($queue);	//remove item from queue since it will not need processing after this
		
		//if we have a match and all remaining options are worse by a margin then stop looking
		if (!is_bool($best) &&  (($best * $margin) < $dist)) break;
		
		/*
			$leaf is an integer index into the $leaves array which represents a segment that has already been added
			we now want to find further segments attached to the other end of the $leaf segment
			the element should always exist so the following test is just belt and braces
		*/
		if (!isset($leaves[$leaf])) continue;
	
		$code_from = $leaves[$leaf]['node'];			//the end_code of the end of the leaf segment we want to continue from
		$last_dist = $leaves[$leaf]['dist'];		//distance of the path this is in including the leaf segment
		$last_angle = $leaves[$leaf]['angle'];		//angle at the end we are trying to follow on from
		$last_link = $leaves[$leaf]['link'];		//link from the parent

		//loop though the primitive links which are listed as having this code_from node as an end
		if (clb_count($primitives['nodes'][$code_from])) foreach($primitives['nodes'][$code_from] AS $linkpnum)
		{
			//this is really a shortest path so don't reuse any segs as predecessor will have scored better than this try can
			if (isset($used[$linkpnum])) continue;	
			
			$linkdata = $primitives['links'][$linkpnum];	//details for this primitive segment
			
			$end1 = clb_val(FALSE, $linkdata, 'end1');
			$end2 = clb_val(FALSE, $linkdata, 'end2');
			$reverse = ($code_from == $end2);
			
			//non reversable link and an attempt to use it in the wrong direction, skip it
			if ($reverse && (clb_val(0, $linkdata, 'reverse') & 1)) continue;	//reverse value of 1 means not in reverse direction
			if (!$reverse && (clb_val(0, $linkdata, 'reverse') & 2)) continue;	//reverse value with 2 means not in forward direction
			
			if ($use_angles && ($last_angle !== FALSE))	//$last_angle should always be good
			{
				//compare angle from end of last seg with angle on the beginning of this one
				$this_angle = (!$reverse ? clb_val(FALSE, $linkdata, 'angle1') : clb_val(FALSE, $linkdata, 'angle2'));
				$kink = abs(rad2deg($last_angle - $this_angle));
				/*
					the difference of the two angles will be 180 if they connect on a straight line, 
					we are allowing connecting angles of 180 +/- 80 degrees, if outside the range skip this segment.
				*/
				if (($kink < (180-$bends)) || ($kink > (180+$bends))) continue;
			}
			
			//no more tests we are adding this segment as leaf in the paths tree
			
			$otherend = ($reverse ? $end1 : $end2);
			$dist = $last_dist + clb_val(0, $linkdata, 'dist');	//add this link distance to the total
			//if this is the final segment only add the part of the length to the point
			if ($linkpnum == $seg2) $dist = $last_dist + (!$reverse ? clb_val(0, $linkdata, 'dist1') : clb_val(0, $linkdata, 'dist2'));
			
			$used[$linkpnum] = 1;	//so we dont reuse this segment
			
			$node = $seq++;
			$leaves[$node]['par'] = $leaf;			//record how we got to this node
			$leaves[$node]['node'] = $otherend;		//the end we will continue on from
			$leaves[$node]['link'] = $linkpnum;		//the pnum of this primitive segment
			$leaves[$node]['dist'] = $dist;			//total distance including this segment
			//angle of the end of this segment, to be compared with angles of follow on segments, so train does not turn sharp corners.
			$leaves[$node]['angle'] = ($reverse ? clb_val(0, $linkdata, 'angle1') : clb_val(0, $linkdata, 'angle2'));
			
// qlog(__FUNCTION__, __LINE__, $node, $otherend, $last_link, $linkpnum, $reverse, $dist);				
			
			//if this was the target segment then record it as a complete route, otherwise add the added leaf to the queue
			if ($linkpnum == $seg2)
			{
				//we have reached our destination, hoozah!
				//remember the end leaf and the distance so we can easily rank results.
				$complete[$node] = $dist;
				if (is_bool($best)) $best = $dist;
				break;	//*** since this is now a true shortest path we will not continue after getting a result
			}
			else	//if not a match add this to the queue to see if we can get there from here.
			{
				//see how far away this link leaves us and add this to the dist
				$pt = clb_val(FALSE, $linkdata, ($reverse ? 'pt1' : 'pt2'));
				$pythag = pline_surface_dist($target[0], $target[1], $pt[0], $pt[1]);
				
				if ($crowfly && $allowance && ($pythag > ($crowfly * $allowance)))
				{
					//this is a natural thing to happen as less promising paths are abandoned, no need to log these unless debugging
					//qlog(__FUNCTION__, __LINE__, 'branch abondoned as route longer than distance', $node1, $linkpnum, $last_dist, $dist, $pythag, $crowfly);
					continue;
				}
				$queue['x'.$node] = round($dist + $pythag);	//add this leaf to the processing queue with the total distance
			}
		}
	}
//qlog(__LINE__,'raw path time', clb_timing(__LINE__));
//qlog(__FUNCTION__, __LINE__, $leaves);	

	/*
		originally this method found a selection of short routes, but now only finds the true shortest
		however, the ability to handle multiples has not been removed
		
		the $complete array holds the leaf index of the last segment in the chain.  
		need to follow backwards to from parent to parent to get the full list of segs in the sequence.
	*/
	$result = array();
	if (count($complete)) foreach($complete AS $key=>$dist)
	{
		$path = array();
		while (is_int($key))
		{
			if ($leaves[$key]['link']) array_unshift($path, $leaves[$key]['link']);
			$key = $leaves[$key]['par'];
		}
		$result[] = array('dist'=>$dist, 'path'=>$path);
	}
	return $result;
}


/*
	this function creates an array that can be used to identify routes on a stop/station
	if a stop appears multiple times on a route, there will be an equivalent number of entries
	this means circular routes are supported along with other bus type configurations
	
	this structure is good for finding routes on a stop, it is not good for finding stops on a route
	but since a route is just a list of stops, that side of things is already handled.
	
	$stops_xref = array(
		['routes'][r_index] = rnum 		//seperate entries for "rnum" and "rnum." numbered sequentially as r_index
		['stops'][pnum][r_index][seq]=>1	//seq gives the order of the stops in the route
		['stops'][pnum]=>r_index/seq;r_index/seq;r_index/seq;	//seq gives the order of the stops in the route
	)
*/
function pbuild_stops_xref($rnums, $path='')
{
	global $wpdb;

	if (!is_array($rnums)) $rnums = array($rnums);

	$stops_xref = array('routes'=>array(),'stops'=>array());
	
	$max_routes = count($rnums);
	
	foreach($rnums AS $r_no => $rnum)
	{
	
		if (($r_no % round($max_routes/20)) == 0) echo '.';
	
		$query = 'SELECT '.RF_ROUTES_STOPS.', '.RF_ROUTES_SBACK.' FROM '.RF_ROUTES_FROM.' WHERE '.RF_ROUTES_KEY.'='.clb_escape($rnum);
		$route = $wpdb->get_results($query, ARRAY_A);
		
		//qlog(__LINE__, $rnum, clb_count($route));
		
		if (clb_count($route) == 0)
		{
			qlog(__LINE__, __FILE__, 'route not found', $rnum);
			continue;
		}
		
		$route = reset($route);

		//assume a route is reversable unless it is blocked by '*' or explicitly lists the reverse version
		if (empty($route[RF_ROUTES_SBACK]))
		{
			$lines = preg_split('/[\r\n]+/', $route[RF_ROUTES_STOPS]);
			$route[RF_ROUTES_SBACK] = join("\n", array_reverse($lines))."\n";
		}
		
		//scanning directions separately
		foreach(array(RF_ROUTES_STOPS=>'', RF_ROUTES_SBACK=>'.') AS $dir=>$dot)
		{			
			//make index of route names so we can refer to them by index pos rather than by full text rnum
			//separate entries for out and back route numbers
			$r = count($stops_xref['routes']);
			$stops_xref['routes'][$r] = $rnum.$dot;
			
			//split out stop pnums from the segs list on the route record
			if (preg_match_all('/^(\w+)(\S*\s(.*))?$/m', $route[$dir], $lines, PREG_SET_ORDER))
			{					
				$seq = 0;	//sequense for stops in this route in this direction
				//loop thorugh each stop/station pnum just extracted from stops_list / stops_back
				foreach($lines AS $ln_no=>$stop)
				{
					$pnum = clb_val('', $stop,1);	//get parts from the regular expression above
					//$stops_xref['stops'][$pnum][$r][$seq++] = 1;
					if (!isset($stops_xref['stops'][$pnum])) $stops_xref['stops'][$pnum] = '';
					$stops_xref['stops'][$pnum] .= $r.'/'.($seq++).';';
				}
			}
		}
	}
	
	if ($path && is_dir(dirname($path)))
	{
		$blob = clb_blob_enc($stops_xref, TRUE); //TRUE=binary
		file_put_contents($path, $blob);	//, FILE_BINARY);
	}
	
	echo "\n";
	return $stops_xref;
}




/*
	finds common routes for two nodes, ie the routes that will use the link between them.
	will include routes in only the n1 -> n2 direction and not the n2 -> n1 direction.
	
	$stops_xref is array created by pbuild_stops_xref(), passed by reference for efficiency not so we can change it
	$forwards allows us to exclude routes by direction when scaning from destinatino to origin
*/
function pbuild_common_routes($n1, $n2, & $stops_xref, $forwards=TRUE)
{
	$route_distances = array();
	$routes_this = array();	//to hold the list of valid routes
	
	foreach(array($n1, $n2) AS $node)
	{
		$routes_this[$node] = array();	
		
		if (!isset($stops_xref['stops'][$node]))
		{
			qlog(__LINE__, __FILE__, 'unknown stop in route', $node);
			continue;
		}		
		
		//$r_index the index number for the route, and will translate short list after
		//foreach($stops_xref['stops'][$node] AS $r_index=>$uses) foreach($uses AS $seq=>$one)
		if (preg_match_all('!(\d+)/(\d+);!', $stops_xref['stops'][$node], $m, PREG_SET_ORDER))
		foreach($m AS $use) 
		{
			list($junk, $r_index, $seq) =  $use;
			
			if (!isset($route_distances[$r_index]))	//we have not seen this route & direction before so add it
			{
				$route_distances[$r_index] =  $seq;
			}
			else if ($forwards == (($route_distances[$r_index]+1) != $seq))
			{
				/*
					distances should always be one higher on the following stop even on the backward direction (as they both start from 0)
					on a subsequent node if the direction of the route does not go in the same direction as the distances
					the disatances for the reverse route should also be ascending because they are calculated from the other end
					that is to say "rnum" and "rnum." are both just routes that happen to follow the same path in opposite directions
				*/
				continue;	//route running backwards so skip it
			}
			$routes_this[$node][] = $r_index;
		}
	 }
	 
	 //intersect to get the common routes, common routes in wrong direction will already have been excluded by the above
	 $common = array_intersect($routes_this[$n1], $routes_this[$n2]);
	 
	 //convert route position into actual rnums including dot
	 foreach($common AS $key=>$val) $common[$key] = clb_val('', $stops_xref, 'routes', $val);
	 
	 return $common;
}



/*
	this function creates the p2p (point to point) array used for actual route finding
	
	$stype - array of types (of links, but usually of same type as the nodes being linked, eg rail not rseg)
	
	although ptypes make broad selection, only nodes on routes are included so the $stops_xref file also has an effect on which links/nodes are selected.
	
	many fields are held as comma separated lists rather than as sub arrays to accellerate the unseriealize() process when loading this massive structrue
	
	unserializing this structure was slow when fully formed so these optimisations accelerate that
	each nodes value is converted to a comma separated list to simplify the structure
	each 'link' value is held as a a url query string
	
	$p2p_links = array(
		'nodes' = > array(nodepnum => array(linkpnums)),	//ie given a node (station) gives a list of links to/from that node
		'links' => array(linkpnum => array(dist, ptype, time, reverse, weight, pt1, pt2, end1_pnum, end2_pnum, routes1, routes2))
	);
	
	$pnums = clb_val(FALSE, $p2p_links, 'nodes', $stop_pnum);
	foreach($pnums AS $link_pnum)
	{
		$data = $links['links'][$link_pnum];
	}
*/
function pbuild_p2p_links($stype, & $stops_xref, $path='', $purge=FALSE)
{
	global $wpdb;

	if (!is_array($stype)) $stype = array($stype);

	$p2p_links = array();
	$unused = array();
	
	//rail stations with pltforms on different lines that converge need to be split into different platforms
	if ($do_plats = in_array('rail', $stype))
	{
		$plats = pbuild_platforms();
		//incluse platforms within the main structure
		$p2p_links['stat2plat'] = $plats['stat2plat'];
		$p2p_links['plat2stat'] = $plats['plat2stat'];
	}
	
	if (!in_array('walk', $stype)) $stype[] = 'walk';	//add in walking as a link type if not already in the list
	
	$query = 'SELECT '.RF_LINKS_SELECT.','.RF_LINKS_POINTS.' FROM '.RF_LINKS_FROM.' WHERE ptype IN '.clb_join($stype, TRUE);
	$segs = $wpdb->get_results($query, ARRAY_A);
	
	$max_segs = clb_count($segs);
	
	if ($max_segs) foreach($segs AS $seg_no=>$seg)
	{
//		qlog(__FUNCTION__, __LINE__, $seg_no, $max_segs);

		if (($seg_no % round($max_segs/10)) == 0) echo round(100*($seg_no/$max_segs)).'% complete'."\n";
		
		$data = array();
		$linkpnum = $seg[RF_LINKS_KEY];
		
		//remember the first/last points so we can do distance checking, omit other fields in the points and round decimal places to save space
		$pt_data = clb_blob_dec($seg[RF_LINKS_POINTS]);
		if (is_array($pt_data))
		{
			$pt = reset($pt_data);	
			$data['pt1'] = round($pt[0], PT_PRECISION).', '.round($pt[1], PT_PRECISION);
			$pt = end($pt_data);
			$data['pt2'] = round($pt[0], PT_PRECISION).', '.round($pt[1], PT_PRECISION);
		}
				
		$end1 = $seg[RF_LINKS_END1];
		$end2 = $seg[RF_LINKS_END2];
		//routes hold list of stations, so if these are platforms we need to convert to stations before looking up routes.
		if ($do_plats && isset($p2p_links['plat2stat'][$end1])) $end1 = $p2p_links['plat2stat'][$end1];
		if ($do_plats && isset($p2p_links['plat2stat'][$end2])) $end2 = $p2p_links['plat2stat'][$end2];
						

		if ($seg[RF_LINKS_TYPE] == 'walk')	//link connects things that are unconnected so no common routes
		{
			if (!isset($stops_xref['stops'][$end1])) continue;	//if no routes using this stop no need to include the interchange to it
			if (!isset($stops_xref['stops'][$end2])) continue;	//if no routes using this stop no need to include the interchange to it
			$data['routes1'] = array();
			$data['routes2'] = array();
			
			/*
				automatic walk links only have start and end points, handmade ones should have more
				this is used when generating walk links to detect hand mades and leave them alone.
			*/
			if (is_array($pt_data)) $data['pt_count'] = count($pt_data);
		}
		else
		{
			//route numbers including dots in FORWARD direction
			$data['routes1'] = join(',',pbuild_common_routes($end1, $end2, $stops_xref));	//common routes which can get from predecessor to the node on this leaf
	
			//route numbers including dots in REVERSE direction
			$data['routes2'] = join(',',pbuild_common_routes($end2, $end1, $stops_xref));	//common routes which can get from predecessor to the node on this leaf
			
			if (empty($data['routes1']) && empty($data['routes2']))
			{
				qlog(__LINE__, 'link has no routes', $linkpnum);
				$query = 'SELECT '.RF_NODES_KEY.' FROM '.RF_NODES_FROM.' WHERE '.RF_NODES_KEY.' IN '.clb_join(array($seg[RF_LINKS_END1],$seg[RF_LINKS_END2]), TRUE);
				$check = $wpdb->get_results($query, ARRAY_A);
				if (clb_count($check) < 2)	//if end points for link no longer exist, then delete link
				{
					qlog(__FUNCTION__, __LINE__, 'deleting link with missing ends', $linkpnum, $seg[RF_LINKS_END1], $seg[RF_LINKS_END2]);
					pbuild_del_rec(RF_LINKS_LINKS, RF_LINKS_KEY.'='.clb_escape($linkpnum));
				}
				else
				{
					$unused[] = $linkpnum;
				}
				continue;	// do not include this segment if not on any routes.
			}
		}
		
		$data['dist'] = $seg[RF_LINKS_DIST];
		$data['weight'] = $seg[RF_LINKS_WEIGHT];
		$data['time'] = $seg[RF_LINKS_TIME];
		$data['end1'] = $end1 = $seg[RF_LINKS_END1];	//these are pnums of the nodes at either end of the segment
		$data['end2'] = $end2 = $seg[RF_LINKS_END2];
		$data['ptype'] = $seg[RF_LINKS_TYPE];
		$data['reverse'] = $seg[RF_LINKS_REVERSE];
		
		$p2p_links['links'][$linkpnum] = http_build_query($data);

		$p2p_links['nodes'][$end1][] = $linkpnum;
		$p2p_links['nodes'][$end2][] = $linkpnum;
	}
	
	//now turn the node lists into comma separated lists insteas of arrays
	foreach($p2p_links['nodes'] AS $key=>$val) $p2p_links['nodes'][$key] = join(',',$val);
	
	if ($path && is_dir(dirname($path)))
	{
		$blob = clb_blob_enc($p2p_links, TRUE); //TRUE=binary
		file_put_contents($path, $blob);	//, FILE_BINARY);
	}
	
	if (count($unused)) qpre(__FUNCTION__,__LINE__, 'links without routes', count($unused), $unused);
	if ($purge && count($unused)) pbuild_del_rec(RF_LINKS_LINKS, RF_LINKS_KEY.'='.clb_join($unused, TRUE));

	return $p2p_links;
}





/*
	this function creates link records to interconnect nodes from different travel modes and nodes on different tracks
	interconnects will be considered walking and will be collected up to a given radius
	a spearate link type for foot paths will also be used
	but when reported in route finding, small distances will not be mentioned as separate stages
	different walking radii can be specified at search time, up to pre calculated radius
	if ptype of two nodes do not match but they are within a distance
	
	hold "walk" distances as double their actual so that they are picked after actual routes, but a "walk" will not add to the change score so will win there
	if can in the short path method, check that "walk" links are not used twice in a row.
	
	when buses moved to route finder system, make interconnects from rail to bus stops near stations.
	
	interconnects between bus stops within 200m should handle no question interchanges
	longer connections possible.
	possibly use google directions to see if distance  > crowfly in which case may not be real interconnect
	also need to stop interconnects between stops on same route
	need to limit use of interlinks to one otherwise shortest path would have visitor walking from bus stop to bus stop
	
	$ptypes - single type as string or array of ptypes for nodes
	$p2p_links - point to point lookup tables gives links connnecting nodes
	$stops_xref - stops to routes lookup table, allows us to spot unused nodes, hence optional
	$meters - radius for making walking links
*/
function pbuild_walk_links($ptypes, & $p2p_links, $stops_xref=FALSE, $meters = 400)
{
	global $wpdb;
	
	if (!is_array($ptypes)) $ptypes = array($ptypes);
	
	$toll_lat = $meters / METERS_PER_DEGREE;	//$toll_lat is degrees equivalent of $meters in meters from the center
	
	$count = 0;	//just count how many connections we are making
	
	$pos = array_search('plat', $ptypes);	//dont include platforms in interchanged (we convert stations to platforms later)
	if (is_int($pos)) unset($ptypes[$pos]);
	
	$query = 'SELECT '.RF_NODES_SELECT.' FROM '.RF_NODES_FROM.' WHERE '.RF_NODES_TYPE.' IN '.clb_join($ptypes, TRUE).' ORDER BY lat';
	$places = $wpdb->get_results($query, ARRAY_A);
	if (is_array($places))
	{
		$lats = clb_column($places, 'lat');
		$lngs = clb_column($places, 'lng');
		
		$max = count($places);
		array_multisort($lats, SORT_ASC, $lngs, SORT_ASC, $places);
		
		foreach($lats AS $idx => $lat1)	//only need to scan forwards because link would have been found forwards so need to scan backwards
		{
			$lng1 = $lngs[$idx];
			
			//allow larger degree difference in longitude because they represents fewer meters
			//also do it for each different lat
			$toll_lng = $toll_lat / cos($lat1);

			$scan = $idx+1;	//start one higher in the selection on each pass, as previous points already compared
			
			while($scan < $max)	//this is just a safe condition, we will break out sooner than that
			{
				$lat2 = $lats[$scan];
				if (abs($lat2 - $lat1) > $toll_lat) break;	//all remaining latitudes will be too far away
				
				$lng2 = $lngs[$scan];
				if (abs($lng2 - $lng1) <= $toll_lng)	//skip items where longitudes differ too much
				{
					//slightly extravegant, double check distance as a circle and not just a square.
					$dist = pline_surface_dist($lat1, $lng1, $lat2, $lng2);
					if ($dist < $meters)
					{
						//nodes are within spitting distance but check if they are already connected
												
						//but first check if the nodes have platforms
						$pnum = clb_val(FALSE, $places, $idx, RF_NODES_KEY);
						if ($stops_xref && !isset($stops_xref['stops'][$pnum])) continue;	//if no routes using this stop no need to include the interchange to it
						$nodes1 = (isset($p2p_links['stat2plat'][$pnum]) ? $p2p_links['stat2plat'][$pnum] : array($pnum));
						
						$pnum = clb_val(FALSE, $places, $scan, RF_NODES_KEY);
						if ($stops_xref && !isset($stops_xref['stops'][$pnum])) continue;	//if no routes using this stop no need to include the interchange to it
						$nodes2 = (isset($p2p_links['stat2plat'][$pnum]) ? $p2p_links['stat2plat'][$pnum] : array($pnum));
						
						//normally there will only be one element in each array but if one or both has platforms we need to work the permutations
						foreach($nodes1 AS $pnum1) foreach($nodes2 AS $pnum2)
						{
							$txt = '';
							$query = 'SELECT '.RF_NODES_SELECT.' FROM '.RF_NODES_FROM.' WHERE '.RF_NODES_KEY.' IN '.clb_join(array($pnum1, $pnum2), TRUE);
							$ends = $wpdb->get_results($query, ARRAY_A);
							
							if (pbuild_make_link($p2p_links, $ends, $dist))
							{
								$count++;
								
								$txt = '';
								if(is_array($ends)) {
									$peep = reset($ends);
									$txt .= $peep[RF_NODES_KEY].' / '.$peep[RF_NODES_TYPE].' / '.$peep[RF_NODES_NAME].', ';
									$p1 = $peep[RF_LINKS_TYPE];
									$peep = next($ends);
									$txt .= $peep[RF_NODES_KEY].' / '.$peep[RF_NODES_TYPE].' / '.$peep[RF_NODES_NAME].', ';
									if (($p1 == $peep[RF_LINKS_TYPE]) && ($p1 != 'rail')) $txt = '**** non rail '.$txt;
								}
								qpre(__LINE__, $count, $dist, $txt);
							}
						}	//producting of end nodes
					}
				}
				$scan++;
			}
			
		}
	}
	
	//now ensure walking links between platforms at same station
	$query = 'SELECT '.RF_NODES_SELECT.' FROM '.RF_NODES_FROM.' WHERE '.RF_NODES_TYPE.' IN '.clb_join(array('plat'), TRUE).' ORDER BY '.RF_NODES_DESC;
	$places = $wpdb->get_results($query, ARRAY_A);
	if (clb_count($places))
	{
		//group records by station
		$list = array();
		foreach($places AS $rec) $list[$rec[RF_NODES_DESC]][] = $rec;
		
		//get list of stations so we can access details
		$query = 'SELECT '.RF_NODES_SELECT.' FROM '.RF_NODES_FROM.' WHERE '.RF_NODES_KEY.' IN '.clb_join(array_keys($list), TRUE);
		$places = $wpdb->get_results($query, ARRAY_A);
		$places = clb_rekey($places, RF_NODES_KEY);
		
		foreach ($list AS $station=>$plats) if (clb_count($plats) > 1)
		{
			//check each platform has the name of its station in its name field
			$name = clb_val('', $places, $station, RF_NODES_NAME);
			$query = 'UPDATE '.RF_NODES_FROM.' SET '.RF_NODES_NAME.'='.clb_escape($name).' WHERE '.RF_LINKS_KEY.'=';
			if ($name) foreach($plats AS $rec) if ($rec[RF_NODES_NAME] != $name) $wpdb->query($query.clb_escape($rec[RF_NODES_KEY]));
			
			//permute platform connections
			$count = count($plats);
			for($x=0; $x<($count-1); $x++) for($y=$x+1; $y<$count; $y++) pbuild_make_link($p2p_links, array($plats[$x], $plats[$y]));
		}
	}
	
	qlog(__LINE__, $count);
}


function pbuild_make_link(& $p2p_links, $ends, $dist=FALSE)
{
	global $wpdb;
	
	$rec = reset($ends);
	$name = clb_val('', $rec, RF_NODES_TYPE).': '.clb_val('', $rec, RF_NODES_NAME);
	$lat1 = clb_val(0, $rec, 'lat');
	$lng1 = clb_val(0, $rec, 'lng');
	$pnum1 = clb_val(0, $rec, RF_LINKS_KEY);
	
	$rec = next($ends);
	$name .= clb_val('', $rec, RF_NODES_TYPE).': '.clb_val('', $rec, RF_NODES_NAME);
	$lat2 = clb_val(0, $rec, 'lat');
	$lng2 = clb_val(0, $rec, 'lng');
	$pnum2 = clb_val(0, $rec, RF_LINKS_KEY);
	

	//checking for existing links, and specifically any existing walk link
	$linkpnum = '';
	$makelink = TRUE;
	if (isset($p2p_links['nodes'][$pnum1]) && isset($p2p_links['nodes'][$pnum2]))
	{
		//get the list of link_pnums for both nodes and intersect to see if they are already connected
		$connections = array_intersect(explode(',',$p2p_links['nodes'][$pnum1]), explode(',',$p2p_links['nodes'][$pnum2]));
		$makelink = (count($connections) <= 0);	//dont make a link if there is already a direct link
		
		//if there are links see if one of them is a walk link in which case we may want to update it if the ends have moved.
		foreach($connections AS $ref) if (isset($p2p_links['links'][$ref]['ptype']) && ($p2p_links['links'][$ref]['ptype'] == 'walk'))
		{
			$linkpnum = $ref;
			$makelink = TRUE;		//comment this out if dont want to update walk links
			if (isset($p2p_links['links'][$ref]['pt_count']) && ($p2p_links['links'][$ref]['pt_count'] > 2)) $makelink = FALSE;	//if the walk link has more than 2 points then 
			break;
		}
		if ((count($connections) > 1) && ($linkpnum)) qlog(__LINE__, 'walk link and other link types', $pnum1, $pnum2, $connections);
	}
	
	/*
		$p2p_links may not include existing "walk" interchanges if there were no routes involving the stops
		but to prevent creating duplicates do a search and do nothing if a walk exists
	*/
	if ($makelink && !$linkpnum)
	{
		$pair = clb_join(array($pnum1,$pnum2), TRUE);
		$query = 'SELECT '.RF_LINKS_KEY.' FROM '.RF_LINKS_FROM.' WHERE '.RF_LINKS_TYPE.'="walk" AND '.RF_LINKS_END1.' IN '.$pair.' AND '.RF_LINKS_END2.' IN '.$pair;
		$check = $wpdb->get_results($query, ARRAY_A);
		if (is_array($check) && clb_val('', reset($check), RF_LINKS_KEY)) $makelink = FALSE;
	}
	
	if ($makelink)
	{
		if ($dist === FALSE) $dist = pline_surface_dist($lat1, $lng1, $lat2, $lng2);
	
		$processed = clb_now_utc();
		$query = '';
		$query .= ', '.RF_LINKS_DIST.'='.($dist * 2);		//double the distance so this is not chosen over actual tracks
		$query .= ', '.RF_LINKS_TIME.'='.ceil($dist / 80);	//80 meters per minute is about 3 miles per hour
		$query .= ', '.RF_LINKS_MODIFIED.'='.clb_escape($processed);
		$query .= ', '.RF_LINKS_NAME.'='.clb_escape($name);
		
		$query .= ', lat='.round((($lat1+$lat2)/2), PT_PRECISION);
		$query .= ', lng='.round((($lng1+$lng2)/2), PT_PRECISION) ;
		
		
		$points = array();
		$points[] = array($lat1, $lng1, 0);
		$points[] = array($lat2, $lng2, 0);
		
		if ($polyline = pline_make($points, array('color'=>'#00FF00')))
		{
			$query .= ', '.RF_LINKS_LINE.'='.clb_escape(clb_join($polyline,'','&','='));
			$query .= ', '.RF_LINKS_POINTS.'='.clb_escape(clb_blob_enc($points));
		}
		
		if ($linkpnum)
		{
			$query = 'UPDATE '.RF_LINKS_FROM.' SET '.trim($query, ', ').' WHERE '.RF_LINKS_KEY.'='.clb_escape($linkpnum);
		}
		else
		{
			$linkpnum = pbuild_new_pnum(RF_LINKS_FROM, RF_LINKS_KEY);
			$query .= ', '.RF_LINKS_KEY.'='.clb_escape($linkpnum);
			$query .= ', '.RF_LINKS_CREATED.'='.clb_escape($processed);
			$query .= ', '.RF_LINKS_END1.'='.clb_escape($pnum1);
			$query .= ', '.RF_LINKS_END2.'='.clb_escape($pnum2);
			$query .= ', '.RF_LINKS_TYPE.'='.clb_escape('walk');
			$query = 'INSERT INTO '.RF_LINKS_FROM.' SET '.trim($query, ', ');
		}
// qlog(__LINE__, $query);	
 		$wpdb->query($query);

	}
	
	return $makelink;
}


?>
