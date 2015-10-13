<?php

//requires clb_lib.php 

if (!defined('METERS_PER_DEGREE')) DEFINE('METERS_PER_DEGREE',111034);//111.320	//111.034 //0.6214 miles/km
if (!defined('EARTH_RADIUS_M')) DEFINE('EARTH_RADIUS_M',6371000);	//earth radius in meters 111,195m/degree


/*
	return the actual polyline structure as an array
	$points passed as reference so that a granularity field can be added to each point.
*/
function pline_make(& $points, $attr=FALSE)
{
	$color = clb_val('#0000FF', $attr, 'color');
	$weight = clb_val(7, $attr, 'weight');	
	$opacity = clb_val(1, $attr, 'opacity');
	$pregran = clb_val(FALSE, $attr, 'pregran');		//if points already have granularity calculated
	$max_pts = clb_val(FALSE, $attr, 'max_pts');		//sets a target number of points for result, which is acheived by omiting detail levels
	$calc_len = clb_val(TRUE, $attr, 'calc_len');		//option not to calculate length of segment
	$split = clb_val(FALSE, $attr, 'split');			//split path into segments, based on segment length in meters, returns array of polylines
	$do_bounds = clb_val(FALSE, $attr, 'do_bounds');	//include bounds in structure
	
	
	//$points: 0=>lat, 1=>lng, 2=>elev, 3=>comment from sges list on route record
	// replaced [3] with ['comment'] and ['pnum']

	$timing = FALSE;	//timing marks for testing
	if ($timing) clb_timing(__LINE__);	
	
	//initial loop here scans points to find points of greatest distance and records granularity directly in the points array
	//only points which do not lie directly between the predecessor and follower will be included in the polyline
	//the scale of the distance held allows an approprate zoom level to be assigned to each point.
	//the distance cut of point roughlyt equates to one screen pixel at full zoom, but actaul zoomlevel info added in next part not this.
	
	/*
		see below for explanation and proof of formula
		http://www.intmath.com/Plane-analytic-geometry/Perpendicular-distance-point-line.php
		
		distance from point (m,n) to line Ax + By + C = 0 is given by abs(Am + Bn + C) / sqrt(A^2 + B^2)
		slope of the line is -A/B
		to simplify the line subtract the first point from the second so that there is no incept C (ie C=0)
		divide by A so that A becomes 1 and B becomes B/A and the distance formula becomes
		abs(1m + (B/A)n + 0) / sqrt(1^2 + (B/A)^2)
		abs(m + (B/A) n) / sqrt(1 + (B/A)^2)
		in our case A is the difference of latitude and B is the differnece of longitudes
		
		if A is zero then we cannot divide by it but it also means the line is horizontal so the distance is just the difference in line lat and point lat
		
		a thrid case is when the line is only a point in which case just use pythag to get the distance
	*/
	
	$verySmall = 0.00001;	//0.00001 happens to equate to 1 screen pixel at zoom level 17
	$range = 18;	//0= outerspce, 18=rooftops
	$numlevels = $range;	//hard coding this so that one zoom group = one zoom level giving prceise control over point granularity
	$maxZoom = $numlevels-1;
	$zoomFactor = pow(2,floor($range/$numlevels));	
	//if every zoom level has its own zoom group then each one has a zoom factor of 2, if there are two zoom levels per group then the factor is 4.
	
	//set minimum distances that matter at each reverse zoom level (ie zoom level 0 has $break[$range-1])
	$breaks = array();
	for($i = $maxZoom; $i >= 0; $i--) $breaks[$i] = $verySmall * pow($zoomFactor, $i);

	$points = array_values($points);	//ensure indexes are from 0 to n-1

	$last_pt = count($points)-1;
	if($last_pt > 0) {

		if (!$pregran) {	//if we have granularity on the points then we do not need to rescan
			
			//ensure any old granularity is removed.
			foreach($points AS $no=>$pt) if (isset($points[$no]['gran'])) unset($points[$no]['gran']);
			
			//using stack to scan points within line.  Start with the end points and divide into two subsets broken at 
			//the point of greatest difference from the direct line between ends of the current subset
			$detail = end($breaks);
			$stack = array();
			array_push($stack, array(0, $last_pt));	//initial values array with first and last array indicies
			$adjust = cos(deg2rad(($points[0][0]+$points[count($points)-1][0])/2));	//use one longitude adjustment factor across all latitudes based on the half way mark

			while(count($stack) > 0) {			//stack based loop rather than recursion
				list($first, $last) = array_pop($stack);
				
				//since all points within subset are to be compared to the same line we can precalculate some factors of the equation
				$A = ($points[$last][0] - $points[$first][0]);
				$B = ($points[$last][1] - $points[$first][1]) * $adjust;
				if ($A != 0) {
					$method = 2;	//assume two points in line method
					$B = $B / $A;	//$A also divided by $A so becomes 1
					$denom = sqrt(1 + pow($B, 2));	//since $A = 1 there is no point in using the pow function to square it and just plug in 1
					//distance for point $x, $y now given by $dist = abs(($x - ($B * $y)) / $denom);
					
				} else if ($points[$last][1] == $points[$first][1]) {
					$method = 1;	//if end points are coincident then simply do distance from test point to first
				
				} else {
					$method = 0;	//if the latitiudes of the end points are the same we would have a div by zero so will simply get difference of latitudes
				}
				
				$maxDist = 0;
				for($i = $first+1; $i < $last; $i++) {
					
					$x = ($points[$i][1] - $points[$first][1]) * $adjust;
					$y = ($points[$i][0] - $points[$first][0]);
					
					switch ($method) {
					case(2):	//two points, do point distance from line
						$dist = abs(($x - ($B * $y)) / $denom);
						break;
					case(1):	//one point do pythag to get distance between points.
						$dist = sqrt(($x* $x) + ($y * $y));
						break;
					case(0):	//find distance by difference of lattitudes
						//this looks wrong but si right.  The end points have the same lat so form a vertical line, 
						//the ponit we are testing therefore differs from the line by its lat also
						$dist = abs($points[$i][0] - $points[$first][0]);
						break;
					}
//$points[$i]['dist'] = $dist;
					if ($dist > $maxDist) {
						$maxDist = $dist;
						$maxLoc = $i;
						//if($maxDist > $absMaxDist) $absMaxDist = $maxDist;	//$absMaxDist is the largest variation from the straight line, but we dont need it
					}
				}	//end scan of intermediate points
				
				
				if($maxDist >= $detail) {	//if any point stuck out far enough 
					//the point that sticks out the most is given a distance value and will be included in the polyline
					
					$gran = $maxZoom;
					foreach($breaks as $lvl => $limit) if ($maxDist > $limit) {
						$gran = max(0, $lvl); 
						break;
					}
					
					$points[$maxLoc]['gran'] = $gran;
					
					//also push the subsegments created when this points divides the segment just handled
					array_push($stack, array($first, $maxLoc));
					array_push($stack, array($maxLoc, $last));
				}
			}
			
		}
	}
	//set these after the granularity loop to ensure everyone keeps end points
	$points[0]['gran'] = $points[$last_pt]['gran'] = $maxZoom;	//make sure the end points have top granularity
	
	$reduce = 0;
	$total = 0;
	if (is_int($max_pts)) {
		$scores = array_fill(0,count($breaks),0);
		foreach($points as $no=>$pt) if (isset($pt['gran']) && isset($scores[$pt['gran']])) $scores[$pt['gran']]++;

		$reduce = $maxZoom;
		for($i = $maxZoom; $i >= 0; $i--) {
			if (($total + $scores[$i]) > $max_pts) break;
			$total += $scores[$i];
			$reduce = $i;
		}
		qlog(__LINE__, 'number of points to add', $total);	//point count
	}
	
	if ($timing) clb_timing('polyline gran');	
	
	$poly =  array(
		'color'=>	$color,
		'weight'=>	$weight * 1,	//ensure these are numbers and not strings as this can make the rescaling of the polyline unstable
		'opacity'=>	$opacity * 1,
		'zoomFactor'=> $zoomFactor * 1,
		'numLevels'=> $numlevels * 1
	);
	
	$batches = array();	//when splitting polyline collect batches in this array
	
	//now scan actual points again and build up polylines
	$seg_len = 0;
	$last = count($points)-1;	//index of last point in seg
	$pt_txt = '';
	$levels = '';
	$last_pt = FALSE;
	$accum = array(0,0);	//rather than use the last point each time, use the accumulated differences to reduce error on each link
	
	$pt = reset($points);
	
	$pt[0] = round($pt[0],5);	//clean and consistent and ensures these are numbers not strings
	$pt[1] = round($pt[1],5);
	$bounds = array('n'=>$pt[0], 's'=>$pt[0], 'e'=>$pt[1], 'w'=>$pt[1]);
	$count = 0;

	foreach($points as $no=>$pt) { 
		
		
		if (($no === 0) || ($no === $last)) {	//ensure end points get highest level of importance
			$gran = $maxZoom;
			
		} else if (isset($pt['gran'])) {
			$gran = $pt['gran'];
			if ($gran < $reduce) continue;
			
		} else {
			$gran = 0;
			continue;
		}
		
			
		$pt[0] = round($pt[0],5);	//work to five decimal places as that is the precision of the polylines
		$pt[1] = round($pt[1],5);
		
		//do not allow two points in a row that are identical
		if (!is_array($last_pt) || ($last_pt[0] != $pt[0]) || ($last_pt[1] != $pt[1]))
		{
			
			$count++;
			
			if ($do_bounds) {
				$bounds['w'] = min($bounds['w'], $pt[1]);	//find bounds from extreme coords
				$bounds['e'] = max($bounds['e'], $pt[1]);
				$bounds['s'] = min($bounds['s'], $pt[0]);
				$bounds['n'] = max($bounds['n'], $pt[0]);
			}
						
			if (!$pt_txt) {	//first loop use actual values, afterwards use differences
				$diff = clb_b64e($pt[0]).clb_b64e($pt[1]);
				$accum[0] = $pt[0];
				$accum[1] = $pt[1];
				
			} else {
					
				$dlat = ($pt[0]-$accum[0]);
				$dlng = ($pt[1]-$accum[1]);
				
				$accum[0] += $dlat;
				$accum[1] += $dlng;
				$diff = clb_b64e($dlat).clb_b64e($dlng);	
		
	
				if (($calc_len && $last_pt) || $split) {
					$dist = pline_surface_dist($pt[0], $pt[1], $last_pt[0], $last_pt[1]);

					$seg_len += $dist;
					
				}
				
			} 
			
			$level_str = clb_b64e($gran, FALSE);
		}
		else
		{
			$diff = '';
			$level_str = '';
		}
		
		//close off the polyline because it is the end or we are splitting it
		if ((is_numeric($split) && ($seg_len > $split)) || ($no === $last))
		{
			
			$level_str = clb_b64e($maxZoom, FALSE);	//end this seg and start next with max zoom value
			
			$poly['points'] = $pt_txt . $diff;	//finish off current line with last point
			$poly['levels'] = $levels .= $level_str;
			if ($do_bounds && (clb_count($bounds) == 4)) $poly['bounds'] = $bounds;
			if ($calc_len && is_numeric($seg_len)) $poly['meters'] = round($seg_len);		//this is not part of the google spec but should not cuase problems (famous last words)
			
			if ($split) {
				if (!isset($batches[0])) $batches[0] = $bounds;
				$batches[0]['w'] = min($bounds['w'], $batches[0]['w']);	//find bounds from extreme coords
				$batches[0]['e'] = max($bounds['e'], $batches[0]['e']);
				$batches[0]['s'] = min($bounds['s'], $batches[0]['s']);
				$batches[0]['n'] = max($bounds['n'], $batches[0]['n']);
				$batches[] = $poly;
				
				$seg_len = 0;
			}
			$accum[0] = $pt[0];
			$accum[1] = $pt[1];
			
			//start the new with the same point we just put at the end of the last sub line
			$pt_txt = clb_b64e($pt[0]).clb_b64e($pt[1]);	
			$levels = $level_str;
			$bounds = array('n'=>$pt[0], 's'=>$pt[0], 'e'=>$pt[1], 'w'=>$pt[1]);
			
		} else {
			$pt_txt .= $diff;
			$levels .= $level_str;
			
		}
		
		$last_pt[0] = $pt[0];
		$last_pt[1] = $pt[1];
		
	}
	
 	if ($timing) qlog(__FUNCTION__, __LINE__, clb_timing('encoding'), $total, $count);

	//if we broke the polyline into batches then return that array not just the last $poly
	if (count($batches)) {
		return $batches;
	} else {
		return $poly;
	}
	
}



