<?php


function pfind_miles($m, $num=FALSE)
{
	$mi = round(($m / 1000) * 0.62137119, 1);
	if ($num) return $mi;
	return (($mi >= 0.25) ? $mi.' mi' :  round($m * 3.2808399).' ft');
}

function pfind_kilo($m)
{
	$km = round($m / 1000, 1);
	return (($km >= 1) ? $km.' km' :  $m.' m');
}

/*	for debugging, shows data structures only to a certain depth
*/
function pfind_shallow($tree, $target, $depth=0)
{
	if (!is_array($tree)) return $tree;
	if ($depth < $target) {
		foreach($tree AS $key => $branch) $tree[$key] = pfind_shallow($branch, $target, $depth+1);
		return $tree;
	} else return '('.count($tree).' nodes)';
}

/*
	$node1, $node2 - these could be pnums of stops or pnums of segments
	$links is the pre calculated array of links to be considered
	$margin = 1.2;	//stop following options if they are greater than the best found option by this margin
	$allowance = 4;  //allow a path to be 4 times as long as the direct distance before giving up
	
	the return value is an array of routes.  Each route is an array with the link pnums as keys and the shortest path network data as the value
	$result[] = array(linkpnum => array(r1, r2));
	
	$links = array(
		'nodes' => array(endcode => array(linkpnums)),
		'links' => array(pnum => array(dist, end1, end2, ptype, reverse, angle1, angle2);
	);
	
	** description of the stack & leaf system **
	$network_d is an array that holds one entry for each NODE of the system, nodes are added as they are traversed and there are no duplicates
	if the same node is reached more than once, details of each predecessor path are added to the node 
	but only the first path to reach a given node adds its onward links to the stack
	the 'dist' field on each node is the shortest distance to that node from the start node.
	
	$queue holds the pnum of the node to be progressed (ie the node at the other end of the link which was added) the key is the pnum, the value is the estimated distance
	
	$network_d[$node]['prev'] = array(
		'prev_node' => $noden,		//pnum of the node at the beginning of the link
		'link_pnum' => $linkpnum,	//pnum of the link itself
		'link_len' => $dist,		//
		'depth' => $last_depth+1,
		'common' => ($reverse ? clb_val(FALSE, $linkrec, 'routes2') : clb_val(FALSE, $linkrec, 'routes1'))
	)
	$network_d[$node]['node'] = pnum
	$network_d[$node]['dist'] = score
	
	$queue[$node] = $dist;	//holds a list of unprocessed leave nodes, not strictly a queue as we will asort it and work from the low scores

*/
function pfind_shortpath($node1, $node2, $links, $allowance = 4, $margin=1.1)
{

	clb_timing(__LINE__);
	
	//find the destination coordinates to evaluate if paths are getting nearer or further from target
	$crowfly=FALSE;
	$dist_limit = FALSE;
	$backwards = FALSE;
	
	//check origin(s)
	if (!is_array($node1)) $node1 = array($node1);
	foreach($node1 AS $i=>$pnum) if (!isset($links['nodes'][$pnum]) || empty($links['nodes'][$pnum]))
	{
		unset($node1[$i]);
		qlog(__FUNCTION__, __LINE__, 'unknown origin in shortpath', $pnum);
	}

	//check destination(s)
	if (!is_array($node2)) $node2 = array($node2);
	foreach($node2 AS $i=>$pnum) if (!isset($links['nodes'][$pnum]) || empty($links['nodes'][$pnum]))
	{
		unset($node2[$i]);
		qlog(__FUNCTION__, __LINE__, 'unknown destination in shortpath', $pnum);
	}
	
	//if no origin or destination then return without searching for a route
	if ((clb_count($node1) == 0) || (clb_count($node2) == 0)) return array();

	//if multiple origins but single destination, reverse direction, and reverse results at end
	if ((clb_count($node1) > 1) && (clb_count($node2) == 1))
	{
		list($node1, $node2) = array($node2, $node1);
		$backwards = TRUE;
	}

	$targets = array();
	foreach($node2 AS $i=>$pnum)	//get node2 coordinates from links on that node
	{
		$linkrec = FALSE;	//this gets set as a reference parameter in parse_str
		$temp = explode(',',$links['nodes'][$pnum]);
		$linkpnum = reset($temp);										//get first linkpnum on node
		
		if (isset($links['links'][$linkpnum])) parse_str($links['links'][$linkpnum], $linkrec);		//get link rec by linkpnum
		
		//$coords = clb_val(FALSE, $linkrec, (($pnum == clb_val('', $linkrec, 'end1')) ? 'pt1' : 'pt2'));	//get end point coords for node	
		$coords = ((isset($linkrec['end1']) && ($pnum == $linkrec['end1'])) ? 'pt1' : 'pt2');
		$coords = (isset($linkrec[$coords]) ? $linkrec[$coords] : FALSE);	//get end point coords for node	

		if ($coords) $targets[] = explode(',',$coords);	//we are just making a list of target positions so we know when we are getting close to any of them
	}
	
	$network_d = array();
	$queue = array();
	$dist_limit = 0;
	
	foreach($node1 AS $c=>$pnum)
	{
		$linkrec = FALSE;	//this gets set as a reference parameter in parse_str
		$temp = explode(',',$links['nodes'][$pnum]);
		$linkpnum = reset($temp);										//get first linkpnum on node
		
		if (isset($links['links'][$linkpnum])) parse_str($links['links'][$linkpnum], $linkrec);		//get link rec by linkpnum
		
		//$coords = clb_val(FALSE, $linkrec, (($pnum == clb_val('', $linkrec, 'end1')) ? 'pt1' : 'pt2'));	//get end point coords for node	
		$coords = ((isset($linkrec['end1']) && ($pnum == $linkrec['end1'])) ? 'pt1' : 'pt2');
		$coords = (isset($linkrec[$coords]) ? $linkrec[$coords] : FALSE);	//get end point coords for node	
		
		$pythag = 0;
		if ($coords) {	//check distance from this node to each of the destinations, and use the shortest distance
			$pt = explode(',',$coords);	//we are just making a list of target positions so we know when we are getting close to any of them
			foreach($targets AS $crowfly)
			{
				$temp = round(pline_surface_dist($pt[0], $pt[1], $crowfly[0], $crowfly[1]));	
				if (($pythag == 0 ) || ($temp < $pythag)) $pythag = $temp;
			}
		}
		
		$network_d[$pnum]['node'] = $pnum;
		$network_d[$pnum]['dist'] = 0;
		$network_d[$pnum]['depth'] = chr(48+($c%207));
		$network_d[$pnum]['prev'] = array();
		$network_d[$pnum]['ptype'] = '';	//ptype of link not node
		
		$queue[$pnum] = $pythag;	//the initial distance only really important when more than one origin.
		
		$dist_limit = max($pythag, $dist_limit);
	}
	
	$dist_limit *= $allowance;	//expand the allowed distance by the allowance multiplier
	
	$complete = array();	//collects one or more paths
	$best = FALSE;	//will hold the lowest score of an actual match so we can stop looking after other options get to large to be contenders

	$max_depth = 0;		//just for interest
	while (count($queue))
	{
		asort($queue);	//put lowest score first
// qlog(__LINE__, $queue);
		$max_depth = max($max_depth, count($queue));
		
		$est_dist = reset($queue);	//set first element current, and get estimated distance for this route
		$noden = key($queue);	//get the key (node pnum)
		array_shift($queue);	//remove item from queue since it will not need processing after this
		
		//$noden is the pnum of the node and is our index into the $network_d
		
		if (!isset($network_d[$noden])) continue;	//the parent should always exist, just being safe.
	
		$last_dist = $network_d[$noden]['dist'];	//distance up to the node on this leaf
		$last_depth = $network_d[$noden]['depth'];	//number of links up to the node on this leaf
			
		if (is_int($best))
		{
			//if we have a match and all remaining options are worse by a margin then exit this part of the process
			if (($best * $margin) < ($est_dist)) break;
				
			/*
				dist_limit is initially a multiple (eg 4)of the crofly distance from A to B 
				after a path has been found this is reduced to the best path times the margin (eg 1.1)
				so some queued items may have been under 4x limit when queued but now are longer than 1.1x best
				so skip those items but continue looping
			*/
			if ($dist_limit && ($est_dist > $dist_limit)) continue;
		}
		
		//if the only link to this node was by walking remember this and don't add walking links from here
		$walking_only = FALSE;
		if (isset($network_d[$noden]['prev']) && count($network_d[$noden]['prev']))
		{
			$only = reset($network_d[$noden]['prev']);
			$walking_only = ($only['ptype'] == 'walk');
		}
		
		//now loop though the candidate links from this node
		if (isset($links['nodes'][$noden]) && $links['nodes'][$noden])
		{
			$links_list = explode(',',$links['nodes'][$noden]);
			foreach($links_list AS $c=>$linkpnum)
			{
				if (array_key_exists($linkpnum, $network_d[$noden]['prev'])) continue;	//dont rescan any links already traversed towards this node
	
				$linkrec = FALSE;
				if (isset($links['links'][$linkpnum])) parse_str($links['links'][$linkpnum], $linkrec);
				
				//$dist = round($last_dist + clb_val(0, $linkrec, 'dist'), 3);	//add this link distance to the total				
				$dist = round($last_dist + (isset($linkrec['dist']) ? $linkrec['dist'] : 0), 3);	//add this link distance to the total				
							
				//$ptype = clb_val('', $linkrec, 'ptype');	//link ptype (same as nodes except for 'walk'
				$ptype = (isset($linkrec['ptype']) ? $linkrec['ptype'] : '');	//link ptype (same as nodes except for 'walk'
				
				if ($walking_only && ($ptype == 'walk')) continue;

				$end1 = (isset($linkrec['end1']) ? $linkrec['end1'] : '');	//clb_val('', $linkrec, 'end1');
				$end2 = (isset($linkrec['end2']) ? $linkrec['end2'] : '');	//clb_val('', $linkrec, 'end2');
				$reverse = ($noden == $end2);
				
				// qlog(__FUNCTION__, __LINE__, $linkpnum, $reverse, clb_val(0, $linkrec, 'reverse'));
				
				//when we have multiple origins, we are doing our short path backwards so we need to reverse our reverse for the real direction
				$real_direction = ($backwards ? !$reverse : $reverse);
				
				//non reversable link and an attempt to use it in the wrong direction, skip it
				//if ($real_direction && (clb_val(0, $linkrec, 'reverse') & 1)) continue;	//reverse value of 1 means not in reverse direction
				//if (!$real_direction && (clb_val(0, $linkrec, 'reverse') & 2)) continue;	//reverse value with 2 means not in forward direction
				if ($real_direction && ((isset($linkrec['reverse']) ? $linkrec['reverse'] : 0) & 1)) continue;	//reverse value of 1 means not in reverse direction
				if (!$real_direction && ((isset($linkrec['reverse']) ? $linkrec['reverse'] : 0) & 2)) continue;	//reverse value with 2 means not in forward direction
							
				$otherend = ($reverse ? $end1 : $end2);
				
				if (isset($network_d[$otherend]))	//we already have an entry for the node on the other end of this link
				{
					//if the node has been seen before then we will not add it to the queue, but we will record how we got to it after the else.
					//if the node reached happens to be the destination, then it is already recorded as such and no need to add it again.
					
					//scan previous routes to this node
					if (is_array($network_d[$otherend]['prev'])) foreach($network_d[$otherend]['prev'] AS $link => $prev)
					{
						$round_err = max(strlen($last_depth)+1, strlen($prev['depth']));	//allow a meter of rounding error for each connection

						if (abs($prev['path_dist'] - $dist) < $round_err)
						{	
							//if the distance to the node within a small margin is the same, we will assume these are the same path but perhaps with different numbers of stops
							if (strpos($last_depth, $prev['depth']) === 0) //if the current depth path starts with the path that reached this node
							{
								$linkpnum = FALSE;	//dont follow this link as we have been there before on this thread
								break;
							}
							else if (strlen($last_depth)+1 > strlen($prev['depth']))	//this path has more stops so keep it rather than the other one
							{							
								unset($network_d[$otherend]['prev'][$link]);
							}
							else
							{
								$linkpnum = FALSE;	//existing route had more stops so will not add this link
								break;
							}
						}
					}
				}
				else
				{
					if (in_array($otherend, $node2))	//we have reached our destination, 
					{
//qlog(__LINE__, clb_timing('complete '.count($complete)), $dist);					
						$complete[] = $otherend;	//remember the end leaf and the distance so we can easily rank results.
						if (is_bool($best) || ($dist<$best))
						{
							$best = $dist;
							$dist_limit = round($best * $margin);
							
						}
					}
					else	//if not a match add this to the queue to see if we can get there from here.
					{
						
						//if we have coords for the end point, see how far away this link leaves us and add this to the dist
						$pythag = 0;	
						if ($targets)
						{	
							//$pt = clb_val(FALSE, $linkrec, ($reverse ? 'pt1' : 'pt2'));
							$pt = ($reverse ? 'pt1' : 'pt2');
							$pt = (isset($linkrec[$pt]) ? $linkrec[$pt] : FALSE);
							if ($pt) $pt = explode(',',$pt);
							
							if ($pt) foreach($targets AS $crowfly)	//use the distance to the nearest destination point
							{
								$temp = round(pline_surface_dist($pt[0], $pt[1], $crowfly[0], $crowfly[1]));	
								if (($pythag == 0 ) || ($temp < $pythag)) $pythag = $temp;
							}
						}
						
						if ($dist_limit && (($dist + $pythag) > $dist_limit))
						{
							//this is a natural thing to happen as less promising paths are abandoned, no need to log these unless debugging
							//qlog(__FUNCTION__, __LINE__, 'branch abondoned as route longer than distance', $dist_limit, $node1, $linkpnum, $last_dist, $dist, $pythag);
							continue;
						}
	// 	qlog(__LINE__, count($queue), $complete, $last_dist, $dist, $pythag, ($dist+$pythag), $dist_limit);
						
						//add this leaf to the processing queue
						$queue[$otherend] = round($dist + $pythag);	//this will find shortest path first
					}
					
					$network_d[$otherend]['node'] = $otherend;
					$network_d[$otherend]['depth'] = $last_depth.chr(48+($c%207));
					$network_d[$otherend]['dist'] = $dist;	//first one here will be shortest so keep that 					
				}
				
				if ($linkpnum)
				{
					$network_d[$otherend]['prev'][$linkpnum] = array(
						'prev_node' => $noden,	//added for reverse traversal
						'link_pnum' => $linkpnum,
						'link_len' => (isset($linkrec['dist']) ? $linkrec['dist'] : 0),	//clb_val(0, $linkrec['dist']),
						//'common' => ($reverse ? clb_val(FALSE, $linkrec, 'routes2') : clb_val(FALSE, $linkrec, 'routes1')),
						'common' => ($reverse ? (isset($linkrec['routes2']) ? $linkrec['routes2'] : FALSE) : (isset($linkrec['routes1']) ? $linkrec['routes1'] : FALSE)),
						'ptype' => $ptype,	//ptype of link not node
						
						'depth' => $network_d[$otherend]['depth'],	//.chr(48+($c%207)),
						'path_dist' => $dist		//total distance so far, including the length of this link
					);
				}
			}
		}
	}
	
qlog(__LINE__, 'max_depth', $max_depth, 'tree size', count($network_d));

// qlog(__LINE__, $network_d);
//qlog(__LINE__,$node1, $node2, $complete);
	clb_timing('shortest path');

	if (count($complete))
	{
		//now run the shortest path on the new network of shortest paths but measure shortest by number of changes
		$seq=0;
		$network_c = array();
		$queue = array();
		$queue_sort = array();	//since change distances will be the same a lot of the time inclde a parallel distance array to pick the shortest within change groups
		
		foreach($complete AS $pnum)
		{
			$pos_c = $seq++;
			$network_c[$pos_c]['node'] = $pnum;	//start at the destination end 
			$network_c[$pos_c]['dist'] = 0;			//distance up to the node on this leaf
			$network_c[$pos_c]['changes'] = 0;		//number of changes to get to this leaf
			$network_c[$pos_c]['link'] = FALSE;		//link pnum from the parent
			$network_c[$pos_c]['routes'] = array();	//routes that the node was arrived through
			$network_c[$pos_c]['past'] = array();	//will build up list of past nodes going forward
			$network_c[$pos_c]['ptype'] = '';		//used to check we do not get multiple 'walk' links in a row
			 
			$queue['x'.$pos_c] = 0;	//the initial distance makes little difference since it is only used to choose which of the one items we use.
			
			$queue_sort['x'.$pos_c] = 0;
		}
		
		$complete = array();	//collects one or more paths
		$best = FALSE;	//will hold the lowest score of an actual match so we can stop looking after other options get to large to be contenders
		$range = 2;	//allow range of best+2 extra stops
		
		$used = array();
		
		while (count($queue))
		{
			//asort($queue);	//put lowest score first
			array_multisort($queue, SORT_ASC, $queue_sort,  SORT_DESC); //$queue = changes, $queue_sort = dist
			
// 	qlog(__LINE__, $queue);
	
			$est_changes = reset($queue);	//set first element current, and get estimated distance for this route
			$cursor = 1*substr(key($queue),1);	//get the key removing 'x' which makes the keys strings and prevents renumbering on arrays shift.
			array_shift($queue);	//remove item from queue since it will not need processing after this
			array_shift($queue_sort);	//remove item from queue since it will not need processing after this
			
			//$cursor is our index into the $network_c
			
			if (!isset($network_c[$cursor])) continue;	//the parent should always exist, just being safe.
		
			$noden = $network_c[$cursor]['node'];			//$noden is the node identifier of the leaf we want to continue the path from
			$last_dist = $network_c[$cursor]['dist'];		//distance up to the node on this leaf
			$last_changes = $network_c[$cursor]['changes'];	//number of changes to get to this leaf
			$last_routes = $network_c[$cursor]['routes'];	//routes that the node was arrived through

			$last_link = $network_c[$cursor]['link'];		//link pnum from the parent
			$past = $network_c[$pos_c]['past'];				//will build up list of past going forward
			$past[] = $last_link;

			$last_ptype = $network_c[$cursor]['ptype'];		//link pnum from the parent
			
			//if we have a match and all remaining options are worse by a margin then stop looking
			if (count($complete) && (($best + $range) < $est_changes)) break;

 			$used[$noden] = $pos_c;
			
			//our "links" are now coming from the leaves of the previous shortest path structure			
			//$old_leaf = clb_val(FALSE, $network_d, $noden);	//get actual node
			$old_leaf = (isset($network_d[$noden]) ? $network_d[$noden] : FALSE);	//get actual node
			
			//$preds = clb_val(FALSE, $old_leaf, 'prev');		//the predecessors to that node
			$preds = (isset($old_leaf['prev']) ? $old_leaf['prev'] : FALSE);		//the predecessors to that node
			
			//now loop though the candidate links from this node
			if (clb_count($preds)) foreach($preds AS $via_link => $pre_link)
			{
				//$ptype = clb_val('', $pre_link, 'ptype');
				$ptype = (isset($pre_link['ptype']) ? $pre_link['ptype'] : '');
// qlog(__LINE__,$ptype, $last_ptype, $noden, (isset($pre_link['prev_node']) ? $pre_link['prev_node'] : ''));		

				if (($ptype == 'walk') && ($last_ptype == 'walk')) continue;	//don't allow a route to be constructed from a series of walk interconnections
				
				if (in_array($via_link, $past)) continue;	//already have this link on this path so skip it
				if ($via_link == $last_link) continue;	//dont double back, this test should be redundent due to the previous line
				
				//$remainder = clb_val(0, $pre_link, 'path_dist');	//each pre link from the network_d array knows how far it is from the origin as a minimum
				$remainder = (isset($pre_link['path_dist']) ? $pre_link['path_dist'] : 0);	//each pre link from the network_d array knows how far it is from the origin as a minimum
				
 //qlog(__LINE__, $last_dist, $remainder, ($last_dist + $remainder), $dist_limit, ((($last_dist + $remainder) > $dist_limit)?' culled':''));
				if (($last_dist + $remainder) > $dist_limit) continue;	//when expanding routes, combinations may get long so chop them out

				//$dest_node = clb_val('', $pre_link,'prev_node');
				$dest_node = (isset($pre_link['prev_node']) ? $pre_link['prev_node'] : '');
				
				//$link_len = clb_val(0, $pre_link, 'link_len');
				$link_len = (isset($pre_link['link_len']) ? $pre_link['link_len'] : 0);
				
				$dist = $last_dist + $link_len;
				
				//$link_routes = clb_val(FALSE, $pre_link, 'common');
				$link_routes = (isset($pre_link['common']) ? $pre_link['common'] : FALSE);
				
				$link_routes = ($link_routes ? explode(',',$link_routes) : array());	//convert comma separated list into array
				
				$routes_common = array_intersect($last_routes, $link_routes);
				
				$changes = ((count($routes_common) == 0) ? 2 : 0);	//if no common routes then give a change a 2 score
				
				if ($changes)	//no common routes, so need there is a change needed
				{
					//see if there are any routes which have the same beginning up to '_' which indicates a branch on a line like underground lines
					foreach($last_routes AS $rl) if (clb_contains($rl, '_'))
					{
						foreach($link_routes AS $rn) if (clb_contains($rn, '_'))
						{
							if (preg_replace('/_.*$/','', $rl) == preg_replace('/_.*$/','', $rn)) {
								$changes = 1;
								break;
							}
						}
						if ($changes == 1) break;
					}
					
					$routes_common = $link_routes;	//since we are changing, can now use all routes between the two nodes
				}
				$changes += $last_changes;
				
				if (is_int($best) && ($changes > ($best + $range))) continue;
				
				
				$pos_c = $seq++;	//prepare the index for the $network_c array, we may skip the node but do not mind gaps
				$network_c[$pos_c]['node'] = $dest_node;				//start at the destination end 
				$network_c[$pos_c]['prev'] = $cursor;				//way back to the previous leaf
				$network_c[$pos_c]['dist'] = $dist;					//distance up to the node on this leaf
				$network_c[$pos_c]['changes'] = $changes;			//number of changes to get to this leaf
				$network_c[$pos_c]['link'] = $via_link;				//link pnum from the parent
				$network_c[$pos_c]['routes'] = $routes_common;	//routes that the node was arrived through
				$network_c[$pos_c]['past'] = $past;	//will build up list of past going forward
				$network_c[$pos_c]['ptype'] = $ptype;	
								
// qlog(__LINE__,$dest_node, $node1);

				if (in_array($dest_node, $node1))	//have reached other end
				{
					$complete[] = $pos_c;
					if (is_bool($best) || ($changes < $best)) $best = $changes;

				}
				else
				{
					$queue['x'.$pos_c] = $changes;	//primary sort on changes keeps minimum changes top of list
					$queue_sort['x'.$pos_c] = $dist;	//distance ranks routes with same number of changes.
				}
			}
		}
	}
	
	$result = array();
	if (is_array($complete)) foreach($complete AS $pos_c)
	{
		$run = array();
		while($link = $network_c[$pos_c]['link'])
		{
			$run[$link] = $network_c[$pos_c];	//['routes'];
			$pos_c = $network_c[$pos_c]['prev'];
		}
		if ($backwards) $run = array_reverse($run);
		$result[] = $run;
	}
	
//qlog(__LINE__,count($complete), count($used), count($network_c), count($network_d));

//qlog(__LINE__, clb_timing('shortest changes'));
//qlog(__LINE__, $result);

	return $result;
}


