<?php
		
	//look for config below root first but then same folder, if in wp, there will not be a config file, as it is only to give db info
	if (!defined('DB_NAME'))
	{
		$path = dirname($_SERVER['SCRIPT_FILENAME']).'/config.php';
		if (!is_file($path)) $path = dirname(dirname($_SERVER['SCRIPT_FILENAME'])).'/config.php';
		require_once($path);
	}

?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" 
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml"  xmlns:v="urn:schemas-microsoft-com:vml">
<head>
	<title>Google Maps JavaScript API Example: Simple Directions</title>
	<meta http-equiv="content-type" content="text/html; charset=UTF-8"/>
	<script type="text/javascript" src="http://www.google.com/jsapi?key=<?php echo GOOGLE_API_KEY; ?>"></script>
	
	<script src="mfw_routefinder.js" type="text/javascript"></script>
	<script type="text/javascript"> 
	// Create a directions object and register a map and DIV to hold the 
	// resulting computed directions
	
	google.load('maps', '2');
	
	var map;
	var directionsPanel;
	var directions;
	
	var tubedirect;
	
	function initialize()
	{
		map = new GMap2(document.getElementById("map_canvas"));
        map.addControl(new GLargeMapControl());
        map.addControl(new GMapTypeControl());
		map.setCenter(new GLatLng(51.51515248101072, -0.13372421264648438), 14);
		directionsPanel = document.getElementById("route");
		
		//road driections object
		directions = new GDirections(map, directionsPanel);
		
		//rail directions object
		tubedirect = new mfw_Directions(map, directionsPanel, '');
		
	//	directions.load("16-18 Shelton Street, Covent Garden, London to W1A 1AB, UK");
		GEvent.addListener(directions, 'error', function(){
			  var status = directions.getStatus();
			  alert(status.code+' : '+status.request);
		});
		
// 		GEvent.addListener(directions, 'load', function(){
// 			  var status = directions.getStatus();
// 			  var route = directions.getRoute(0);
// 			  for(var i=0; i< route.getNumSteps(); i++)
// 			  alert(i+' '+ route.getStep(i).getDescriptionHtml());
// 		});
		
	}
	
	function get_directions(query, mode)
	{
		var way = 'road';
		if (mode.length) for(var i=0;i<mode.length; i++) if (mode[i].checked) way = mode[i].value;
		
		if (way == 'road') {
			directions.load(query);
		} else {
			
// 			tubedirect.loadFromWaypoints(['waterloo', new GLatLng(51.518090106431515, -0.17638206481933594), 'bristol'], {
// 				onerror: function() {
// 				  var status = tubedirect.getStatus();
// 				  alert(status.code+' : '+status.request);
// 				},
// 				color:'#0000FF',
// 				stroke:5,
// 				opacity:0.5,
// 				modes:way
// 			});
				
			tubedirect.load(query, {
				onerror: function() {
				  var status = tubedirect.getStatus();
				  alert(status.code+' : '+status.request);
				},
// 				onaddoverlay: function() {
// 					var marker = tubedirect.getMarker(0);
// 					if (typeof marker.info.name == 'string') alert(marker.info.name);
// 				},
				color:'#0000FF',
				stroke:5,
				opacity:0.5,
				modes:way
			});
			
		}
	}
	
	
	</script>
	
</head>

<body onload="initialize()" onunload="GUnload()">


    <form action="#" onsubmit="get_directions(this.address.value, this.trans); return false">
      <p>
        <input type="text" size="60" name="address" value="Elm Park to West Ruislip" />
        <input type="radio" id="roadmode" name="trans" value="road" /><label for="roadmode">Road</label>
        <input type="radio" id="railmode" name="trans" value="rail" /><label for="railmode">Rail</label>
        <input type="radio" id="tubemode" name="trans" value="tube" /><label for="tubemode">Tube</label>
        <input type="radio" id="rtmmode" name="trans" value="rtm" checked="checked" /><label for="rtmmode">Mixed</label>
        <input type="submit" value="Go!" />
      </p>
    </form>

<div id="map_canvas" style="width: 70%; height: 480px; float:left; border: 1px solid black;"></div>
<div id="route" style="width: 25%; height:480px; float:right; border; 1px solid black;"></div>
<br/>

<!--
<form name="view">
<textarea name="peeper" style="width:500px;height:200px;"></textarea>
<input type="button" value="peek" onclick="this.form.peeper.value=document.getElementById('route').innerHTML" />
</form>
-->

</body>
</html>