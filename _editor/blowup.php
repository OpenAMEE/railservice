<?php


function blow_load_nodes($node1, & $links)	//$node2, $links, $allowance = 4, $margin=1.1)
{

	clb_timing(__LINE__);
	
	
	//check origin(s)
	if (!is_array($node1)) $node1 = array($node1);
	foreach($node1 AS $i=>$pnum) if (!isset($links['nodes'][$pnum]) || empty($links['nodes'][$pnum]))
	{
		unset($node1[$i]);
		qlog(__FUNCTION__, __LINE__, 'unknown origin in shortpath', $pnum);
	}

	
	//if no origin or destination then return without searching for a route
	if (clb_count($node1) == 0) return array();

	$network_d = array();
	$queue = array();
	
	foreach($node1 AS $c=>$pnum)
	{				
		$network_d[$pnum]['node'] = $pnum;
		$network_d[$pnum]['dist'] = 0;
		$network_d[$pnum]['depth'] = chr(48+($c%207));
		$network_d[$pnum]['prev'] = array();	//this is where we start so no previous links
		$network_d[$pnum]['ptype'] = '';	//ptype of link not node
		
		$queue[$pnum] = 0;	//the initial distance only really important when more than one origin.
	}
		
	$complete = array();	//collects one or more paths
	$best = FALSE;	//will hold the lowest score of an actual match so we can stop looking after other options get to large to be contenders

	$max_depth = 0;		//just for interest
	while (count($queue))
	{
		asort($queue);	//put lowest score first
// qlog(__LINE__, $queue);
		$max_depth = max($max_depth, count($queue));
		
		$noden = key($queue);	//get the key (node pnum)
		array_shift($queue);	//remove item from queue since it will not need processing after this
		
		//$noden is the pnum of the node and is our index into the $network_d
		
		if (!isset($network_d[$noden])) continue;	//the parent should always exist, just being safe.
	
		$last_dist = $network_d[$noden]['dist'];	//distance up to the node on this leaf
		$last_depth = $network_d[$noden]['depth'];	//number of links up to the node on this leaf
		
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
				$real_direction = $reverse;	//($backwards ? !$reverse : $reverse);
				
				//non reversable link and an attempt to use it in the wrong direction, skip it
				//if ($real_direction && (clb_val(0, $linkrec, 'reverse') & 1)) continue;	//reverse value of 1 means not in reverse direction
				//if (!$real_direction && (clb_val(0, $linkrec, 'reverse') & 2)) continue;	//reverse value with 2 means not in forward direction
				if ($real_direction && ((isset($linkrec['reverse']) ? $linkrec['reverse'] : 0) & 1)) continue;	//reverse value of 1 means not in reverse direction
				if (!$real_direction && ((isset($linkrec['reverse']) ? $linkrec['reverse'] : 0) & 2)) continue;	//reverse value with 2 means not in forward direction
				
				//the $otherend will be different on each loop here because each link points to a different other node.
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
							if (strpos($last_depth, $prev['depth']) === 0)
							{
								/*
									'depth' is a map of branching and depth on current path and curren node 
									match then we have been here before on this thread and are looping back so stop
									and dont follow this link
								*/
								$linkpnum = FALSE;
								break;
							}
							else if (strlen($last_depth)+1 > strlen($prev['depth']))
							{							
								/*
									if the distance to the node within a small margin is the same, we will assume 
									these are the same path but perhaps with different numbers of stops
									this path has more stops so keep it rather than the other one
								*/
								unset($network_d[$otherend]['prev'][$link]); 
							}
							else
							{
								//existing route had more stops so will not add this link
								$linkpnum = FALSE;	
								break;
							}
						}
					}
					//do this after above as above may delete a prev
					//if MAX_PATH_OPTIONS=1 the shortpath process is very quick but ONLY gets the shortest path regardless of number of changes
					//MAX_PATH_OPTIONS = 2 is the default. 
					if (clb_count($network_d[$otherend]['prev']) >= MAX_PATH_OPTIONS) continue;	//to only the best N predecessors 
					
				}
				else
				{					
					//add this leaf to the processing queue
					$queue[$otherend] = round($dist);	//this will find shortest path first
					
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
	
	return $network_d;
}