/*
	when using precalculated distances this method finds distances based on end pairs
	and returns a fake list of shortest paths
	
	there can still be multiple origins and destinations and these may be split into 
	platforms so there are a number of AB distances that can be found and offered
	as alternatives
	
	eg here are two alternatives from basingstoke to bristol
	
array (
  0 => 
  array (
    'P_MXWIPTF/P_QZYHVP' => 
    array (
      'end1' => 'P_MXWIPTF',
      'end2' => 'P_QZYHVP',
      'dest' => 'P_QZYHVP',
      'dist' => '146540',
    ),
  ),
  1 => 
  array (
    'P_MXWIPTF/P_TUQDOU' => 
    array (
      'end1' => 'P_MXWIPTF',
      'end2' => 'P_TUQDOU',
      'dest' => 'P_TUQDOU',
      'dist' => '142272',
    ),
  ),
)
	

*/
function pfind_precalc_dist($orig, $dest, $links, & $stops)
{
	global $wpdb;
	
	$stops = array();
	$journies = array();
	
	/*
		product the orig X dest lists, substituting stations for platforms if needed and putting low nodes first
		since distances are held as station to station, and we may be given platforms we are swapping values
		contra to the normal of going to platforms before doing short path.
	*/
	$list = array();
	foreach($orig AS $io=>$o)
	{
		$node1 = (($links && isset($links['plat2stat'][$o])) ? $links['plat2stat'][$o] : $o);
		$orig[$io] = $node1;
		
		foreach($dest AS $d)
		{
			$node2 = (($links && isset($links['plat2stat'][$d])) ? $dest[$id] = $links['plat2stat'][$d] : $d);
			
			$list[] = (($node1 < $node2) ? $node1.'/'.$node2 : $node2.'/'.$node1);
		}
	}
	
	
	if (count($list)) 
	{
		$query = '';
		foreach($list AS $pair)
		{
			$pair = explode('/',$pair);
			$query .= '(a="'.$pair[0].'" AND b="'.$pair[1].'") OR ';
		}
		$query = 'SELECT * FROM '.RF_DISTS_FROM.' WHERE '.rtrim($query,'OR ');
		$options = $wpdb->get_results($query, ARRAY_A);
		if (is_array($options)) foreach($options AS $rec)
		{
			$end1 = $rec['a'];	//these are pnums
			$end2 = $rec['b'];
			
			//if a not in the origin list then swap ends
			if (!in_array($end1, $orig)) list($end1, $end2) = array($end2, $end1);
			
			$journies[][$end1.'/'.$end2] = array('end1'=>$end1, 'end2'=>$end2, 'dest'=>$end2, 'dist'=>$rec['dist']);
			$stops[$end1] = TRUE;
			$stops[$end2] = TRUE;
		}
	}
	return $journies;
}