/*
	this method takes a list of links and returns the combined route point list and polyline
	when converting raw segments to make links between specific nodes, the end nodes and the segs/stops mapping is needed to trim the ends
	
	$route - an array of link pnums
	
	$pnum1, $pnum2 - always stop pnums (even when segs have coordinate end points) on station to station routes pnum1 is used to get the direction of the first segment
		on raw segments, both pnum1 and pnum2 are needed to find the cut points of the end points (and give direction).
	
	$stopsegs - is an array structure listing where to cut a segment at its nearpoint to a stop/station  it contains three parallel arrays:
		
	$stopsegs['stoppnums'][$key] = pnum of stop/station
	$stopsegs['segpnums'][$key] = pnum of segment/link
	$stopsegs['data'][$key]['pos'|'lat'|'lng'] = point in the list of points, lat/lng of cut point
	
	The $key is just a sequential index and elements in the different arrays are linked via common key value 
	
	if $stopsegs is passed in it implies that the list of segments in the $route are raw segments and will need the ends cut
	
	**** splice_links for bus routes
	segs to points produces an array of new segments to handle both direction around roundabouts and so on
	this is possible because the segments used to build this up are directly identified and duplicates omitted
	However since routes made via splice_links are based on node to node links, the raw segs cannot be identified
	Also with bus routes the stops are on opposite sides of the road and so even node to node links will not match 
	in different directions even when they follow exactly the same path.
	
	Only routes which cover the same road more than once will have any need, such as those going into a station
	forecourt or a shopping centre in both directions.  For small overlaps it hardly seems worth the added complexity of cutting.
	
	Showing both directions will probably best be done by showing two lines one for each route direction. 
	
	There will probably be cases of the same node pairs being reachable by different paths because of directional 
	differences in the road system.  But these will be rare and it is probably simpler to address this with a simplication of the
	representation of the road path rather than adding complexity to the route line handling.
	
	alternate or peak routes will still be signalled via <_peak> type annotations but on their own lines  Basically "<>" makes the end of a seg
	naming within the <> is entirely optional.  colour for the coming segment can be specified with "#000000" on the <> line
	alternate routes are specified by <else> in the marker between the segments.  Note that each sub seg will repeat the transitional stop
	eg  A, B, C, <>, C,  D, E, <else>, C, F, G, H, E, <> E, I, J	
	note both sub segs start with C and end with E to match the last stop before and the first stop after the alternate routes

*/
function pline_splice($db, $route, $pnum1=FALSE, $pnum2=FALSE,  $stopsegs=FALSE)
{
	//$route is an array of link pnums
	
	if (!is_array($route) || (count($route) == 0)) 
	{
		qlog(__LINE__, __FUNCTION__, 'bad route', $route, $pnum1, $pnum2);
		//exit();
		return '';
	}
	
	$raw = is_array($stopsegs);	//TRUE => segment end points are points not pnums
	
	if ($raw) {//in the raw case we have to trim the ends so prepare information we will need
	
		$key1 = pline_arr_match($stopsegs['stoppnums'], $pnum1, $stopsegs['segpnums'], reset($route));
		$key1 = reset($key1);	//should only be one match but still need to turn array into single value
		$cut1 = clb_val(FALSE, $stopsegs['data'], $key1);

		$key2 = pline_arr_match($stopsegs['stoppnums'], $pnum2, $stopsegs['segpnums'], end($route));
		$key2 = reset($key2);	//should only be one match but still need to turn array into single value
		$cut2 = clb_val(FALSE, $stopsegs['data'], $key2);
	}
	

	//load all segs in one hit
	$query = 'SELECT '.RF_LINKS_SELECT.','.RF_LINKS_POINTS.' FROM '.RF_LINKS_FROM.' WHERE '.RF_LINKS_KEY.' IN '.clb_join($route, TRUE);
	$segs = $db->get_results($query, ARRAY_A);
	$segs = clb_rekey($segs, 'pnum');
	
	$last_end1 = $last_end2 = $flip = FALSE;
	$flipfirst = FALSE;	//if the first seg is flipped this holds the nubmer of points so we can work out how many to trim

	$points = array();
	$count = 0;
	$max = count($route);
	
	foreach($route AS $seg_pnum)
	{
		$count++;	//so will be 1 on first loop, 2 on second etc
		$rec = clb_val(FALSE, $segs, $seg_pnum);
		$aoe_text = clb_val('', $rec, RF_LINKS_POINTS);
		$aoe_data = pline_pts_arr($aoe_text);	
		if (!is_array($aoe_data))
		{
			qlog(__FUNCTION__, __LINE__, 'failed to get points list for route segment', $seg_pnum, $count, $aoe_data);
			continue;
		}
		
		
		$this_end1 = clb_val('', $rec, RF_LINKS_END1);
		$this_end2 = clb_val('', $rec, RF_LINKS_END2);
		
		$flip = FALSE;
		if ($max == 1)	//if there is only one segment 
		{
			if ($raw)	//and we may need to reverse it based on the ends specified in the call to this function.
			{
				//if the position within the segment of the second point is before the first, then we need to reverse
				$flip = (isset($cut1['pos']) && isset($cut2['pos']) && ($cut2['pos'] < $cut1['pos']));	
			}
			else if ($pnum1)
			{
				$flip = ($this_end2 == $pnum1);	//on a stop to stop link, need to reverse if start pnum is at tail end of link
			}
			
			if ($flip) $flipfirst = count($aoe_data);
			
		}
		else if (($count <= 1) || ($this_end1 == $last_end2))	//first end of this matches the last end of the previous
		{
			//no action
		}
		else if (($count == 2) && ($this_end1 == $last_end1))	//need to reverse first segment on first loop
		{
			$points = array_reverse($points ,TRUE);	//TRUE=preserve_keys
			$flipfirst = count($points);
		}
		else if (($count == 2) && ($this_end2 == $last_end1))	//need to reverse first & second segment on second loop
		{
			$points = array_reverse($points ,TRUE);		//TRUE=preserve_keys
			$flipfirst = count($points);
			$flip = TRUE;
		}
		else
		{
			$flip = ($this_end2 == $last_end2);
		}
		
		if ($flip)
		{
			$aoe_data = array_reverse($aoe_data ,TRUE);	//TRUE=preserve_keys
			list($this_end1, $this_end2) = array($this_end2, $this_end1);	//reverse end points too		
		}
		
		//check if first point on this seg is same as last point on previous seg and if so remove it
		if (count($points) && count($aoe_data))
		{
			$last = end($points);
			$first = reset($aoe_data);
			//do an array_pop on the existing points rather than removing the duplicate from $aoe_data since that alters the cutting math on raw segs
			if ((round($last[0],5)==round($first[0],5)) && (round($last[1],5)==round($first[1],5))) array_pop($points);
		}
		
		$points = array_merge($points, $aoe_data);
		
		//$seg_idx[$this_end2] = count($points) - 1;
		
		$last_end1 = $this_end1;
		$last_end2 = $this_end2;
		
	}
	
	if ($raw)	//raw segments will probably need the ends trimming
	{
		//if the first seg was reversed then we want to keep the number of points on the other side
		$pos = (is_int($flipfirst) ? $flipfirst - $cut1['pos'] : $cut1['pos']);	
		//cut off end points and insert the stops own point.  If this is duplicate it will be cleaned by polyline later
		array_splice($points, 0, $pos, array(array($cut1['lat'], $cut1['lng'], 0)));	
		
		//if the last seg was reversed then we want to keep the number of points on the other side
		$pos = ($flip ? $cut2['pos'] : count($aoe_data) - $cut2['pos']);	//aoe_data still around after last itteration of loop
		//cut off end points and insert the stops own point.  If this is duplicate it will be cleaned by polyline later

		array_splice($points, -$pos, $pos, array(array($cut2['lat'], $cut2['lng'], 0)));	
//qlog(__FUNCTION__, __LINE__, $pnum1, $cut1, $pnum2, $cut2);
	}
	
	//return array($points, $seg_idx);	//not using this because the positions may change when making a polyline and makes returning the points list more cumbersome
	return $points;
}




