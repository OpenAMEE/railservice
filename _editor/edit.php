<?php
		
	//look for config below root first but then same folder, if in wp, there will not be a config file, as it is only to give db info
	if (!defined('DB_NAME'))
	{
		$path = dirname($_SERVER['SCRIPT_FILENAME']).'/config.php';
		if (!is_file($path)) $path = dirname(dirname($_SERVER['SCRIPT_FILENAME'])).'/config.php';
		require_once($path);
	}

	require_once(dirname(__FILE__).'/edit_ajax.php');	//in same directory as this file
qlog(__LINE__, '=========', $_REQUEST);

	if (isset($_REQUEST['ajax'])) 
	{
		ajax_request($_REQUEST['ajax']);
	}
	
	function map_js_defs($spec, $options, $btns)
	{
		$key = clb_tag('script','','',array('src'=>'http://www.google.com/jsapi?key='.GOOGLE_API_KEY, 'type'=>'text/javascript'));
		$script = 'google.load(\'maps\', \'2\');'."\n";
		$script .= 'Event.observe(window, \'load\', function (){map_setup('.clb_json($options).','.clb_json($btns).');});'."\n";
		$script .= 'var map_marker_spec = '.clb_json($spec);
		$script = clb_tag('script','', $script, array('type'=>'text/javascript'));
		return $key."\n".$script;
	}
	
	/*
		echo map_show_types('the_map', 'btn_types', $editor_types);
	*/
	function map_show_types($map, $id, $types)	//$editor_types
	{
		$html = '';
		foreach($types AS $ptype=>$rec) $html .= clb_tag('option',clb_val($ptype,$rec,'label'),'',array('value'=>$ptype));
		return clb_tag('select','',$html,array('id'=>$id, 'name'=>$id, 'multiple'=>'multiple', 'size'=>min(20,count($types))+1, 'onchange'=>'map_refresh('.$map.', \'purge\');'));
	}
	
	$btns = array('types'=>'btn_types', 'names'=>'btn_names', 'status'=>'btn_status');
	
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
        "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="content-type" content="text/html; charset=utf-8" />
	<meta http-equiv="imagetoolbar" content="false" />
	<style type="text/css">v\:* {behavior:url(#default#VML);} </style>
	<script src="../includes/scriptaculous/prototype.js" type="text/javascript" language="javascript"></script>
	<script src="edit_help.js" type="text/javascript" language="javascript"></script>
	
	<?php echo map_js_defs($editor_types, (isset($editor_map_options) ? $editor_map_options : FALSE), $btns); ?>
	
	<script src="markerclusterer.js" type="text/javascript" language="javascript"></script>
	<script src="labeledmarker.js" type="text/javascript" language="javascript"></script>
	
	<style type="text/css" title="text/css">
*
{
	margin: 0;
	padding: 0;
}

/* this height allows large page elements to be given heights as percentages of the window */
html, body{ height:100%; }

body, textarea {
	font: 62.5%/1.6 Verdana, Arial, Helvetica, sans-serif; 
}

input, select, textarea, th, td {
	font-family: inherit;
	font-size: 1em;
}

h1, h2, h3, h4, h5, h6 {
	font-weight: bold;
}

a img { border:0; }	

#framer
{
	height: 100%;
}

#the_map
{
	height: 99%;
	border: 1px solid #888;
	margin-left: 120px;
	background-color: #eee;
}

#controls
{
	position: relative;
	float: left;
	width: 100px;
	padding: 10px;
	border: 1px solid #888;
}

#controls h2 { font-size: 1.2em; }
#controls select
{
	display:block;
	width: 100px;
}

#btn_status
{
	color: #800;
	margin: 10px 0;
	padding: 10px;
	border: 1px solid #888;
	height: 100px;
	overflow: auto;
}
.block
{
	display: block;
	margin: 10px 0;
}

.labelmarker
{
	padding: 0 5px;
	border: 1px solid #000;
	background-color: #aee;
	background: #aee url(../includes/images/redcorner.gif) no-repeat top left;
}

#info_win { width: 330px; }

#info_win label
{
	clear: both;
	display:block;
	float: left;
	width: 60px;
	border:
	margin-right: 10px;
	font-weight: bold;
	font-size: 12px;
}

#info_win input.type_text,
#info_win textarea,
#info_win select
{
	clear: none;
	float: left;
	width: 250px;
	margin-bottom: 5px;
}
#info_win textarea { height: 80px; }

#info_win p { float: left; margin-right: 6px; }

ul.route_list
{
	list-style-type: none;
	list-style-position: default;
	margin:0;
	padding:0;
	border:1px solid #888;
	height: 300px;
	overflow:auto;
}
ul.route_list li { width: 310px; }
ul.route_list li a
{
	display:block;
	text-decoration: none;
	font-size: 12px;
}
ul.route_list li.alt0 a
{
	color: #000;
	background-color: #fff;
}
ul.route_list li.alt1 a
{
	color: #000;
	background-color: #eff;
}
ul.route_list li.alt0 a:hover, ul.route_list li.alt1 a:hover
{
	color: #fff;
	background-color: #4682B4;
}



#fauxconsole{position:absolute;top:0;right:0;width:300px;border:1px solid #999;font-family:courier,monospace;background:#eee;font-size:10px;padding:10px;}
html>body #fauxconsole{position:fixed;}
#fauxconsole a{float:right;padding-left:1em;padding-bottom:.5em;text-align:right;}

	</style>
	<title>Map Data Editor</title>
	
	<!--[if IE 6]><script>var msie6=true;</script><![endif]-->
	
</head>
<body onunload="GUnload()">

<div id="framer">
<div id="controls">
	<div id="btn_status"></div>
	
	<h2>commands</h2>
	<select id="btn_line" name="btn_line" multiple="multiple" onchange="map_line_tools(the_map);">
	<option value="new" title="create a new line by clicking points on the map">new line</option>
	<option value="save" title="when editing or adding a line click this to save it">save line</option>
	<option value="cut" title="click on a point to cut line in two">cut line</option>
	<option value="edit" title="add or move points on the current line">edit/add points</option>
	<option value="insert" title="same as edit but new points go on the other end of line">add to start</option>
	<option value="extract" title="click on a point within the line and get a new marker with the same coordinates">point to marker</option>
	<option value="delete" title="clicking points in the line will delete the points">delete points</option>
	<option value="cancel" title="cancel editing without saving and or hide line on map">cancel changes</option>
	</select>

	<div class="block">
	<h2>route stops</h2>
		<input type="button" onclick="map_route_line(the_map, 'prev');" value="<" title="Show the PREVIOUS stop on the route" />
		<input type="button" onclick="map_route_line(the_map, 'next');" value=">" title="Show the NEXT stop on the route" />
		<input type="button" onclick="map_route_line(the_map, 'delete');" value="-" title="Delete the current stop from the route" />
	</div>
	
	<form onsubmit="return map_ajax(the_map, 'show', {'pnum':$('find_q').value});">
		<div class="block">
			<h2>find markers</h2>
			<input type="text" value="" name="find_q" id="find_q" style="width:80px;" />
		</div>
	</form>
	
	<div class="block"><input type="button" onclick="map_refresh(the_map, 'clear');" value="refresh map" /></div>
	
	<h2>show on map</h2>
	<?php echo map_show_types('the_map', 'btn_types', $editor_types); ?>
	
	<div class="block"><input type="checkbox" onclick="map_refresh(the_map, 'clear');" id="btn_names" value="1" /><label for="btn_names">&nbsp;Show names</label></div>

</div>

<div id="the_map"></div>

</div>

</body>
</html>