/*
	this builds the steps between two waypoints and calls the actual shortest path routine.
	
	The shortest path may return more than one route, this method makes steps for all of them and gives them scores
	
	It returns an array of all candidates so caller can choose on score or return more than one option
	
	$orig, $dest - pnums of nodes, can be arrays of places which will be producted
	
	$links - array produced by journey_links() there will be different arrays for differnet modal combinations, just rail, just tube, just bus or combos  
	
	$candidates[r_no]['steps'][step] = 
		'dist' : distance for step, 
		'desc' : text describing step,
		'lat', 'lng'
		'duration'	: timing of step not supported
		'polyindex' : position within polyline not supported
					
	$candidates[r_no]['orig'] = array of fields from the place record for that node pnum, ptype, name, addr, lat, lng
	$candidates[r_no]['dest'] = array of fields from the place record for that node pnum, ptype, name, addr, lat, lng
	
	$candidates[r_no]['scores'] = array(
		'meters'=>x, 	//total distance
		'stops'=>x, 	//number of nodes
		'maxchanges'=>x, 	//maximum number of changes, includes soft changes like on tube line branches
		'minchanges'=>x		//minimum number of changes
		more?
		duration // not currently supported
	);
*/
function pfind_steps($orig, $dest, $links, $type_names, $dist_only=FALSE)
{
		
	global $wpdb;
	$stops = array();	//collect stop/station/node pnums so we can do a single db hit for their details
	$r_index = array();	//collect possible routes from stops
	$journies = array();
	$short_paths = array();
	
	//shortpath can work on multiple origins and destinations at the same time, woohoo!
	//but if only given one then wrap in an array
	if (!is_array($orig)) $orig = array($orig);
	if (!is_array($dest)) $dest = array($dest);
	
	//if dist_only look in precalculated table
	if ($dist_only)
	{
		$short_paths = $journies = pfind_precalc_dist($orig, $dest, $links, $stops);
qlog(__LINE__, $dist_only, $journies);
	}
	else
	{
		if (!count($journies)) $short_paths = pfind_shortpath($orig, $dest, $links);
//qlog(__LINE__, $dist_only, $short_paths);
	}
	
	clb_timing(__LINE__);

	/*
		The $short_paths array returned by pfind_shortpath() contains link pnums as keys, 
		and a list of routes on that link as the value as follows
		$short_paths[] = array(linkpnum1=>array(r1,r2), linkpnum2=>array(r2,r4),...);
		
		The first loop builds up the $journies by looking up the link details for the link pnums
		and then adding an indiction of which end point is the destination, its own link pnum and the list of routes on the link
		
		$journies[path_no][step] = array(dist, end1, end2, angle1, angle2) + array(dest, linkpnum, routes)	
	*/
	
	if (!count($journies)) foreach($short_paths AS $no => $alternative)
	{
		/*
			when there are multiple start points in orig, then we need to determine which one the shortest path chose
			to start from.  Normally only one end of the first link will match a pnum of a node in orig
		*/
		reset($alternative);	//getting first item and its key, $routes not actually used here
		$linkpnum = key($alternative);

		$linkrec = FALSE;	//link data stored in structure as query string, which needs parsing.
		if ($links && isset($links['links'][$linkpnum])) parse_str($links['links'][$linkpnum], $linkrec);
		
		$node1 = $linkrec['end1'];
		$node2 = $linkrec['end2'];

		//the first stop may be a platform but that is OK, we only need to convert the node for the geocode and not for the path following
		$in_orig1 = in_array($node1, $orig);
		$in_orig2 = in_array($node2, $orig);
		
		if ($in_orig1 && $in_orig2)	//if both ends of first segment are in list of possible origins
		{
			/*
				eg as heathrow will have all three in the orig selection, and a link from T4 to T123 will cause this
				the user has typed "heathrow" which has T4 and T123 stops as possible start points, and some segments will go 
				from t4 to t123 if the route starts at T4 and goes to T123 then we do not want to choose T123 as the start as
				we will then hit T4 and a dead end.  We need to ensure that we pick the true start.
			*/
			
			//getting NEXT item and its key
			$routes = next($alternative);	// $routes not actually used here
			$linkpnum = key($alternative);
			$linkrec = FALSE;
			if ($links && isset($links['links'][$linkpnum])) parse_str($links['links'][$linkpnum], $linkrec);
			
			//and the point of all this is that if the end we picked to start with is the one that connects to the second link, swap node2 for node1
			//$linkrec is the second link, and $node1 is the link we were going to start with but if it is in the second link then start with $node2
			if (($linkrec['end1'] == $node1) || ($linkrec['end2'] == $node1)) $node1 = $node2;	//this line is correct, both tests are meant to have $node1
			
		}
		else if ($in_orig1)
		{
			$node1 = $linkrec['end1'];
			
		}
		else if ($in_orig2)
		{
			$node1 = $linkrec['end2'];
			
		}
		else
		{
			qlog(__FUNCTION__, __LINE__, 'first segment did not match any points in origin', $linkpnum, $orig, $dest, $linkrec);
			continue;	//skip this path
		}
		
		
		//potentially convert platform to station for list of stops to look up
		$stop_pnum = (($links && isset($links['plat2stat'][$node1])) ? $links['plat2stat'][$node1] : $node1);
		$stops[$stop_pnum] = TRUE;	//collect a unique list of stops in routes
		
		$journey = array();
		foreach($alternative AS $linkpnum=>$rec)	//a route is a list of link pnums as keys and an array of routes on that link as values
		{
			$routes = $rec['routes'];
			
			$linkrec = FALSE;	//link data stored in structure as query string, which needs parsing.
			if ($links && isset($links['links'][$linkpnum])) parse_str($links['links'][$linkpnum], $linkrec);
			
			if (!$linkrec)
			{
				qlog(__FUNCTION__, __LINE__, 'link pnum not in links array!', $linkpnum);
				$journey = array();	//kill the journey
				break;
			}
			
			if (($linkrec['end1'] != $node1) && ($linkrec['end2'] != $node1))
			{
				qlog(__FUNCTION__, __LINE__, 'expected node not found in link!', $linkpnum, $node1, $node2, $no, count($journey), array_keys($alternative));	//, $linkrec);
				$journey = array();	//kill the journey
				break;
			}
			
			$node2 = (($linkrec['end1'] != $node1) ? $linkrec['end1'] : $linkrec['end2']);
			
			//get list of routes that this stop is on
			$common_routes = (($linkrec['end1'] != $node1) ? 'routes1' : 'routes2');
			$common_routes = clb_val(FALSE, $linkrec, $common_routes);	//walk links dont have routes
			if ($common_routes)
			{
				$common_routes = explode(',', $common_routes);
				foreach($common_routes AS $rnum) $r_index[trim($rnum,'.')] = 1;	//unique list in keys
			}

			
			//potentially convert platform to station for list of stops to look up
			$stop_pnum = (($links && isset($links['plat2stat'][$node2])) ? $links['plat2stat'][$node2] : $node2);
			$stops[$stop_pnum] = TRUE;	//collect a unique list of stops in routes

			$linkrec['dest'] = $node2;	//already have end1 and end2 but need a way to know which order they are being used.
			$linkrec['link'] = $linkpnum;	//keep pnum of the link in case we want it or just for debug
			$linkrec['routes'] = $routes;		//the list of routes usable between the two nodes
			
			$journey[] = $linkrec;
			
			$node1 = $node2;
		}
		if (count($journey)) $journies[$no] = $journey;
	}

	
	/*
		when processing the links we kept a list of stop ids so we can now search for them all in one DB hit.
	*/
	if (count($stops))
	{
		//get all of the stop records for the stop places, so we know which stops are on which routes, alo get the place names & types for output
		//'SELECT pnum, ptype, title, more, lat, lng FROM places ';
		$select = 'SELECT '.RF_NODES_SELECT.' FROM '.RF_NODES_FROM.' ';	
		$stop_pnum_str = clb_join(array_keys($stops), TRUE);
		$pindex = $wpdb->get_results($select.' WHERE '.RF_NODES_KEY.' IN '.$stop_pnum_str, ARRAY_A);
		$pindex = clb_rekey($pindex, RF_NODES_KEY);
		
		$rlist = array();	//since we will have to describe routes get the names from the routes table
		if (clb_count($r_index))
		{
			//'SELECT rnum, title, origin, destination, ptype FROM routes ';
			$select = 'SELECT '.RF_ROUTES_SELECT.' FROM '.RF_ROUTES_FROM.' WHERE '.RF_ROUTES_TYPE.'!="" AND '.RF_ROUTES_KEY.' IN '.clb_join(array_keys($r_index), TRUE);
			$rlist = $wpdb->get_results($select, ARRAY_A);
			$rlist = clb_rekey($rlist, RF_ROUTES_KEY);
		}
	}

	$candidates = array();	//will hold scoring info for each alternative route so we can pick the best
	$cache = array();
	
$compare = array();
$stoplist = array();

	//rescan all routes to build stages and calculate scores
	foreach($journies AS $no=>$alternative)
	{
		//get the list of seg pnums from the original short_paths array to make polyline later maybe
		$candidates[$no]['links'] = array_keys($short_paths[$no]);
		
		$candidates[$no]['scores'] = array(
			'meters'=>0, 		//total distance
			'stops'=>0, 		//number of nodes
			'duration'=>0, 		//duration **not currently supported**
			'modechanges'=>0, 	//count the number of mode changes as another metric
			'maxchanges'=>0, 	//maximum number of changes, includes soft changes like on tube line branches
			'minchanges'=>0		//minimum number of changes
		);

		/*
			get the array of info for the first link and identify the start end by it not being the dest end,
			convert from platform to station if needed
		*/
		$linkrec = reset($alternative);
		$node1 = (($linkrec['end2'] == $linkrec['dest']) ? $linkrec['end1'] : $linkrec['end2']);
		$stop_pnum =  (($links && isset($links['plat2stat'][$node1])) ? $links['plat2stat'][$node1] : $node1);

		$candidates[$no]['orig'] = clb_val('', $pindex, $stop_pnum);	//get details of first node place for caller
		
		//contains the lat/lng of the first place which we will use on first step, pt1/pt2 not in record if using distance lookup
		$last_place = '';
		if (isset($linkrec['pt1'])) $last_place = (($linkrec['end2'] == $linkrec['dest']) ? $linkrec['pt1'] : $linkrec['pt2']);

		$step_text = '';	//text for the step
		$last_mode = '';
		$step_idx = 0;
		
		//routes can only be followed in ascending distance order, so track position on each route and eliminate routes going backwards
		
		$routes_last = FALSE;
		$routes_common = FALSE;
		$old_common = FALSE;

		$place = clb_val('', $pindex, $stop_pnum);	//just getting this so we can monitor mode changes
		$ptype = clb_val('', $place, RF_NODES_TYPE);
		
		$total_dist = 0;
		$alternative[] = FALSE;	//add an element to the array to get an extra loop at the end, for the last stop/change
		foreach($alternative AS $link_no => $linkrec)
		{
			if ($linkrec === FALSE) {	//on the last loop there is no link, just have to finish off the last instruction
				$change = TRUE;
				$dist = 0;	//we will already have added this distance
				
			} else {
				$node2 = clb_val('', $linkrec, 'dest');
				$dist = clb_val(0, $linkrec,'dist');
				$ptype = clb_val('', $linkrec, RF_NODES_TYPE);


				//get list of routes that this stop is on
				$routes_this = clb_val(FALSE, $linkrec, 'routes');
				
				//initialise these on first loop
				if ($link_no == 0) {	//first loop is 0 
					$routes_last = $routes_common = $routes_this;
					$change = FALSE;
					
				} else {	
					$routes_common = array_intersect($routes_common, $routes_this);
					$change = ($link_no && (count($routes_common) == 0));	//dont change on the first link
				}
				$soft = FALSE;
								
				if ($change) {	//no common routes, so need there is a change needed
					
					//see if there are any routes which have the same beginning up to '_' which indicates a branch on a line like underground lines
					if ($ptype == 'tube') foreach($routes_last AS $rl) if (clb_contains($rl, '_')) {
						foreach($routes_this AS $rn) if (clb_contains($rn, '_')) {
	
							if (preg_replace('/_.*$/','', $rl) == preg_replace('/_.*$/','', $rn)) {
								$soft = TRUE;
								break;
							}
						}
						if (!$soft) break;
					}
					
					//if this is a soft change and the previous seg only had one option then , not really a change in this direction, so dont advance index
					//for instance, coming from amersham on metropolitain, will not require a change when another branch joins the same track, whereas a change may be needed in the other direction
	
					if ($soft && (count($routes_last) == 1)) {
						//$change = FALSE;
					}
					
					if ($change) $routes_common =  $routes_this;	//can continue on any of the routes listed on this seg since we do not have to match with prev seg.
				}
		//debug code to show sequence of stops being passed and *** on the ones where changes are made		
// $stop_pnum =  (isset($links['plat2stat'][$node1]) ? $links['plat2stat'][$node1] : $node1);
// $place = clb_val('', $pindex, $stop_pnum);			
// $stoplist[$no][] = ($change?'*** ':'').clb_val('', $place, 'name');
				
			}
			
			if ($change)
			{
				$stop_pnum =  (($links && isset($links['plat2stat'][$node1])) ? $links['plat2stat'][$node1] : $node1);
				
				$place = clb_val('', $pindex, $stop_pnum);
				$name = clb_val('', $place, RF_NODES_NAME);
				$addr = clb_val('', $place, RF_NODES_DESC);
				$ptype = clb_val('', $place, RF_NODES_TYPE);
				
				$type_name = clb_val('', $type_names, $ptype, 'label');
				
				switch($ptype) {
				case ('stop'):	
					$step_text = htmlentities('To ').clb_tag('b',$name).htmlentities(' / '.$addr.', '.$type_name);
					break;
					
				case ('rail'):	
					$step_text = htmlentities('To ').clb_tag('b',$name).htmlentities(', '.$type_name);	//.htmlentities(join('/',$old_common));
					break;
				
				case ('tube'):	
					$code = join(',',$old_common);
					if (isset($cache[$code])) {
						$txt = $cache[$code];
					} else {
						$txt = '';
						if (count($old_common) > 0) {
							$txt = 'Take '.((count($old_common)>1) ? 'any of ' : '');
							//build list of lines and branches
							foreach($old_common AS $rnum)
							{
								$row = clb_val(FALSE, $rlist, trim($rnum,'.'));
								$txt .= clb_val('', $row, RF_ROUTES_NAME).' '.clb_val('', $row, (clb_contains($rnum,'.') ? RF_ROUTES_ORIG : RF_ROUTES_DEST)).', ';
							}
							$txt = htmlentities(trim($txt,', ').' to ');
							$cache[$code] = $txt;
						}
					}
					$step_text = $txt.clb_tag('b',$name).htmlentities(', '.$type_name);
					break;
					
				default:
					$step_text = htmlentities($type_name.' '.$name);
					break;
				}
				
				if ($link_ptype == 'walk')
				{
					$step_text = 'Walk to '.$step_text;
					$candidates[$no]['scores']['meters'] -= $total_dist;	//don't count interchange walks in the trip distance.
					//the above is for the carbon calculations, if want to include walk dists then move line to after the divide by 2 line so that we only take off half of the distance.
					$total_dist = round($total_dist / 2);	//walk distances are doubled for the short path algorithm so correct 
				}
				
				$last_place = preg_split('/\s*,\s*/',$last_place);
				$candidates[$no]['steps'][$step_idx] = array(
					'dist'=>$total_dist, //for this step
					'desc'=>$step_text,
					'lat'=>clb_val(0, $last_place, 0),
					'lng'=>clb_val(0, $last_place, 1),
					
					'duration'=>0,						//currently no system to calculate durations
					'polyindex'=>0						//not providing index into polyline
				);
				
				//on each pass keep a copy of the end of the line point, on last loop there is no line so check linkrec not false.
				if ($linkrec && isset($linkrec['pt1']))
				{
					$candidates[$no]['EndLatLng'] = (($linkrec['end2'] == $linkrec['dest']) ? $linkrec['pt2'] : $linkrec['pt1']);			
					$last_place = (($linkrec['end2'] == $linkrec['dest']) ? $linkrec['pt1'] : $linkrec['pt2']);	//keep this nodes coords for use in next step.
				}
				$step_idx++;	//change means new step
				$total_dist = 0;
			}
			$total_dist += $dist;
			
			
			
//qlog(__LINE__, $no, $step_idx, ($change?1:0), $total_dist, $routes_this, $candidates[$no]['steps'][$step_idx]['desc']);
			
			$candidates[$no]['scores']['meters'] += $dist;
			$candidates[$no]['scores']['stops'] += 1;

			//also only now do we choose which route we have been travelling on up to the point at the beginning of this link
			if ($change) $candidates[$no]['scores']['maxchanges'] += 1;
			
			//if no soft change then increase the minimum number of changes
			if ($change && !$soft) $candidates[$no]['scores']['minchanges'] += 1;
			
			//track the number of mode changes
			if ($last_mode != $ptype) $candidates[$no]['scores']['modechanges'] += 1;
			
			$old_common = $routes_common;
			$routes_last = $routes_this;	//carry over the set of routes so that it can be compared with routes to the next node
			$last_mode = $ptype;
			$node1 = $node2;
			$link_ptype = clb_val('', $linkrec, RF_NODES_TYPE);			
			
		}
		$stop_pnum =  (($links && isset($links['plat2stat'][$node1])) ? $links['plat2stat'][$node1] : $node1);		
		$candidates[$no]['dest'] = clb_val('', $pindex, $stop_pnum);	//get details of final node place for caller
		
		$meters = $candidates[$no]['scores']['meters'];
		if (!isset($compare[$meters])) $compare[$meters] = 0;
		$compare[$meters]++;
	}
	
qlog(__LINE__,clb_timing('expansion'), count($candidates));
// qlog($compare);
// qlog($stoplist);
	return $candidates;
	
}