/*
	given a set of arrays with parallel indicies, returns array of zero or more more keys where all elements match values
	used by pline_splice()
*/
function pline_arr_match($arr1, $val1)	//$arr2, val2, etc
{
	$keys = array_keys($arr1, $val1);	//gets only keys matching the first key
	foreach($keys AS $pos=>$key) for ($i=2; $i<func_num_args(); $i+=2)	
	{
		//now check values match on other arrays with same key, if not remove key dont bother checking others for this key
		$arr2 = func_get_arg($i);
		$val2 = func_get_arg($i+1);
		if (is_array($arr2) && (!isset($arr2[$key]) || ($arr2[$key] != $val2))) { unset($keys[$pos]); break; }
	}
	return $keys;
}



/*
	given the points list of a line finds the point on the line at mid point of its length
*/
function pline_midpoint($points) {
	$seg_len = 0;
	$dists = array();	//will hold the distance for each point so we can quickly find the one before the midpoint
	$last_pt = FALSE;
	$accum = array(0,0);	//rather than use the last point each time, use the accumulated differences to reduce error on each link
	foreach($points as $no=>$pt)
	{
					
		$pt[0] = round($pt[0],5);
		$pt[1] = round($pt[1],5);
		
		
		if (!$last_pt) {	//first loop use actual values, afterwards use differences
			$accum[0] = $pt[0];
			$accum[1] = $pt[1];
			
		} else {
				
			$dlat = ($pt[0]-$accum[0]);
			$dlng = ($pt[1]-$accum[1]);
			
			$accum[0] += $dlat;
			$accum[1] += $dlng;
	
			$dist = pline_surface_dist($pt[0], $pt[1], $last_pt[0], $last_pt[1]);
			
			$seg_len += $dist;
		}
		$last_pt[0] = $pt[0];
		$last_pt[1] = $pt[1];
		$dists[$no] = $seg_len;
	}
	$last_pt = FALSE;
	foreach($dists AS $no=>$pos) {
		if ($last_pt && ($pos > ($seg_len /2))) {
			$lat = ($points[$no][0]+$last_pt[0])/2;
			$lng = ($points[$no][1]+$last_pt[1])/2;
			$elev = ($points[$no][2]+$last_pt[2])/2;
			return array($lat,$lng,$elev);
		}
		$last_pt = $points[$no];
	}
	return FALSE;
}