function blow_backout($node1, $node2, $network_d, $links)
{
	//now run the shortest path on the new network of shortest paths but measure shortest by number of changes
	$seq=0;
	$network_c = array();
	$queue = array();
	$queue_sort = array();	//since change distances will be the same a lot of the time inclde a parallel distance array to pick the shortest within change groups
	
	$dist_limit = FALSE;
	foreach($node2 AS $pnum)	//since these have come from the results they must all be known in the links list
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
		
		if (isset($network_d[$pnum]) && (($dist_limit === FALSE) || ($dist_limit > $network_d[$pnum]['dist']))) $dist_limit = $network_d[$pnum]['dist'];
	}
	
	$dist_limit *= 1.1;	//don't allow paths to get more than 20% longer than the best.
	
	$complete = array();	//collects one or more paths
	$best = FALSE;	//will hold the lowest score of an actual match so we can stop looking after other options get to large to be contenders
	$range = 2;	//allow range of best+2 extra stops
	
	$used = array();
	$time = microtime(true);
	
	while (count($queue))
	{
		//asort($queue);	//put lowest score first
		array_multisort($queue, SORT_ASC, $queue_sort,  SORT_DESC); //$queue = changes, $queue_sort = dist
		
if ((microtime(true) - $time) * 1000 > 5000)
{
	$time = microtime(true);
	qlog(__LINE__, 'queue', count($queue));
}

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
//if (count($complete) == 0) {qpre($node1, $node2, $network_d, $network_c, $preds);	exit();}

	$result = array();
	if (is_array($complete)) foreach($complete AS $pos_c)
	{
		$run = array();
		while($link = $network_c[$pos_c]['link'])
		{
			$run[$link] = $network_c[$pos_c];	//['routes'];
			$pos_c = $network_c[$pos_c]['prev'];
		}
		$result[] = $run;
	}
	
//qlog(__LINE__,count($complete), count($used), count($network_c), count($network_d));

//qlog(__LINE__, clb_timing('shortest changes'));
//qlog(__LINE__, $node2, $complete);

	return $result;
}


			
//get list of all stops sorted by latitude
$flat = 'wp_mfw_dists';
$query = 'SELECT '.RF_NODES_KEY.' FROM '.RF_NODES_FROM.' WHERE '.RF_NODES_TYPE.' IN '.clb_join(array('rail'), TRUE).' ORDER BY '.RF_NODES_KEY;
$sel1 = $wpdb->get_results($query, ARRAY_A);
if (is_array($sel1))
{
	//load p2p data so we can run shortest path and do our own lookups on link data
	$data = file_get_contents($p2p_path);
	$p2p_links = clb_blob_dec($data);
	
	$sel1 = clb_column($sel1, RF_NODES_KEY);
	
	//remove stations that dont have links to them
	foreach($sel1 AS $i=>$pnum) if (!isset($p2p_links['stat2plat'][$pnum]) && !isset($p2p_links['nodes'][$pnum]))
	{
		qpre('station not on network', $pnum);
		unset($sel1[$i]);
	}
	
	$to_save = $sel1;
	
	sort($sel1);	//renumber consecutively and make it easy to tell if we should create the pair
	
	$time = 0;
	
	//main double loop to permute station combinations inner loop starts from last station
	$stats = $base_stats = array('added'=>0,'skipped'=>0,'shortpaths'=>0);
	
	
	$chunks = max(1, (int) clb_val(1, $argv, 2));
	$which = max(1,min($chunks,(int) clb_val(1, $argv, 3)));	//keep between 1 & chunks inclusive

	//passing 1 in this makes the shortpath process very quick, default 2 for better path values
	$max_opts = clb_val(2, $argv, 4);
	DEFINE('MAX_PATH_OPTIONS', $max_opts);
	
	$node_count = count($sel1);
	
	$chunk_start = 0;
	$chunk_end = $node_count;
	
	if ($chunks > 1)	//scans from 0 - node_count with no overlaps
	{
		$chunk_start = floor((($which - 1) * $node_count) / $chunks);
		$chunk_end = floor((($which - 0) * $node_count) / $chunks);
	}
	
	$chunk_nodes = ($chunk_end - $chunk_start);
	
	qpre('node count', count($sel1), 'chunks', $chunks, 'this chunk', $which, 'indicies (inclusive)', $chunk_start.'-'.($chunk_end-1));	//loop ends <$chunk_end so -1 to show last index hit
	

	$batch = 0;
	$start_time = time();
	$last_est = $estimate = 0;
	
	for($scan1=$chunk_start; $scan1<$chunk_end;  $scan1++)
	{
				
		//get station pnum and platform pnums
		$pnum1 = $sel1[$scan1];
		$pnum1s = (isset($p2p_links['stat2plat'][$pnum1]) ? $p2p_links['stat2plat'][$pnum1] : array($pnum1));
		
		//make a short path network from this node
		$network_d = blow_load_nodes($pnum1s, $p2p_links);
		
		
		if (clb_count($network_d))
		{
			//get a list of all the node keys in the list
			$targets = array_keys($network_d);
			while(count($targets))
			{
				if ((microtime(true) - $time) * 1000 > 60000)	//roughly every minute
				{
					$rate = round($batch / (microtime(true) - $time));	//per second rate
					$pos = round(100*(($scan1-$chunk_start) / $chunk_nodes),3);
					
					$elapsed = (time() - $start_time);
					if ($last_est != $pos)	//only calc time right after flipped to next node
					{
						$last_est = $pos;	
						$estimate = (100*$elapsed/$pos);	
					}
					
					$txt = clb_json($stats);
					qpre($rate, $txt, $pos, $scan1, gmdate('H:i', $elapsed), 'remaining: '.($estimate ? gmdate('H:i', max(0,$estimate-$elapsed)) : 'not ready'));
					//qlog($rate, $txt, $pos, $clock, $scan1);
					$stats = $base_stats;
					$batch = 0;
					$time = microtime(true);
				}
				
				while(count($targets))
				{
					//get most distant other end reached/left
					
					//ensures $targets always reduces on each loop
					$pnum2 = array_pop($targets);
					//convert platforms to the station
					if (isset($p2p_links['plat2stat'][$pnum])) $pnum2 = $p2p_links['plat2stat'][$pnum2];
					
					if (($pnum2 > $pnum1) && in_array($pnum2, $to_save)) break;	//we will use this pnum if greater than the originating one
				}
			
				if (count($targets))
				{
					//convert station to platforms, or station to array
					$pnum2s = (isset($p2p_links['stat2plat'][$pnum2]) ? $p2p_links['stat2plat'][$pnum2] : array($pnum2));
					
//qlog(__LINE__, $pnum1, $pnum2, count($targets));
					//get shortpaths for this node
					$short_paths = blow_backout($pnum1s, $pnum2s, $network_d, $p2p_links);
					
					$stats['shortpaths']++;
					
					if (clb_count($short_paths) == 0)
					{
						qpre('no shortpaths found', $pnum1, $pnum2);
						continue;
					}
					
					//find optimal route, using penalty for changes
					$best_pos = FALSE;
					$best_score = FALSE;
					foreach($short_paths AS $path_no => $details)
					{
						$first = reset($details);	//first node has total dist and changes count
						$changes = clb_val(FALSE, $first, 'changes');
						$dist = clb_val(FALSE, $first, 'dist');
						$score = ($changes*10000)+$dist;	//wieght changes as 10k distance additions
						if (($best_score === FALSE) || ($score < $best_score)) list($best_pos, $best_score) = array($path_no, $score);
					}
					
					
					$stoplist = array();
					$details = $short_paths[$best_pos];
					$first = reset($details);	//first node has total dist and changes count
					$node1 = clb_val(FALSE, $first, 'node');	//could be a platform but that is OK, lets us orient first segment
					
//qlog(__LINE__, $node1, $pnum2, count($short_paths), count($details));
					
					$stop_pnum = (isset($p2p_links['plat2stat'][$node1]) ? $p2p_links['plat2stat'][$node1] : $node1);
					
					//0=pnum, 1=dist from last station to this one
					$stoplist['pnum'][] = $stop_pnum;
					$stoplist['dist'][] = 0;
					
					//loop to build stop list with real stations not platforms and with distances
					foreach($details AS $linkpnum=>$info) if (!isset($p2p_links['links'][$linkpnum])) 
					{
						qpre('flatten - link record not found', $linkpnum, $pnum1, $pnum2); break;
					}
					else
					{
						parse_str($p2p_links['links'][$linkpnum], $linkrec);		//get link rec by linkpnum
						
						$ptype = clb_val(0, $linkrec, 'ptype');
						
						$dist = 0;
						if ($ptype != 'walk') $dist = clb_val(0, $linkrec, 'dist');
						
						$next = ((isset($linkrec['end1']) && ($node1 == $linkrec['end1'])) ? $linkrec['end2'] : $linkrec['end1']);
						
						$stop_pnum = (isset($p2p_links['plat2stat'][$next]) ? $p2p_links['plat2stat'][$next] : $next);
						
						$stoplist['pnum'][] = $stop_pnum;
						$stoplist['dist'][] = $dist;
						
						$node1 = $next;
					}

					// now scan all nodes along path adding AB pairs where B>A and B still in $targets
					$dist = 0;
					$query = '';
					for($combo1=1; $combo1<count($stoplist['pnum']); $combo1++)
					{						
						$B = $stoplist['pnum'][$combo1];	//these are stations not platforms
						$dist += $stoplist['dist'][$combo1];	//accumulate distance even if we dont save this stop
						
						//convert the station to platform list or station into array
						if (isset($p2p_links['stat2plat'][$B]))
						{
							$platforms = $p2p_links['stat2plat'][$B];
							$platforms[] = $B;
							$doit = count(array_intersect($targets, $platforms));
						}
						else
						{
							$platforms = array($B);
							$doit = in_array($B, $targets);
						}
						if ($doit) $targets = array_diff($targets, $platforms);
						
						//both $B and $pnum2 are always stations not platforms
						if ((($B == $pnum2) || $doit) && ($B > $pnum1))
						{
							$batch++;
							$stats['added']++;
							$query .= ' ("'.$pnum1.'", "'.$B.'",'.$dist.'),';
// 							$query = 'INSERT INTO '.$flat.' SET a="'.$pnum1.'", b="'.$B.'", dist='.$dist;
// 							$wpdb->query($query, ARRAY_A);
						}
						else
						{
							$stats['skipped']++;
						}
					}
					
					if ($query) $wpdb->query('INSERT INTO '.$flat.' VALUES '.trim($query,','), ARRAY_A);
					
				}
			}
		}
	}
}


?>