/*
	Given a list of to or more waypoints (arrays containing lat, lng + other attributes of the node)
	this method calls pfind_steps to find routes and creates steps between waypoints
	it creates the structure describing the route and its substeps
		
G_GEO_SUCCESS (200) 			No errors occurred; the address was successfully parsed and its geocode has been returned. (Since 2.55)
G_GEO_BAD_REQUEST (400)			A directions request could not be successfully parsed. (Since 2.81)
G_GEO_SERVER_ERROR (500)		A geocoding or directions request could not be successfully processed, yet the exact reason for the failure is not known. (Since 2.55)
G_GEO_MISSING_QUERY (601)		The HTTP q parameter was either missing or had no value. For geocoding requests, this means that an empty address was specified as input. For directions requests, this means that no query was specified in the input. (Since 2.81)
G_GEO_MISSING_ADDRESS (601)		Synonym for G_GEO_MISSING_QUERY. (Since 2.55)
G_GEO_UNKNOWN_ADDRESS (602)		No corresponding geographic location could be found for the specified address. This may be due to the fact that the address is relatively new, or it may be incorrect. (Since 2.55)
G_GEO_UNAVAILABLE_ADDRESS (603)	The geocode for the given address or the route for the given directions query cannot be returned due to legal or contractual reasons. (Since 2.55)
G_GEO_UNKNOWN_DIRECTIONS (604)	The GDirections object could not compute directions between the points mentioned in the query. 
								This is usually because there is no route available between the two points, or because we do not have data for routing in that region. (Since 2.81)
G_GEO_BAD_KEY (610)				The given key is either invalid or does not match the domain for which it was given. (Since 2.55)
G_GEO_TOO_MANY_QUERIES (620)	The given key has gone over the requests limit in the 24 hour period. (Since 2.55)	

	bounds
	Polyline - 
	NumRoutes - routes between waypoints so waypoints-1
	NumGeocodes - number of waypoints with coords
	CopyrightsHtml - 
	SummaryHtml - 
	Distance -
	Duration -
	Markers - array of GMarkers LatLng + icon
	Routes - 
		NumSteps
		StartGeocode
		EndGeocode
		EndLatLng
		SummaryHtml - 
		Distance -
		Duration -
		Steps - 
			LatLng
			PolylineIndex
			DescriptionHtml
			Distance -
			Duration -
*/