//given two lat/lng points in return distance
function pline_surface_dist($lat1, $lng1, $lat2, $lng2)
{
	$lat1 = deg2rad($lat1);
	$lat2 = deg2rad($lat2);
	$lng1 = deg2rad($lng1);
	$lng2 = deg2rad($lng2);
	
	//calc the distance from this point to the previous one IN METERS
	return abs(acos(sin($lat1)*sin($lat2)+cos($lat1)*cos($lat2)* cos($lng2-$lng1)) * EARTH_RADIUS_M);
}



/*
	precalculations for distance from line.  These points are the line we will be taking a distance from.  So this is a point pair in a seg
	Usually the fisrt pair of coordinates are passed as 0 and the second ones as relative.
	There are three possible cases:
	0 - if the segment is horizontal the distance to the point is just the difference of latitudes
	1 - if the segment is null (ie both points coincident) then use pytagorus  between segment point and other point
	2 - true segment and not horizontal so use distance to line formula
*/
function pline_normal($seg, $y, $x)
{
	list($y1, $x1, $y2, $x2) = $seg;
	if ($y1 == $y2)
	{
		$method = 0;	//if the latitiudes of the end points are the same we would have a div by zero so will simply get difference of latitudes
		$B = $y1;	//save the original latitude in $B
		if ($x1 == $x2) $method = 1;	//if end points are coincident then simply do distance from test point to first
	}
	else
	{
		$method = 2;	//do actual point distance from a line methoduation
		$B = ($x2 - $x1) / ($y2 - $y1);
	}
	
	switch ($method) {
	case(2):	//two points, do point distance from line
		$denom = sqrt(1 + pow($B, 2));
		return abs(($x - ($B * $y)) / $denom);
		break;
	case(1):	//one point do pythag to get distance between points.
		return sqrt(($x* $x) + ($y * $y));
		break;
	case(0):	//find distance by difference of lattitudes
		return abs($y - $B); //here $B is $y1 from pre_dist_to_line
		break;
	}
	return 0;
}


