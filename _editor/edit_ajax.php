<?php

//scan downwards for _engine directory
$path = dirname(__FILE__);
while($path && !file_exists($path.'/_engine/')) $path = dirname($path);
DEFINE('CODE_DIR', $path.'/_engine/');

require_once(CODE_DIR.'core_lib.php');
require_once(CODE_DIR.'wp-db.php');

function new_pnum($db, $table, $fld = 'pnum')
{
	global $editor_tables;
	$code = clb_val(FALSE, $editor_tables, $table, 'prefix');
	
	if (!$code) return FALSE;
	$pnum = '';
	while (!$pnum || $db->query('SELECT `'.$fld.'` FROM `'.$table.'` WHERE '.$fld.'="'.$pnum.'"'))
	{
		$pnum = strtoupper($code.'_'.substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890'),0,7));
	}
	return $pnum;
}

/*
	pass in the points and the table spec
*/
function save_line_query($points, $spec)
{
	$query = '';
	
	//identify line and points fields based on the table spec
	list($line_fld, $points_fld) = explode('/', clb_val(FALSE, $spec, 'polyline').'/');

	if ($points && ($mid = pline_midpoint($points))) list($_REQUEST['lat'], $_REQUEST['lng']) = $mid;
	
	$pt = reset($points);
	$_REQUEST['end1'] =  clb_b64e($pt[0], 1E4).clb_b64e($pt[1], 1E4);
	
	$pt = end($points);
	$_REQUEST['end2'] =  clb_b64e($pt[0], 1E4).clb_b64e($pt[1], 1E4);
	
	if ($points_fld)
	{
		$val = '';
		foreach($points AS $pt) $val .= join(',',$pt)."\n";
		$query .= '`'.$points_fld.'`='.clb_escape($val).',';
	}
	if ($line_fld)
	{
		$line = ($points ? pline_make($points, array('color'=>'#0000FF')) : '');
		if ($line) $line = clb_join($line, '','&','=');
		$query .= '`'.$line_fld.'`='.clb_escape($line).',';
	}
	return $query;
}

function refesh_marker($db, $table, $pnum, $old_pnum=FALSE)
{
	$xml = '';
	//remove old marker, which closes info box
	if ($old_pnum) $xml .= clb_tag('response', '', '', array('type'=>'remove', 'pnum'=>$old_pnum))."\n";	
	
	//send new marker to reflect type change if any
	$query = 'SELECT lat, lng, pnum, ptype, title FROM '.$table.' WHERE pnum='.clb_escape($pnum);
	$sel = $db->get_results($query, ARRAY_A);
	if (clb_count($sel)) foreach($sel AS $rec) $xml .= clb_tag('response', '', '', array_merge($rec, array('type'=>'onemarker')))."\n";
	
	return $xml;
}

function marker_bubble($db, $spec, $table, $pnum)
{
	global $editor_types, $editor_tables;
	
	$xml = '';
	
	$types = array();
	foreach($editor_types AS $type=>$info) if (clb_val(FALSE, $info, 'table') == $table) $types[$type] = clb_val(FALSE, $info, 'label');
	
	//identify line and points fields based on the table spec
	list($line_fld, $points_fld) = explode('/', clb_val(FALSE, $spec, 'polyline').'/');
	
	$query = 'SELECT '.clb_join($spec['select'],'`').' FROM '.$table.' WHERE pnum='.clb_escape($pnum);
	$sel = $db->get_results($query, ARRAY_A);
	
	if (clb_count($sel)==0) foreach($spec['select'] AS $fld) $sel[0][$fld] = clb_val('', $_REQUEST, $fld);
	
	$rec = reset($sel);
	
	//if the bubble is outside the current viewport, cluster manager does not show marker, so move into range.
	$lat = clb_val(FALSE, $rec, 'lat');
	$lng = clb_val(FALSE, $rec, 'lng');
	if (($lat > clb_val(0, $_REQUEST, 'top')) || ($lat < clb_val(0, $_REQUEST, 'bot')) || ($lng > clb_val(0, $_REQUEST, 'rgt')) || ($lng < clb_val(0, $_REQUEST, 'lft')))
	{
		$xml .= clb_tag('response', '', '', array('type'=>'centre', 'lat'=>$lat, 'lng'=>$lng))."\n";
	}
	
	$ptype = clb_val('',$rec, 'ptype');
	$html = '';
	foreach($rec AS $fld=>$val)
	{
		switch($fld) {
		case('ptype'):
			$options = '';
			foreach($types AS $type=>$label)
			{
				$attr = array('value'=>$type);
				if ($val == $type) $attr['selected'] = 'selected';
				$options .= clb_tag('option',$label,'',$attr);
			}
			$html .= clb_tag('label', $fld, '', array('for'=>$fld))."\n";							
			$html .= clb_tag('select','',$options, array('id'=>$fld, 'name'=>$fld));
			break;
		case('lat'):
		case('lng'):
		case('pnum'):
		case('end1'):
		case('end2'):
			$html .= clb_tag('p', $fld.': "'.$val.'", ')."\n";							
			break;
		case('notes'):
		case('audit'):
			$html .= clb_tag('label', $fld, '', array('for'=>$fld))."\n";							
			$html .= clb_tag('textarea', $val, '', array('name'=>$fld, 'id'=>$fld))."\n";							
			break;
		default:
			if ($fld == $line_fld)
			{
				if (clb_val(FALSE, $editor_types, $ptype, 'table') != $table) $ptype = clb_val(FALSE, $editor_tables, $table, 'prefix').'_'.$ptype;
				$xml .= clb_tag('response', '', htmlspecialchars($val), array('type'=>'edit_pline', 'pnum'=>$pnum, 'ptype'=>$ptype))."\n";							
			}
			else
			{
				$html .= clb_tag('label', $fld, '', array('for'=>$fld))."\n";							
				$html .= clb_tag('input/', '', '', array('name'=>$fld, 'id'=>$fld, 'class'=>'type_text', 'type'=>'text', 'value'=>$val))."\n";							
			}
		}
	}
	$btns = '';
	$btns .= clb_tag('input/', '', '', array('name'=>'btn_save', 'type'=>'button', 'value'=>'save', 'onclick'=>'map_marker_info(the_map, this.name, \''.$pnum.'\', this.form);'))."\n";
	$btns .= clb_tag('input/', '', '', array('name'=>'btn_delete', 'type'=>'button', 'value'=>'delete', 'onclick'=>'map_marker_info(the_map, this.name, \''.$pnum.'\');'))."\n";
//	$btns .= clb_tag('input/', '', '', array('name'=>'btn_revert', 'type'=>'button', 'value'=>'revert', 'onclick'=>'map_marker_info(the_map, this.name, \''.$pnum.'\');'))."\n";
	if ($line_fld && !preg_match('/\w+_\w+/',$ptype)) $btns .= clb_tag('input/', '', '', array('name'=>'btn_editline', 'type'=>'button', 'value'=>'edit line', 'onclick'=>'map_marker_info(the_map, this.name, \''.$pnum.'\');'))."\n";
	if (!$line_fld) $btns .= clb_tag('input/', '', '', array('name'=>'btn_newline', 'type'=>'button', 'value'=>'start line', 'onclick'=>'map_marker_info(the_map, this.name, \''.$pnum.'\');'))."\n";
	if (!$line_fld) $btns .= clb_tag('input/', '', '', array('name'=>'btn_routes', 'type'=>'button', 'value'=>'routes', 'onclick'=>'map_ajax(the_map, \'route_list\', {\'pnum\':\''.$pnum.'\'});'))."\n";
	$btns .= clb_tag('input/', '', '', array('name'=>'btn_new_route', 'type'=>'button', 'value'=>'new route', 'onclick'=>'map_ajax(the_map, \'new_route\', {\'pnum\':\''.$pnum.'\', \'ptype\':\''.$ptype.'\'});'))."\n";
	
	$html .= clb_tag('p', '', $btns)."\n";							
	$form = clb_tag('form', '', $html, array('id'=>'info_win', 'onsubmit'=>'return false;'))."\n";							
	$xml .= clb_tag('response', '', htmlspecialchars($form), array('type'=>'bubble', 'pnum'=>$pnum))."\n";	
	
	return $xml;
}

function ajax_request()
{
	global $editor_types, $editor_tables;
	
	$xml = '';
	$msg = '';
	$rec_count = 0;
	$db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
	
	$pnum = clb_val(FALSE, $_REQUEST, 'pnum');
	if ($new_mark = preg_match('/^b(\w{7})(\w{7})/',$pnum, $m)) //temp marker
	{
		$_REQUEST['lat'] = ((hexdec($m[1])/100000)-180);
		$_REQUEST['lng'] = ((hexdec($m[2])/100000)-180);
		$_REQUEST['coords'] = clb_b64e($_REQUEST['lat']).clb_b64e($_REQUEST['lng']);
	}

	$ptype = clb_val(FALSE, $_REQUEST, 'ptype');
	
	//if the ptype is cust, default to the first table and type in the definitions that does or does not have a polyline
	if (($ptype == 'cust') && $new_mark) foreach($editor_types AS $ptype=>$spec)
	{
		if (clb_val(FALSE, $spec, 'polyline') == isset($_REQUEST['polyline'])) break;
	}
	
	$table = FALSE;
	$prefixes = clb_column($editor_tables, 'prefix');
	if ($pnum && preg_match('/^(\w+)_\w+$/', $pnum, $m))
	{
		$table = array_search($m[1], $prefixes);
	}
	else if ($ptype)
	{
		$table = clb_val(FALSE, $editor_types, $ptype, 'table');
	}
	
	$spec = clb_val(FALSE, $editor_tables, $table);
	
	
	
	$ajax = clb_val(FALSE, $_REQUEST, 'ajax');

qlog(__LINE__, '>>>>',  $ajax, $pnum, $ptype, $table, ($new_mark?'new':'old'));	//, $spec);
	
	switch($ajax) {
	case ('show'):
		$sel = FALSE;
		if ($spec)	//its a pnum
		{
			$select = clb_val(FALSE, $spec, 'select');
			$query = 'SELECT '.clb_join($select, '`').' FROM '.$table.' WHERE pnum='.clb_escape($pnum);
			$sel = $db->get_results($query, ARRAY_A);			
		}
		//if not found via pnum, try title in each table.
		if (!$sel) foreach($editor_tables AS $table=>$spec) if (($select = clb_val(FALSE, $spec, 'select')) && in_array('title', $select))
		{
			$query = 'SELECT '.clb_join($select, '`').' FROM '.$table.' WHERE title like '.clb_escape($pnum.'%');
			$sel = $db->get_results($query, ARRAY_A);
			if ($sel) break;
		}
		if (!$sel)
		{
			$msg = 'No matches found for: '.$pnum;
		}
		else
		{
			$titles = clb_column($sel, 'title');
			$msg = count($sel).' matches found: '.join(', ',$titles);
			$rec = reset($sel);
			$pnum = clb_val(FALSE, $rec, 'pnum');
			$xml .= clb_tag('response', '', '', array_merge(array('type'=>'centre'), array_intersect_key($rec, array('lat'=>0, 'lng'=>0))))."\n";	
			$xml .= refesh_marker($db, $table, $pnum);
			$xml .= marker_bubble($db, $spec, $table, $pnum);
		}
		break;
		
	case ('route_list'):	//shows bubble on station showing list of routes
		
		$html = '';
		
		//find the route table and which field(s) hold the stop list
		$route_flds = '';
		foreach($editor_tables AS $route_tab=>$info) if ($route_flds = clb_val(FALSE, $info, 'routes')) break;
		if (!$route_flds) break;
		$route_flds = explode('/', $route_flds);	//can be more than one field with '/' as a separator
		
		//build query to get route list
		$select = clb_val(FALSE, $editor_tables, $route_tab, 'select');
		$where = array();
		foreach($route_flds AS $fld) $where[] = ' (`'.$fld.'` LIKE '.clb_escape('%'.$pnum.'%').')';
		
		$query = 'SELECT '.clb_join(array_merge($select, $route_flds), '`').' FROM '.$route_tab.' WHERE '.join(' OR ', $where);
		$sel = $db->get_results($query, ARRAY_A);
		
		if (clb_count($sel) == 0)
		{
			$msg = 'There are no routes for node: '.$pnum;
		}
		else
		{
			$list = '';
			$alt = 0;
			foreach($sel AS $rec) foreach($route_flds AS $pos=>$fld)
			{
				$rnum = reset($rec);
				$route_key = key($rec);
				$script = 'map_ajax(the_map, \'show_route\', {\'pnum\':\''.$rnum.'\',\'fld\':\''.$fld.'\', \'stop\':\''.$pnum.'\'});';
				$name = $rnum.':'.$pos.': '.clb_val(FALSE, $rec, 'title');
				$list .= clb_tag('li', '', clb_tag('a', $name, '', array('onclick'=>$script, 'href'=>'javascript:void(0);')), array('class'=>'alt'.($alt++%2)));
			}
			$html .= clb_tag('ul', '', $list, array('class'=>'route_list'))."\n";
			
			$form = clb_tag('form', '', $html, array('id'=>'info_win', 'action'=>'', 'method'=>'get', 'onsubmit'=>'return false;'))."\n";							
			$xml .= clb_tag('response', '', htmlspecialchars($form), array('type'=>'bubble', 'pnum'=>$pnum))."\n";
		}
		break;
		
	case ('new_route'):	//create new route
	case ('route_stop'):	//add stop to route
	case ('route_unstop'):	//remove stop from route
	case ('show_route'):	//show route
//		qlog(__LINE__, $table, $pnum, $spec);
		require_once(CODE_DIR.'pline_lib.php');
		
		//scan tables to find routes
		foreach($editor_tables AS $table=>$info) if ($routes = clb_val(FALSE, $info, 'routes')) break;
		if (!$routes) break;	//did not find route table
		
		$routes = explode('/',$routes);
		$route_tab = $table;
		$select = clb_val(FALSE, $editor_tables, $route_tab, 'select');
		$route_key = reset($select);

		$stops = FALSE;
		$new_route = ('new_route' == $ajax);
		
		if ($new_route)
		{
			$fld = reset($routes);
			$newstop = $pnum;
			$stopindex = 0;
			
			$list = '';
			$title = clb_val(FALSE, $_REQUEST, 'title');
			$pnum = new_pnum($db, $table, $route_key);
			$ajax = 'route_stop';
		}
		else
		{
			$stopindex = clb_val(FALSE, $_REQUEST, 'stopindex');	//this only come with route_stop and route_unstop
			$newstop = clb_val(FALSE, $_REQUEST, 'newstop');		//this only come with route_stop
			$fld = clb_val(FALSE, $_REQUEST, 'fld');
			$select = array_merge($select, $routes);
			
			//select route record
			$query = 'SELECT '.clb_join($select, '`').' FROM '.$route_tab.' WHERE '.$route_key.'='.clb_escape($pnum);
			$sel = $db->get_results($query, ARRAY_A);
			$rec = (clb_count($sel) ? reset($sel) : FALSE);
			$list = clb_val(FALSE, $rec, $fld);
			$ptype = clb_val(FALSE, $rec, 'ptype');
			$title = clb_val(FALSE, $rec, 'title');
			
			//if (empty($list) && (reset($routes) == $fld) && ($fld
			
			if (empty($list))	//if no stops, start with the one just clicked
			{
				$newstop = clb_val(FALSE, $_REQUEST, 'stop');
				$stopindex = 0;
				$ajax = 'route_stop';
			}
		}
		
		if (preg_match_all('/^(\w+)\s+(.*)/m', $list, $m)) $stops = $m[1];
		
		if (in_array($ajax, array('route_stop', 'route_unstop')) && is_numeric($stopindex))
		{
			if (!$stops) $stops = array(FALSE);	//ensure loop runs at least once
			$new = '';
			foreach($stops AS $i=>$stop_pnum)
			{
				if (('route_unstop' == $ajax) && ($i == $stopindex)) continue;	//skip the stop that is to be deleted
				
				//add the stop on this line
				if ($stop_pnum) $new .= $m[1][$i].' '.trim($m[2][$i])."\n";
				
				//add new station if this is the right position
				if (('route_stop' == $ajax) && ($i == $stopindex) && $newstop)
				{
					//find maker table via ptype
					$table = clb_val(FALSE, $editor_types, $ptype, 'table');
					$query = 'SELECT lat, lng, pnum, ptype, title FROM '.$table.' WHERE pnum='.clb_escape($newstop);
					$sel = $db->get_results($query, ARRAY_A);
					if ($sel) $new .= $newstop.' '.clb_val('', reset($sel), 'title')."\n";
				}
			}
			
			if (preg_match_all('/^(\w+)\s+(.*)/m', $new, $m)) $stops = $m[1];
			
			//if forward direction and changing last item update orig, dest and title
			$rename = '';
			if ((reset($routes) == $fld) && (clb_count($stops) <= ($stopindex+2)))
			{
				$orig = reset($m[2]);
				$dest = end($m[2]);
				$rename  .= ', title='.clb_escape($orig.' - '.$dest);
				$rename  .= ', orig='.clb_escape($orig);
				$rename  .= ', dest='.clb_escape($dest);
			}

			if ($new_route)
			{
				$query = 'SELECT area, count(*) AS c FROM '.$route_tab.' WHERE ptype='.clb_escape($ptype).' GROUP BY area';
				$sel = $db->get_results($query, ARRAY_A);
				$area = (is_array($sel) ? clb_val('', reset($sel), 'area') : '');
				$query  = '';
				$query  .= ', '.$route_key.'='.clb_escape($pnum);
				$query  .= ', area='.clb_escape($area);
				$query  .= ', ptype='.clb_escape($ptype);
				$query  .= ', '.$fld.'='.clb_escape($new);
				$query  .= ', created='.clb_escape(clb_now_utc());
				$query = 'INSERT INTO '.$route_tab.' SET '.trim($query, ', ').$rename;
			}
			else
			{
				$query = 'UPDATE '.$route_tab.' SET '.$fld.'='.clb_escape($new).$rename.' WHERE '.$route_key.'='.clb_escape($pnum);
			}
			$db->query($query);

		}
		
		if ($stops)
		{
			$index = array_search(clb_val(FALSE, $_REQUEST, 'stop'), $stops);	//index of the stop we opened route from
			if (is_numeric($stopindex)) $index = Min($stopindex+($newstop?1:0), clb_count($stops)-1);	//use new index if provided and add one if new stop
			
			if (preg_match('/^(\w+)_\w+$/', reset($stops), $m))
			{
				$table = array_search($m[1], $prefixes);
				$spec = clb_val(FALSE, $editor_tables, $table);
				
				$points = array();
				$names = array();
				$pnums = array();
				$query = 'SELECT lat, lng, pnum, ptype, title FROM '.$table.' WHERE pnum IN '.clb_join($stops, TRUE);
				$sel = $db->get_results($query, ARRAY_A);
				//in theory could be multiple stop instances and need to give points in order so loop on $stops and look up record via xref
				$xref = clb_column($sel, 'pnum');
				if (clb_count($sel)) foreach($stops AS $stop_pnum)
				{
					$rec = clb_val(FALSE, $sel, array_search($stop_pnum, $xref));
					$points[] = array($rec['lat'], $rec['lng'], 0);
					$names[] = str_replace('|',',',$rec['title']);
					$pnums[] = $rec['pnum'];
					
					$rec['type'] = 'marker';
					$xml .= clb_tag('response', '', '', $rec)."\n";
					$rec_count++;
				}
				foreach($points AS $i=>$pt) if (!is_array($pt)) unset($points[$i]);	//remove non points if there are any
				$line = pline_make($points, array('color'=>'#FF0000'));

				$line['names'] = join('|',$names);
				$line['pnums'] = join('|',$pnums);
				if ($line) $line = clb_join($line, '','&','=');
				$attr = array('type'=>'route_pline', 'pnum'=>$pnum, 'title'=>$title, 'fld'=>$fld, 'ptype'=>$ptype, 'index'=>$index);
				if ($line) $xml .= clb_tag('response', '', htmlspecialchars($line), $attr)."\n";				
			}
		}
		else
		{
			$msg = 'This route (in this direction) has no stops. ';
		}
		break;
		
		
	case ('overlays'):
		if (clb_val(FALSE, $_REQUEST, 'zm') < 9)
		{
			$msg = 'Zoom in to get markers to download.';
		}
		else
		{
			$rect = array();
			$rect[] = 'lat >= '.clb_val(FALSE, $_REQUEST, 'bot');
			$rect[] = 'lng >= '.clb_val(FALSE, $_REQUEST, 'lft');
			$rect[] = 'lat <= '.clb_val(FALSE, $_REQUEST, 'top');
			$rect[] = 'lng <= '.clb_val(FALSE, $_REQUEST, 'rgt');
			
			$qtypes = preg_split('/;/',clb_val(FALSE, $_REQUEST, 'types'), -1, PREG_SPLIT_NO_EMPTY);
			$tables = array();
			foreach($qtypes AS $ptype) $tables[clb_val('bad', $editor_types, $ptype, 'table')][] = $ptype;
			
			if (!count($qtypes)) 
			{
				$msg = 'No marker types selected.';
			}
			else foreach($tables AS $table=>$list) if (($table != 'bad') && count($list))
			{	
				$prefix = clb_val(FALSE, $editor_tables, $table, 'prefix');
				$types = preg_replace('/\w+_/','',clb_join($list, TRUE));
				$query = '';
				$query .= 'SELECT lat, lng, pnum, ptype, title FROM '.$table.' WHERE ';
				$query .= ' ptype IN '.$types.' AND ('.join(' AND ',$rect).') ';
				$sel = $db->get_results($query, ARRAY_A);
				if (clb_count($sel)) foreach($sel AS $rec)
				{
					$rec['type'] = 'marker';
					if (!in_array($rec['ptype'], $list)) $rec['ptype'] = $prefix.'_'.$rec['ptype'];
					$xml .= clb_tag('response', '', '', $rec)."\n";
					$rec_count++;
				}
			}
			$msg = ($rec_count ? count($sel).' markers loaded' : 'No markers found.');
			if ('purge' == clb_val(FALSE, $_REQUEST, 'purge')) $xml .= clb_tag('response', '', '', array('type'=>'purge'))."\n";
		}
		break;
		
		
	case ('saveline'):
		require_once(CODE_DIR.'pline_lib.php');

		//fall through
		
	case ('save'):	//no real validation so just save and close
		if (!$spec)	{$msg = 'could not identify the marker type.'; break; }	//('polyline'=>'line', 'select'=>' lat, lng, pnum, ptype, title, line, aoe_data')
		
		if ($new_mark) $pnum = new_pnum($db, $table);
		
		//get field names and types
		$def = array();
		$sel = $db->get_results('describe '.$table, ARRAY_A);
		foreach($sel AS $rec) $def[$rec['Field']] = $rec['Type'];

		/*
			normally save line is handled by the normal save 
			but if we are saving a cut line, we need to save off the first part and 
			then use the normal save to create the latter part as a new record
			dont cut on first or last point of line
		*/
		$points = FALSE;
		if ('saveline' == $ajax)
		{
			$points = pline_pts_arr(clb_val(FALSE, $_REQUEST, 'polyline'));
			
			//the rest of this is only for cutting a line
			if (($cut = clb_val(FALSE, $_REQUEST, 'cut')) && (($cut+1) < clb_count($points)))
			{
				$first = array_slice($points, 0, $cut+1);	//keep points up to and including cut
				$points = array_slice($points, $cut);	//reduce points on and after cut for new record
				
				$query = save_line_query($first, $spec);
				$query .= '`'.'lat'.'`='.clb_escape($_REQUEST['lat']).',';
				$query .= '`'.'lng'.'`='.clb_escape($_REQUEST['lng']).',';
				$query .= '`'.'end1'.'`='.clb_escape($_REQUEST['end1']).',';	//adjusts mid point
				$query .= '`'.'end2'.'`='.clb_escape($_REQUEST['end2']).',';
				if ($new_mark)
				{
					$query .= '`'.'pnum'.'`='.clb_escape($pnum).',';
					$query .= '`'.'ptype'.'`='.clb_escape($ptype).',';
					if (isset($def['created'])) $query .= '`'.'created'.'`='.clb_escape(clb_now_utc()).',';
					$query = 'INSERT INTO '.$table.' SET '.trim($query,', ');
				}
				else 
				{
					$query = 'UPDATE '.$table.' SET '.trim($query,', ').' WHERE pnum='.clb_escape($pnum);
				}
				$res = $db->query($query);
				
				$xml .= refesh_marker($db, $table, $pnum, $pnum);
				unset($_REQUEST['pnum']);	//clear this so that the new marker does not remove the repositioned marker
				$pnum = new_pnum($db, $table);	//new pnum for marker
				$new_mark = TRUE;
			}
		}
		
		
		$query = '';
		
		
		if (('saveline' == $ajax) && is_array($points)) $query .= save_line_query($points, $spec);
		
		foreach($def AS $fld=>$type) switch($fld) {
		case('pnum'):
			if ($new_mark) $query .= '`'.$fld.'`='.clb_escape($pnum).',';
			break;
		case('created'):
			if ($new_mark) $query .= '`'.$fld.'`='.clb_escape(clb_now_utc()).',';
			break;
		case('ptype'):
			$query .= '`'.$fld.'`='.clb_escape($ptype).',';
			break;
		default:
			if (isset($_REQUEST[$fld])) $query .= '`'.$fld.'`='.clb_escape($_REQUEST[$fld]).',';
			break;
		}
qlog(__LINE__, $query);

		if ($new_mark) $query = 'INSERT INTO '.$table.' SET '.trim($query,', ');
		if (!$new_mark) $query = 'UPDATE '.$table.' SET '.trim($query,', ').' WHERE pnum='.clb_escape($pnum);

		$res = $db->query($query);
		
		//when saving a marker remember the type to be used as the default for next new marker.
		$name = (clb_val(FALSE, $spec, 'polyline') ? 'type_line' : 'type_mark');
		$xml .= clb_tag('response', '', $ptype, array('type'=>'state', 'pnum'=>$name))."\n";	//pnum is the "id" name is the "id" of the state we are setting
		
		$xml .= refesh_marker($db, $table, $pnum, clb_val(FALSE, $_REQUEST, 'pnum'));
		
		//want to fall through to show a bubble if saving a line
		//so break when either condition fails
		if ('saveline' == $ajax) $xml .= marker_bubble($db, $spec, $table, $pnum);
		
		break;
		
		
	case ('bubble'):

		if (!$spec || !$pnum)	{$msg = 'could not identify the marker type.'; break; }
		
		$xml .= marker_bubble($db, $spec, $table, $pnum);
	
		break;
		
	case ('delete'):
		$query = 'DELETE FROM '.$table.' WHERE pnum='.clb_escape($pnum);
		$sel = $db->query($query);
		//remove old marker, which closes info box
		$xml .= clb_tag('response', '', '', array('type'=>'remove', 'pnum'=>$_REQUEST['pnum']))."\n";		
		break;
		
	}
	
	if ($ajax)	//if ajax request, must return xml, even null response if nothing else
	{
		if ($msg) $xml .= clb_tag('response', $msg, '', array('type'=>'info'))."\n";
		if (!$xml) $xml .= clb_tag('response', '', '', array('type'=>'null'))."\n";
// qlog(__LINE__, $xml);
		clb_response('xml', $xml);
	}
}


?>