function pfind_routes($waypoints, $links, $prop, $type_names)
{
	global $wpdb;
	//convert lat/lng to nearest station(s)
	$metric = (clb_val('', $prop,'units') == 'km');
	$get_steps = clb_val(TRUE, $prop,'getSteps');
	$get_plyline = clb_val(TRUE, $prop, 'getPolyline');
	$dist_only = clb_val(TRUE, $prop, 'dist_only');
	
	$trip_meters = 0;
	
	clb_timing(__LINE__);
	
	$result = array();
	$result['error'] = 200;	//start optimistically
	$result['error_str'] = 'no errors';
	
	$html = '';
	$bounds = array();
	$polyline = '';
	$aoe_data = array();
	$markers = array_fill(0, count($waypoints), FALSE);	//initialise the markers array with a false for each waypoint

	$marker_path = 'http://chart.apis.google.com/chart?chst=d_map_xpin_letter&chld=pin|%|65BA4A|000000|000000';
	
	$tab_style = 'margin-top: 10px; margin-right: 0px; margin-bottom: 10px; margin-left: 0px; border-top-width: 1px; border-right-width: 1px; border-bottom-width: 1px; border-left-width: 1px; border-top-style: solid; border-right-style: solid; border-bottom-style: solid; border-left-style: solid; border-top-color: silver; border-right-color: silver; border-bottom-color: silver; border-left-color: silver; background-color: rgb(238, 238, 238); border-collapse: collapse; color: rgb(0, 0, 0); width: 100%;';
	
	$has_route = FALSE;	//gets set when we have some sort of route between waypoints
	
	/*
		****
		currently the route between waypoints is found, processed and then the next waypoint is handled
		if there are multiple nodes in an intermediate waypoint then the chosen route to and route from the way point may not pick the same nodes
		this case could be hanlded by adding a walk step at the beginning of the following step to go between the two chosen nodes
		or all intermediate routes could be processed and then ones with matching intermedite nodes chosen which would probably be harder to code.
	*/
	
	$orig = FALSE;
	$way_no = 0;
	foreach($waypoints AS $route_no=>$sel)	//each waypoint can be a selection of possible nodes in an area, nodes with similar names
	{
		$dest = array();
		foreach($sel AS $key=>$val)
		{
			//waypoints can be array(pnum => array(place record)) or array(pnum, pnum), hence the choice below
			$pnum = (is_array($val) ? $key : $val);

			//check each pnum and see if it needs to be split into platforms, but not if dist only
			if (!$dist_only && isset($links['stat2plat'][$pnum]))
			{
				$dest = array_merge($dest, $links['stat2plat'][$pnum]);
			}
			else
			{
				$dest[] = $pnum;
			}
		}
		
		if (clb_count($orig))	//do generate a route unless we are on second+ loop and have two end points
		{	
			$candidates = pfind_steps($orig, $dest, $links, $type_names, $dist_only);				//<= ****** call to shortest path
						
		//qlog(__LINE__, $waypoints, count($candidates), $candidates);
		
			//sift though candidates and choose best
			switch(clb_val('best', $prop,'opto')) {
				case('time'): $pick_fld = 'duration'; break;
				case('changes'): $pick_fld = 'minchanges'; break;
				case('dist'): $pick_fld = 'meters'; break;
				case('best'):
				default:
					$pick_fld = 'best'; break;
			}
			
			$pick_rec = FALSE;
			$pick_val = FALSE;
			foreach($candidates AS $no => $option)
			{
				if ($pick_fld == 'best')	//best is basically distance but with a penalty for changes
				{
					$test = $option['scores']['meters'] + (1000 * $option['scores']['minchanges']);
				}
				else
				{
					$test = $option['scores'][$pick_fld];
				}
				if (($pick_val === FALSE) || ($test < $pick_val))
				{
					$pick_rec = $no;
					$pick_val = $test;
				}
			}
					
			if ($pick_rec === FALSE)
			{
				//no routes found
				$result['error'] = 604;	
				$result['error_str'] = 'no connecting routes found';
				$result['Routes'][] = FALSE;	//maintain place for numbering
			} 
			else
			{
				$pnum1 = FALSE;
				if (!clb_val(FALSE, $markers, $way_no-1))	//add first marker on first route
				{
					$place = clb_val(FALSE, $candidates, $pick_rec, 'orig');
					$pnum1 = clb_val('', $place, 'pnum');
					
					$icon = str_replace('%', substr('ABCDEFGHIJKLMNOPQRSTUVWXYZ',$way_no-1,1), $marker_path);
					$markers[$way_no-1] = array(
						'url'=>$icon,
						'lat'=>clb_val(0, $place,'lat'),
						'lng'=>clb_val(0, $place,'lng'),
						'name'=>clb_val('', $place, RF_NODES_NAME),
						'desc'=>clb_val('', $place, RF_NODES_DESC),
					);
					
					$ptype = clb_val('', $place, 'ptype');
					$orig_detials = clb_val('', $type_names, $ptype, 'label').': '.clb_val('', $place, RF_NODES_NAME).' / '.clb_val('', $place, RF_NODES_DESC);
					
					if ($get_steps)	//two column table for waypoint at beginning of route
					{
						$row = '';
						$row .= clb_tag('td','', clb_tag('img/','','', array('src'=>$icon)), array('style'=>'cursor:pointer'));
						$row .= clb_tag('td', $orig_detials, '', array('style'=>'vertical-align: middle;width: 100%'));
						$html .= clb_tag('table','', clb_tag('tbody','', clb_tag('tr','', $row, array('style'=>'cursor:pointer'))), array('style'=>$tab_style));
					}
				}
				$has_route = TRUE;	//signals we have a route to return

				//add the points for this route into the array for the polyline
				if ($get_plyline)
				{
					$temp = FALSE;
					if (function_exists('pline_splice')) $temp = pline_splice($wpdb, $candidates[$pick_rec]['links'], $pnum1);	//pnum1 added 2/8/2009
					if (clb_count($temp)) $aoe_data = $temp;	//array_merge($aoe_data, $temp);
				}
				
				$steps = $candidates[$pick_rec]['steps'];
				
				$meters = $candidates[$pick_rec]['scores']['meters'];
				
				$trip_meters += $meters;
				
				$summary = ($metric ? pfind_kilo($meters) : pfind_miles($meters));	//string with units
				
				$place = clb_val(FALSE, $candidates, $pick_rec, 'dest');
				$ptype = clb_val('', $place, 'ptype');
				$dest_detials = clb_val('', $type_names, $ptype, 'label').': '.clb_val('', $place, RF_NODES_NAME).' / '.clb_val('', $place, RF_NODES_DESC);
		
				$icon = str_replace('%', substr('ABCDEFGHIJKLMNOPQRSTUVWXYZ',$way_no,1), $marker_path);
				$markers[$way_no] = array(
					'url'=>$icon,
					'lat'=>clb_val(0, $place,'lat'),
					'lng'=>clb_val(0, $place,'lng'),
					'name'=>clb_val('', $place, RF_NODES_NAME),
					'desc'=>clb_val('', $place, RF_NODES_DESC),
				);
		
				$step_list = array();
				
				if ($get_steps)	//build table of steps in this route
				{
					$table = '';					
					foreach($steps AS $s=>$pt)
					{
						$row = '';
						$row .= clb_tag('td', ($s+1),'', array('style'=>'vertical-align:top;border-top: 1px solid #cdcdcd;padding:0.3em 3px 0.3em 3px;margin: 0px;text-align:right;'));
						$row .= clb_tag('td', '', clb_val('', $pt,'desc'), array('style'=>'vertical-align:top;border-top: 1px solid #cdcdcd;padding:0.3em 3px 0.3em 3px;margin: 0px;width:100%;'));
						
						$dist = clb_val(0, $pt,'dist');
						
						
						$dist = ($metric ? pfind_kilo($dist) : pfind_miles($dist));
						$row .= clb_tag('td', '', $dist, array('style'=>'vertical-align:top;border-top: 1px solid #cdcdcd;padding-top:0.3em;padding-right:3px;padding-bottom:0.3em;padding-left:0.5em;margin: 0px;text-align:right;'));
						
						$table .= clb_tag('tr','',$row);
						
						$step_meters = clb_val(0, $pt,'dist');
						
						$step_list[] = array(
							'lat'=>clb_val(0, $pt,'lat'),
							'lng'=>clb_val(0, $pt,'lng'),
							'PolylineIndex'=>0,
							'DescriptionHtml'=>clb_val('', $pt,'desc'),
							'Distance'=>array('meters'=>$step_meters, 'miles'=>pfind_miles($step_meters, FALSE), 'html'=>($metric ? pfind_kilo($meters) : pfind_miles($meters))),
							'Duration'=>array(),
						);
					}
					$html .= clb_tag('table', '', clb_tag('tbody', '', $table));
					
					//add end marker on each route
					$row = '';
					$row .= clb_tag('td','', clb_tag('img/','','', array('src'=>$icon)), array('style'=>'cursor:pointer'));
					$row .= clb_tag('td', $dest_detials, '', array('style'=>'vertical-align: middle;width: 100%'));
					$html .= clb_tag('table','', clb_tag('tbody','', clb_tag('tr','', $row, array('style'=>'cursor:pointer'))), array('style'=>$tab_style));
				}
				
				
				$route = array();
				$route['NumSteps'] = count($step_list);
				
				
				/*
					geocodes are Placemark type values with the top level fields: address, AddressDetails, Point
					we dont have the address details breakdown so just providing the address if we have it and the Point data
				*/
				$m = $markers[$route_no-1];
				$route['StartGeocode'] = array('address'=>clb_val('',$m,'addr'), 'Point'=>array('coordinates'=>array(clb_val('',$m,'lat'),clb_val('',$m,'lng'),0)));
				$m = $markers[$route_no];
				$route['EndGeocode'] =  array('address'=>clb_val('',$m,'addr'), 'Point'=>array('coordinates'=>array(clb_val('',$m,'lat'),clb_val('',$m,'lng'),0)));
				
				if (isset($candidates[$no]['EndLatLng']))
				{
					$pt = preg_split('/\s*,\s*/',$candidates[$no]['EndLatLng']);
					$route['EndLatLng'] = array('lat'=>clb_val(0, $pt,0), 'lng'=>clb_val(0, $pt,1));
				}
				else
				{
					$route['EndLatLng'] = $route['EndGeocode']['Point'];
				}
				
				$route['SummaryHtml'] = $summary;
				$route['Distance'] = array('meters'=>$meters, 'miles'=>pfind_miles($meters, FALSE), 'html'=>$summary);
				$route['Duration'] = array();
				$route['Steps'] = $step_list;
		
				
				$result['Routes'][] = $route;
				
			}
		}
		$orig = $dest;
		$way_no++;
	}
	
	if ($has_route)
	{
		if (clb_count($aoe_data) && $get_plyline) //make the polyline
		{
			$attr = array(
				'color'=>clb_val('#0000FF', $prop,'color'), 
				'weight'=>clb_val(5, $prop, 'stroke'), 
				'opacity'=>clb_val(0.5, $prop,'opacity'),
				'pregran'=>TRUE,
				'do_bounds'=>TRUE,
				'max_pts'=>1000,
				'calc_len'=>FALSE,
				'split'=>TRUE	//true but not an int gets it done in batches but does not actually split it
			);
			
			$batch = FALSE;
			if (function_exists('pline_make')) $batch = pline_make($aoe_data, $attr);

			if (is_array($batch))
			{
				$bounds = array_shift($batch);	//first element of a batch is the bounds
			
				$polyline = array();
				foreach($batch AS $no=>$line)
				{
					//dont use the variable $bounds here as we want to keep the value from above
					$seg_bounds = clb_val(FALSE, $line, 'bounds');
					if ($seg_bounds) unset($line['bounds']);								
					$polyline[] = array('ident'=>'seg'.$no, 'pline'=>$line);
				
				}
// $txt = clb_json($polyline);
// qlog(__LINE__, $txt);

			}
//qlog(__LINE__, count($batch), $polyline);	
		}

		$summary = ($metric ? pfind_kilo($trip_meters) : pfind_miles($trip_meters));	//string with units
		$html = clb_tag('div', $summary, '', array('style'=>'text-align:right;padding-bottom:0.3em;')) . $html;
		
		$result['Bounds'] = $bounds;
		$result['Polylines'] = $polyline;
		$result['html'] = $html;
		$result['NumRoutes'] = 1;		//routes between waypoints so waypoints-1
		$result['NumGeocodes'] = 2;		//number of waypoints with coords
		$result['CopyrightsHtml'] = '&copy;'.htmlentities('2008'.' Logoriph Ltd');
		$result['SummaryHtml'] = $summary;
		$result['Distance'] = array('meters'=>$meters, 'miles'=>pfind_miles($meters, FALSE), 'html'=>$summary);
		$result['Duration'] = array();
		$result['Markers'] = $markers;
		$result['querytime'] = clb_timing('querytime');
		
		
		qlog(__LINE__, 'package result', $result['querytime']);
	}
	return $result;
}