/*
	converts the text in the aoe_data field into a points array from either point list or serialised array
*/
function pline_pts_arr($points)
{
	$aoe_data = clb_blob_dec($points);
	if (is_array($aoe_data)) return $aoe_data;
	
	//if the above does not decode the contents, assume this is a simple list of points
	if (preg_match_all('/^([^\s,]*)\s*,\s*([^\s,]*)\s*,\s*([^\s,]*)/m', $points, $raw_pts, PREG_SET_ORDER))
	{
		$aoe_data = array();
		foreach($raw_pts as $pt)
		{
			array_shift($pt);	//remove full match from the reg_exp array leaving lng, lat, elev
			$aoe_data[] = $pt;
		}
	}
	return $aoe_data;
}


/*
	
	see the below link for the explanation of how the paramteric formula is derived
	
	http://caffeineowl.com/graphics/2d/vectorial/bezierintro.html
	
	This is the parametric formular for a cubic besier curve where P1 and P2 are the start and end points and C1 and C2 are the control points
	
	Pn = (1-t)^3 * P1 + 3 * (1-t)^2 * t * C1 + 3 * (1-t) * t^2 * C2 + t^3 * P2 
	
	the control lines are divided into s segments which results in s-1 intermediate points or s+1 points when the ends are also included
	t is a ratio indicating the distance along the control paths (the actual distance along the drawn curve varies from point to point)
	1-t is the reverse ratio call it tr.  therefore  tr[n] = t[s-n] or if we calc one array of ratios, nr = s-n and tr[n] = t[nr] (so we nevercalculate the tr array)
	
	t never actually appears on its own in the parametric but only as t^3 (and (1-t)^3) or as (3 * t^2 * (1-t)) (and (3 * (1-t)^2 * t))
	
	so we can precalc arrays for t^3 and (3 * t^2 * (1-t)) and will call these $t3 and $t2t

 */
 
 /*
	The greater the number of curve segments, the smoother the curve, 
	and the longer it takes to generate and draw.  The number below was pulled 
	out of a hat, and seems to work o.k.
 */