/*
	this method takes a list of way points and converts them into real coordinates
	$entries - array of incoming waypoints (which are text and may be coordinates or names)
	$mode - travel mode: rail, tram, rtm, ...
	$waypoints - array by reference to receive the results, each element is an array of fields from the node
	$path - path to the data directory where the metaphones.txt file will be found.
	
	the result is an array with an error number and an error message.
*/
function pfind_interpret($entries, $mode, & $waypoints, $path, $node_types)
{
	global $wpdb;
	
	$result = array();
	$result['error'] = 200;	//start optimistically
	$result['error_str'] = 'no errors';
	
	$waypoints = array();
	
	if (clb_count($entries) <= 0)
	{
		$result['error'] = 400;
		$result['error_str'] = 'no locations received to parse';
		return $result;
	}
	
	//if only one entry we expect a a "to" separating origin and dest
	if (count($entries) == 1) $entries = preg_split('/\s+to\s+/i', trim(reset($entries)), -1, PREG_SPLIT_NO_EMPTY);
	
	if (clb_count($entries) == 1)
	{
		$result['error'] = 400;
		$result['error_str'] = 'only one location';
		return $result;
	}
	
	if (is_file($path)) $path = dirname($path);
	$path = clb_dir($path).'metaphones.txt';	//like spelling city
	$index = FALSE;
	if (is_file($path))
	{
		$data = file_get_contents($path);	//, FILE_BINARY);
		$index = clb_blob_dec($data);	
	}
			
	$pickone = array();
	foreach($entries AS $loc)
	{
		$loc = strtolower($loc);

		$sel = FALSE;
		$select = 'SELECT '.RF_NODES_SELECT.' FROM '.RF_NODES_FROM.' ';	//'SELECT pnum, ptype, title, more, lat, lng FROM places ';
		$where = ' WHERE ';	
		
		//the list of ptypes that will be used to limit the names search
		$ptypes = $node_types;
		
		//if more than one mode check if the user has added clarifying name to the name eg "oxford rail"
		if (count($ptypes) > 1) foreach($ptypes AS $type) if (clb_contains($loc, $type))
		{
			//allow the user to force a mode, by adding the code to the name
			$ptypes = array($type);	//replace all other modes
			$loc = trim(preg_replace('/\s*\b'.preg_quote($type).'\b\s*/i',' ',$loc));	//remove the signal from the name
		}
		
		//get rid of quotes and escapes
		$loc = trim(str_replace(array('\\','"'),' ', $loc));
		
		
		if (preg_match('/^\s*(-?[\.\d]+)\s*,\s*(-?[\.\d]+)\s*$/', $loc, $m))
		{
			//waypoint given as lat/lng pair
			
			//limit search by node types
			if (clb_count($ptypes)) $where .= ' '.RF_NODES_TYPE.' IN '.clb_join($ptypes, TRUE).' AND ';
			
			list($junk, $lat, $lng) = $m;
			
			for($dist=200; $dist<=500; $dist+=100)
			{
				$sel = $wpdb->get_results($select.$where.within_rect($lat, $lng, $dist), ARRAY_A);
				$sel = clb_rekey($sel, RF_NODES_KEY);
				if (clb_count($sel)>1) break;	
			}
			
			if (clb_count($sel) <= 0)
			{
				$result = array('error'=>602, 'error_str'=>'no tranpost node could be found near coordinates '.$lat.', '.$lng);
				return $result;
			}
		}
		else if (preg_match('/^node:\s*(\w+)\s*$/', $loc, $m))	//this is a literal code for the waypoint
		{
			//waypoint specified by pnum
			$sel = $wpdb->get_results($select.' WHERE '.RF_NODES_KEY.'='.clb_escape($m[1]), ARRAY_A);
			$sel = clb_rekey($sel, RF_NODES_KEY);
		}
		else if (in_array('rail', $ptypes) && preg_match('/^\s*(\w{3})\s*$/', $loc, $m))
		{
			//rail station three letter code
			$sel = $wpdb->get_results($select.' WHERE '.RF_NODES_TYPE.'="rail" AND (extref='.clb_escape($m[1]).' OR '.RF_NODES_NAME.'='.clb_escape($m[1]).')', ARRAY_A);
			$sel = clb_rekey($sel, RF_NODES_KEY);
		}
		else	//waypoint is a name
		{
			/*
				the primary key of the sound index structrue is the metaphone.  Inside that are ptypes using that saound.
				within each ptype there is a list of specific pnums using that sound in the name.
				on each first word, get all pnums for that sound and type, on subsequent words intesect with pnums
				so that we end up with pnums which have all sounds.
				$index[$sound][ptype][] => pnum
			*/
			$sel = FALSE;
			$name = pfind_unify_names($loc);
			if (is_array($index))
			{	
				$intersection = FALSE;
				$words = preg_split('/\W+/',$name, -1, PREG_SPLIT_NO_EMPTY);
				foreach($words AS $w)
				{
					if ($w == '&') $w = 'and';
					$sound = metaphone($w);
					if ($sound && isset($index[$sound]))
					{
						$set = array();
						foreach($index[$sound] AS $ptype=>$nodes) if (in_array($ptype, $ptypes)) $set = array_merge($set, $nodes);
						$intersection = (!is_array($intersection) ? $set : array_intersect($set, $intersection));
					}
				}
				if (clb_count($intersection))
				{
					$query = $select.$where.' '.RF_NODES_KEY.' IN '.clb_join($intersection, TRUE);
					$sel = $wpdb->get_results($query, ARRAY_A);
					$sel = clb_rekey($sel, RF_NODES_KEY);
				}
			}
			else	//if no sounds like structure, do by direct names starts with
			{
				$query = $select.$where.' '.RF_NODES_NAME.' LIKE '.clb_escape($name.'%');
				if (clb_count($ptypes)) $query .= ' AND '.RF_NODES_TYPE.' IN '.clb_join($ptypes, TRUE);
				$sel = $wpdb->get_results($query, ARRAY_A);
				$sel = clb_rekey($sel, RF_NODES_KEY);
			}
			
			//if more than one match scan for common words and remove the matches if they did not contain them
			if(clb_count($sel) > 1) foreach($sel AS $i=>$row)
			{
				//exact match go for it alone
				if (strtolower($row[RF_NODES_NAME]) == strtolower($loc)) { $sel = array($i=>$row); break; }
				
				//if the full name contains "road" but the requested name does not 
				//omit this choice to avoid stations like "london road" when looking for london
				if (preg_match('/\b(road)\b/i', $row[RF_NODES_NAME], $m)) if (!clb_contains($loc, $m[1], FALSE)) unset($sel[$i]);
			}
						
		}
		if (clb_count($sel) <= 0)
		{
			$result = array('error'=>602, 'error_str'=>'no stations with the name '.$loc);
			return $result;
		}
		$waypoints[]  = $sel;
		
	}
	
	return $result;
}

function pfind_def_tables($pfind_defs=FALSE)
{	
if (!defined('RF_NODES_FROM')) {
	DEFINE('RF_NODES_FROM', clb_val('places', $pfind_defs, 'NODES', 'FROM'));
	DEFINE('RF_NODES_SELECT', clb_val('pnum, ptype, title, more, lat, lng', $pfind_defs, 'NODES', 'SELECT'));
	DEFINE('RF_NODES_KEY', clb_val('pnum', $pfind_defs, 'NODES', 'KEY'));
	DEFINE('RF_NODES_TYPE', clb_val('ptype', $pfind_defs, 'NODES', 'TYPE'));
	DEFINE('RF_NODES_NAME', clb_val('title', $pfind_defs, 'NODES', 'NAME'));
	DEFINE('RF_NODES_DESC', clb_val('more', $pfind_defs, 'NODES', 'DESC'));
	
	DEFINE('RF_ROUTES_FROM', clb_val('routes', $pfind_defs, 'ROUTES', 'FROM'));
	DEFINE('RF_ROUTES_SELECT', clb_val('rnum, title, origin, destination, ptype', $pfind_defs, 'ROUTES', 'SELECT'));
	DEFINE('RF_ROUTES_KEY', clb_val('rnum', $pfind_defs, 'ROUTES', 'KEY'));
	DEFINE('RF_ROUTES_TYPE', clb_val('ptype', $pfind_defs, 'ROUTES', 'TYPE'));
	DEFINE('RF_ROUTES_NAME', clb_val('title', $pfind_defs, 'ROUTES', 'NAME'));
	DEFINE('RF_ROUTES_ORIG', clb_val('origin', $pfind_defs, 'ROUTES', 'ORIG'));
	DEFINE('RF_ROUTES_DEST', clb_val('destination', $pfind_defs, 'ROUTES', 'DEST'));
	DEFINE('RF_ROUTES_STOPS', clb_val('stops_list', $pfind_defs, 'ROUTES', 'STOPS_LIST'));
	DEFINE('RF_ROUTES_SBACK', clb_val('stops_back', $pfind_defs, 'ROUTES', 'STOPS_BACK'));
	
	DEFINE('RF_LINKS_FROM', clb_val('wp_mfw_links', $pfind_defs, 'LINKS', 'FROM'));
	DEFINE('RF_LINKS_SELECT', clb_val('pnum, ptype, title, end1, end2, reverse, dist, weight, time', $pfind_defs, 'LINKS', 'SELECT'));
	DEFINE('RF_LINKS_KEY', clb_val('pnum', $pfind_defs, 'LINKS', 'KEY'));
	DEFINE('RF_LINKS_TYPE', clb_val('ptype', $pfind_defs, 'LINKS', 'TYPE'));
	DEFINE('RF_LINKS_NAME', clb_val('title', $pfind_defs, 'LINKS', 'NAME'));
	DEFINE('RF_LINKS_END1', clb_val('end1', $pfind_defs, 'LINKS', 'END1'));
	DEFINE('RF_LINKS_END2', clb_val('end2', $pfind_defs, 'LINKS', 'END2'));
	DEFINE('RF_LINKS_REVERSE', clb_val('reverse', $pfind_defs, 'LINKS', 'REVERSE'));
	DEFINE('RF_LINKS_DIST', clb_val('dist', $pfind_defs, 'LINKS', 'DIST'));
	DEFINE('RF_LINKS_WEIGHT', clb_val('weight', $pfind_defs, 'LINKS', 'WEIGHT'));
	DEFINE('RF_LINKS_TIME', clb_val('time', $pfind_defs, 'LINKS', 'TIME'));
	DEFINE('RF_LINKS_MODIFIED', clb_val('modified', $pfind_defs, 'LINKS', 'MODIFIED'));
	DEFINE('RF_LINKS_CREATED', clb_val('created', $pfind_defs, 'LINKS', 'CREATED'));
	DEFINE('RF_LINKS_LINE', clb_val('line', $pfind_defs, 'LINKS', 'LINE'));
	DEFINE('RF_LINKS_POINTS', clb_val('points', $pfind_defs, 'LINKS', 'POINTS'));
	
	DEFINE('RF_DISTS_FROM', clb_val('wp_mfw_dists', $pfind_defs, 'DISTS', 'FROM'));
}
}