DEFINE('B_SEGS', 16);

function pline_bez_calc($p1, $c1, $c2, $p2, $x=0, $y=1) {
	static $t3, $t2t;
	
	if (!isset($t3))
	{
		$t3[0] = 1;		// 1^3 = 1
		$t2t[0] = 0;	//one of the factors will be zero at the ends so the product will be zero
		for($s = 1 ; $s < B_SEGS ; $s++ ) {
			$t = $s / B_SEGS;
			$t3[$s] = pow((1 - $t), 3);
			$t2t[$s] = 3 * $t * pow(($t - 1), 2);
		}
		$t3[B_SEGS] = 0;
		$t2t[B_SEGS] = 0;
	}
	
	$points = array();
	$points[0] = $p1;
	for($s = 1 ; $s < B_SEGS ; $s++ ) {
		$sr = B_SEGS - $s;
		$points[$s][0] = ($t3[$s] * $p1[0]) + ($t2t[$s] * $c1[0]) + ($t2t[$sr] * $c2[0]) + ($t3[$sr] * $p2[0]);
		$points[$s][1] = ($t3[$s] * $p1[1]) + ($t2t[$s] * $c1[1]) + ($t2t[$sr] * $c2[1]) + ($t3[$sr] * $p2[1]);
	}
	$points[B_SEGS] = $p2;
	return $points;
}

/*
	function to take a list of points as anchors and return a larger set of points formaing a bezier curve using the points as anchors
	this function calculates the control points having an angle averaging the angle between points and extending by the $handle amount
	end points can either mirror the angles on the other end (soft) or they can align with the direct segment to the next point.
	$x and $y give the key values for the x and y values in the point arrays
*/
function pline_bez_make($points, $x=0, $y=1, $handle=0.33, $softends=false) {
	
	$end = count($points);
	
	if ($end < 3) return $points;	//need at least three points to make a curve.
		
	$points = array_values($points);	//ensure indicies are 0 to n-1
	$bezier = array();
	
	$last_angle = FALSE;
	//loops n-1 times since 3 points makes two segments, etc
	for($i=0; $i < ($end - 1); $i++) {
		$p1 = $points[$i];
		$p2 = $points[$i+1];
		//the distance between first and second points
		$dist = sqrt(pow($p2[$x] - $p1[$x], 2) + pow($p2[$y] - $p1[$y], 2));
		
		if (isset($points[$i+2])) {	//if this is not the last point
			$p3 = $points[$i+2];
			//both angles originating from the second point
			$a1 = atan2($p1[$y]-$p2[$y], $p1[$x]-$p2[$x]) + (pi()*2);
			$a2 = atan2($p3[$y]-$p2[$y], $p3[$x]-$p2[$x]) + (pi()*2);
			
			//average the angeles and wind back 90 degrees if first angle smaller or +90 if first angle larger
			$new_angle = (($a1 + $a2) / 2) + (($a1 < $a2) ? -(pi()/2) : (pi()/2));
			
		} else {
			//this loops $a1 is the reverse of last loops $a2
			$new_angle = ($a2 + pi()) - ($softends ? ($a2 - $last_angle) : 0);
		}
		
		//on first angle either use the reverse of the first segment direction or soften by reflecting the angle at the other end
		if ($last_angle === FALSE) $last_angle = ($a1 + pi()) - ($softends ? ($a1 - $new_angle) : 0);
		
		$c1[$x] = $p1[$x] + (cos($last_angle) * $handle * $dist);
		$c1[$y] = $p1[$y] + (sin($last_angle) * $handle * $dist);
		
		$c2[$x] = $p2[$x] + (cos($new_angle) * $handle * $dist);
		$c2[$y] = $p2[$y] + (sin($new_angle) * $handle * $dist);
		

		$new = pline_bez_calc($p1, $c1, $c2, $p2, $x, $y);
		
		$bezier = array_merge($bezier, $new);
		
		$last_angle = ($new_angle + pi());	//the tangent line on the point will point in the opposite direction for the next seg
	}
	
	return $bezier;
}

?>