/*
	written to make station names consistent, this changes common abbreviations into the full words
*/
function pfind_unify_names($name)
{
	$name = strtolower(trim($name));
	$name = str_replace(array("'",' and ',' rd','pkwy',' pk'),array('',' & ',' road',' parkway',' park'),$name);
	$name = preg_replace('/^(st\. |saint )/i','st ', $name);
	$name = preg_replace('/\b( st\.?)$/i',' street', $name);
	$name = preg_replace('/^(london)\b/i','x$1', $name);	//this gets names beginning with london treated differently to ones containing london
	return $name;	
}



/*
	This gets parameters from the request and gets the right lookup tables and calls the route finder
	
	$prms - pass in $_REQUEST if acting as a web service or symulate options in your own array
	$pfind_defs - array of field names of the place/link/route tables.  see pfind_def_tables() above
	$type_names - array with ptype index and array values with 'label'=>'name of type'
	$p2p_paths - one or more full paths to point to point files
		- simply a path string
		- an array of path strings, indexes assumed to be node types
		- an array of arrays with 'path=>'', 'types'=>string|array
	$node_types - ptypes of places that can be seardched for as end points
*/
function pfind_service($prms, $pfind_defs, $type_names, $p2p_paths, $node_types=FALSE)
{
	clb_timing(__LINE__);
	
	pfind_def_tables($pfind_defs);
	
	$result = array('error'=>200, 'error_str'=>'no errors');
	
	//get the query or way points from the request
	$entries = array();
	if (isset($prms['q']) && $prms['q'])
	{
		$entries[] = $prms['q'];
	}
	else
	{
		$w = 0;
		while(isset($prms['w'.$w]))
		{
			$entries[] = $prms['w'.$w];
			$w++;
		}
	}

	$prop = array();
	$prop['service']  = strtolower(clb_val('json', $prms, 's'));	//json, xml
	$prop['mode'] = strtolower(clb_val('', $prms, 'm'));
	$prop['units']  = strtolower(clb_val('mi', $prms, 'u'));	//km, mi
	$prop['opto']  = strtolower(clb_val('best', $prms, 'o'));	//dist, change, best
	$prop['opacity']  = strtolower(clb_val(0.5, $prms, 't'));
	$prop['color']  = strtolower(clb_val('#0000FF', $prms, 'c'));
	$prop['stroke']  = strtolower(clb_val(5, $prms, 'k'));
	
	$dist_only  = (FALSE != clb_val(0, $prms, 'gd'));	//get result from precalculated distances
	$prop['dist_only']  = $dist_only;
	$prop['getSteps']  = (!$dist_only && (FALSE != clb_val(0, $prms, 'gs')));
	$prop['getPolyline']  = (!$dist_only && (FALSE != clb_val(0, $prms, 'gp')));

	$path = FALSE;
	$mode = $prop['mode'];
	if (is_string($p2p_paths))	//if we only have one p2p file then we have no mode choice
	{
		$path = $p2p_paths;
	}
	else if (!is_array($p2p_paths))
	{
		$result = array('error'=>500, 'error_str'=>'server configuration error: route tables not specified');
	}
	else if ($mode && isset($p2p_paths[$mode]))	//we have a p2p file to match requested mode
	{
		$path = $p2p_paths[$mode];
	}
	else if (isset($p2p_paths['rtm']))	//since we could not satisfy requested mode, try and use rail/tube/metro = rtm
	{
		$node_types = array('rail','tube','tram');
		$path = $p2p_paths['rtm'];
	}
	else	//if there is an array of paths try the first one
	{
		$path = reset($p2p_paths);
	}
	
	if (is_array($path))	//paths are structured not just strings
	{
		if (!$node_types) $node_types = clb_val(FALSE, $path, 'types');		//get the types
		$path = clb_val(FALSE, $path, 'path');
	}
	
	//if types not given as param or with paths, but the mode name is a node type use it as default.
	if (!$node_types && clb_val(FALSE, $type_names, $prop['mode'])) $node_types = $prop['mode'];
	
	if ($node_types && !is_array($node_types)) $node_types = array($node_types);	//make types an array if just a single
		
	if (!$path || !is_string($path) || !is_file($path))
	{
		$result = array('error'=>500, 'error_str'=>'server configuration error: route tables could not be loaded');	
	}
	else
	{
		$waypoints = array();
		$result = pfind_interpret($entries, $mode, $waypoints, $path, $node_types);
		
		qlog(__LINE__, $mode, $entries, $result);
		if (IS_LOCAL) foreach($waypoints AS $i=>$stage) foreach($stage AS $see) qlog(__LINE__, $i, join(', ',$see));
	
		clb_timing('interpret');
	}
	
	if ($result['error'] == 200)
	{
		$links = FALSE;
		if (!$prop['dist_only'] && is_file($path))
		{
			$data = file_get_contents($path);	//, FILE_BINARY);	//need different p2p files for different combinations of modes
			$links = clb_blob_dec($data);	
		}
		
		clb_timing('load array');
		
		if (!$links && !$prop['dist_only'])
		{
			$result = array('error'=>500, 'error_str'=>'point to point data file could not be found/loaded '.$mode);	
			qlog(__LINE__, $result, $mode, $path, $p2p_paths);
		}
		else
		{
			$result = pfind_routes($waypoints, $links, $prop, $type_names);
		}
		qlog(__LINE__, clb_timing('find path'));
	}
//qlog(__LINE__,clb_xml($result, 'RouteFinder'));
// qlog(__LINE__,clb_json($result, "'"));

	switch($prop['service']) {
	case('json'):
		$return_data = json_encode($result);
		rs_response('application/json', $return_data, 'UTF-8');
		break;
		
	case('javascript'):
		$sid = clb_val('', $prms, 'sid');
		$func = clb_val('mfw_dir_result', $prms, 'callback');
		$return_data = clb_json($result, "'");
		$callback = $func.'(\''.$sid.'\','.$return_data.');';
		clb_response('application/javascript', $callback, 'UTF-8');
		break;
		
	case('xml'):
		$return_data = clb_xml($result, 'RouteFinder');
		clb_response('xml', $return_data, 'UTF-8');		
		break;
		
	case('php'):
		return $result;
		break;
	}
}



?>
