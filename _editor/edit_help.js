//declare marker_color_map = {'ptype1':'000888', 'ptype2':'00FF00'}	in the main page with the other type popups

var the_map = null;

function map_setup(options, controls) {
    
	var lat = options['lat'] || 53.252069;
	var lng = options['lng'] || -2.059937;

	
	var zoom = options['zoom'] || 7;
	var type = options['type'] || G_PHYSICAL_MAP;	//G_PHYSICAL_MAP, G_NORMAL_MAP, G_SATELLITE_MAP, G_HYBRID_MAP
	var ctrls = options['ctrls'] || ['zoom','type_small','overview', 'scale'];
	
	if (!GBrowserIsCompatible()) return false;
		
	var map_elem = $('the_map');
	if (!map_elem) return false;
		
	var map = new GMap2(map_elem);
	map.addMapType(G_PHYSICAL_MAP);
	map.setMapType(type);	//map.addMapType(G_PHYSICAL_MAP);
	map.mstate = {};	//hold the map states such as type and zoom as values
	map.mstate.lc = location.toString();	//window
	
	map.edit_pline = false;	//used to edit polylines
	map.route_pline = false;	//used to edit route lines
	
	map.btn_status = $(controls['status'] || 'btn_status');
	map.btn_types = $(controls['types'] || 'btn_types');
	map.btn_names = $(controls['names'] || 'btn_names');
	map.btn_line = $(controls['line'] || 'btn_line');
	if (map.btn_line) map.btn_line.selectedIndex = -1;
	
	map.setCenter(new GLatLng(lat, lng), zoom);
	map.savePosition();
	
	for(var i=0; i < ctrls.length; i++) switch(ctrls[i]) {
		case ('zoom'): map.addControl(new GLargeMapControl()); break;
		case ('zoom_small'): map.addControl(new GSmallZoomControl()); break;
		case ('nav'): map.addControl(new GSmallMapControl()); break;
		case ('type'): map.addControl(new GMapTypeControl()); break;
		case ('type_small'): map.addControl(new GMapTypeControl(1)); break;
		case ('overview'): map.addControl(new GOverviewMapControl()); break; //always bottom right ignores GControlPosition(G_ANCHOR_TOP_RIGHT, new GSize(0,0)));
		case ('scale'): map.addControl(new GScaleControl()); break;
	}
		
	//create marker base objects, and store in map object
	map.mbase = {};
	map.mbase.g = map_marker_base(21, 34, 10, 34, 10, 2, 'http://chart.apis.google.com/chart?chst=d_map_pin_shadow', 37, 34);	//google pins
	
	map.mlist = {};		//marker list to track which markers have been downloaded
	map.umarks = {};	//track temporary markers
	
	map.enableDoubleClickZoom();	//map.disableDoubleClickZoom();
	map.enableContinuousZoom();		//map.disableContinuousZoom();
	map.enableScrollWheelZoom();	//map.disableScrollWheelZoom();
	
	// drag zoom, if loaded
	if (typeof map.enableKeyDragZoom == 'function') map.enableKeyDragZoom({
		key: 'shift',
		boxStyle: {
			border: '2px dashed black',
			backgroundColor: 'blue',
			opacity: 0.2
		},
		paneStyle: {
			backgroundColor: 'gray',
			opacity: 0.2
		}
	});
	
	
	GEvent.addListener(map, 'click', function (marker, point) {
		//delay click action for 1/3 of a second to allow blocking by double click
		if (marker)	//handle clicks on markers immediately
		{ 
			map_click(map, marker, point);
		}
		else if (map_dupe_evt(300, true))	//clicks on blank space to be delayed
		{
			setTimeout(function(){ if (map_dupe_evt(300)) map_click(map, marker, point);},300);
		}
	});
	
	GEvent.addListener(map, 'dblclick', function (overlay, latlng) { map_dupe_evt(0); });
	GEvent.addListener(map, 'moveend', function () { map_refresh(map); });
	
	//GEvent.addListener(map, 'zoomend', function () { map_refresh(map); });
	//removes temp markers when closing a info window
	GEvent.addListener(map, 'infowindowclose', function () {
		map_dupe_evt(0);	//prevents new marker being added as window is closed, so can simply click away from info win to close.
		//when closing marker window, also check we remove any user markers
		for(var pnum in map.umarks) if (pnum in map.mlist) map.removeOverlay(map.mlist[pnum]);
		map.umarks = {};
	});

	//GEvent.addDOMListener(map, 'unload', GUnload); //handled on body tag
	
	// http://gmaps-utility-library.googlecode.com/svn/trunk/markerclusterer/1.0/docs/reference.html
	//MarkerClusterer(map:GMap2, opt_markers:Array of GMarker, opt_opts:MarkerClustererOptions)
	if (typeof MarkerClusterer == 'function') map.clusters = new MarkerClusterer(map, null, {'maxZoom':11});
	
	the_map = map;
	
	if (map.btn_types.value) map_refresh(map);
}

var map_stop_evt = 0;	//used to block bubbling event
function map_dupe_evt(delay, reset)
{
	var now = (new Date().getTime());
	if ((now - map_stop_evt) < delay) return false;	//return false for a second after the last pass
	map_stop_evt = (reset ? 0 : now);
	return true;
}

function map_state_to_str(state)
{
	switch(state)
	{
	case(G_PHYSICAL_MAP): return 'p';
	case(G_NORMAL_MAP): return 'n';
	case(G_HYBRID_MAP): return 'h';
	case(G_SATELLITE_MAP): return 's';
	}
	return '';
}


function map_marker_base(w, h, ax, ay, ix, iy, shadow, sw, sh)
{
	var baseIcon = new GIcon(G_DEFAULT_ICON);
	baseIcon.iconSize = new GSize(w, h);		//The pixel size of the foreground image of the icon.
	baseIcon.iconAnchor = new GPoint(ax, ay);	//The pixel coordinate relative to the top left corner of the icon image at which this icon is anchored to the map.
	baseIcon.infoWindowAnchor = new GPoint(ix, iy);	//The pixel coordinate relative to the top left corner of the icon image at which the info window is anchored to this icon.

	baseIcon.shadow = shadow;				//The shadow image URL of the icon.
	baseIcon.shadowSize = new GSize(sw, sh);	//The pixel size of the shadow image.
	return baseIcon;
}



function map_refresh(map, purge)
{
	var purge = (arguments[1] || '');
	if (purge)
	{
		map_line_tools(map, 'cancel');
		map_route_line(map, 'cancel');
	}

	if (purge && (purge == 'clear')) 
	{
		if (map.clusters) map.clusters.clearMarkers();
		map.clearOverlays();	//clear lines and/or markers if not clustered
		map.mlist = {};	
	}
	map_ajax(map, 'overlays', {'purge':purge})
}

function map_ajax(map, func, opts, body, enc_type)
{
	var bounds = map.getBounds();		//GLatLogBounds(sw,ne)
	var pt1 = bounds.getSouthWest();	//GLatLng
	var pt2 = bounds.getNorthEast();	//GLatLng
	map.mstate.bot = pt1.lat().toFixed(6);
	map.mstate.lft = pt1.lng().toFixed(6);
	map.mstate.top = pt2.lat().toFixed(6);
	map.mstate.rgt = pt2.lng().toFixed(6);
	map.mstate.mt = map_state_to_str(map.getCurrentMapType());
	map.mstate.zm = map.getZoom();	
	
	var val = '';
	var showtypes = $(map.btn_types).options;
	for(var i=0; i<showtypes.length; i++) if (showtypes[i].selected) val += showtypes[i].value+';';
	showtypes = val;
	
	var shownames = (map.btn_names && map.btn_names.checked);

	map.btn_status.innerHTML = 'Requesting data...';
	
	var prms = '?ajax='+func+'&types='+escape(showtypes);
	for(var v in map.mstate) prms += '&'+v+'='+escape(map.mstate[v]);
	if (typeof opts == 'object') for(var v in opts) prms += '&'+v+'='+escape(opts[v]);
	if (typeof opts == 'string') prms += (opts.match(/^&/)?'':'&')+opts;	//adds the '&' if opts does not begin with &
	
	var body = arguments[3] || '';	//body passed as postbody AFTER inline function definition
	var enc_type = arguments[4] || 'application/x-www-form-urlencoded';
	
	GDownloadUrl(map.mstate.lc+prms, function(data) {	
		var log = '';		
		var val;	//temp var used as and when
		var xml = GXml.parse(data);
		var markers = xml.documentElement.getElementsByTagName('response');
		var this_list = new Array();	//used to track overlays given in this download
		var cluster_list = new Array();	//if marker clustering, keep an array of the markers to add as one
		map.btn_status.innerHTML = '';
		
		for (var r = 0; r < markers.length; r++)
		{
			var response = markers[r];
			
			var pnum = response.getAttribute('pnum');
			
			var type = response.getAttribute('type');
			if (!response.firstChild) 
			{
				var content = '';
			}
			else if(typeof(response.textContent) != "undefined") 
			{
				//firefox splits large text nodes into 4k siblings so use this function to get it in one
				//http://www.coderholic.com/firefox-4k-xml-node-limit/
				content = response.textContent;
			}
			else	//MSIE does not support textContent so still use nodeValue for it.
			{
				content = response.firstChild.nodeValue;	//getAttribute('content');
			}
			
			var lat = (response.getAttribute('lat') || false);
			var lng = (response.getAttribute('lng') || false);
// console.log(type+' / '+pnum+' / '+(pnum in map.mlist));
			var ptype = (response.getAttribute('ptype') || 'cust');
			
			switch(type)
			{
			case('info'):
				map.btn_status.innerHTML = content;
				break;
				
			case('state'):
				map.mstate[pnum] = content;
				break;
				
			case('centre'):
				map.setCenter(new GLatLng(lat, lng), map.getZoom());
				break;
								
			case('onemarker'):	//same as marker but with a forced refresh
			case('marker'):
				this_list.push(pnum);	//keep track of markers in this batch
				if (pnum in map.mlist) continue;	//dont add markers if already in list
				if (!lat && !lng) continue;	//cannot position marker without coordinates
				
				var title = (response.getAttribute('title') || 'untitled');

				var mopts = {};
				mopts.title = title;
				if (title && shownames)
				{
					mopts.labelText = title;
					mopts.labelClass = 'labelmarker';
				}
				
				this_mark = map_make_marker(map, new GLatLng(lat, lng), pnum, ptype, mopts);
				
				if (map.clusters) cluster_list.push(this_mark);
				
				if (('onemarker' == type) && map.clusters && cluster_list.length)	//if only one marker add now
				{
					map.clusters.addMarkers(cluster_list);
					var cluster_list = new Array();
				}
				
				if (val = response.getAttribute('baggage')) this_mark.baggage = val;	//server may send baggage with markers to be returned when opened

				break;
				
			case('remove'):
				//remove single overlay at request of server
				if (pnum in map.mlist)
				{
					if (('delete'==func) && ('edit_pline' in map.mlist)) map_line_tools(map, 'cancel');	//only needed when we are deleting a marker with a line
					map.closeInfoWindow(0);
					var overlay = map.mlist[pnum];
					delete(map.mlist[pnum]);
					if (map.clusters) map.clusters.removeMarker(overlay);
					//if not using clusters or temp markers are added directly
					if (!map.clusters || (overlay.pnum.length > 10)) map.removeOverlay(overlay);	
				}	
				break;

			case('purge'):
				//remove overlays on the map but not in current download list
				map.closeInfoWindow(0);
				for(pnum in map.mlist) if (this_list.indexOf(pnum) < 0)
				{
					var overlay = map.mlist[pnum];
					if (map.clusters)  map.clusters.removeMarker(overlay);
					if (!map.clusters)  map.removeOverlay(overlay);
					delete(map.mlist[pnum]);
				}	
				break;

			case('edit_pline'):
				map_line_tools(map, 'cancel');
				map_add_polyline(map, pnum, ptype, content, type);
				break;
								
			case('route_pline'):
				map_route_line(map, 'cancel');
				var pline = map_add_polyline(map, pnum, ptype, content, type);
				var index = (response.getAttribute('index') || 0);
				pline.fld = (response.getAttribute('fld') || '');
				pline.title = (response.getAttribute('title') || '');
				map_route_line(map, 'init', index, pline);
				break;
								
			case('bubble'):
				if (pnum in map.mlist)
				{
					map.mlist[pnum].openInfoWindowHtml(content);
					var form = $('info_win');
					if (form && form.elements && form.elements.length) Form.focusFirstElement(form);
				}
				break;
				
			}
		}		
		if (map.clusters && cluster_list.length) map.clusters.addMarkers(cluster_list);
		
	}, body, enc_type);
	
	return false;
}



function map_add_polyline(map, pnum, ptype, content, ident)
{
	var pline = content.toQueryParams();
	var encodedPolyline = new GPolyline.fromEncoded(pline);	//eval(content)
	encodedPolyline.pnum = pnum;
	encodedPolyline.ptype = ptype;
	if (pline.names) encodedPolyline.names = pline.names.split('|');
	if (pline.pnums) encodedPolyline.pnums = pline.pnums.split('|');
	map.addOverlay(encodedPolyline);
	map.mlist[ident] = encodedPolyline;
	return encodedPolyline;
}


//guest markers as 14 hex digits of lat+180/lng+180 with 'b' prefix
function mfw_temp_pnum(point)
{
	var txt = 'b';
	var len = 7;
	var dec = Math.round((point.y+180)*1e5);
	var hex = dec.toString(16);
	if (len && (hex.length < len)) hex = '00000000000'.slice(0,(len-hex.length))+hex;
	txt += hex;
	var dec = Math.round((point.x+180)*1e5);
	var hex = dec.toString(16);
	if (len && (hex.length < len)) hex = '00000000000'.slice(0,(len-hex.length))+hex;
	txt += hex;
	return txt;	
}



function map_make_marker(map, point, pnum, ptype, mopts)
{
	
	if (typeof map.mlist[pnum] != 'undefined') return map.mlist[pnum];	//don't add duplicates
	
	var mopts = (arguments[4] || {});
	
	var mc = '888888';	//default colour grey
	var drag = false;
	if (map_marker_spec && map_marker_spec[ptype])
	{
		if (typeof map_marker_spec[ptype].color != 'undefined') mc = map_marker_spec[ptype].color;
		if (typeof map_marker_spec[ptype].drag != 'undefined') drag = map_marker_spec[ptype].drag;
	}
	
// 	mopts.ptype = ptype;
	mopts.icon = new GIcon(map.mbase.g);
	mopts.icon.image = 'http://chart.apis.google.com/chart?cht=mm&chs=24x32&chco=FFFFFF,'+mc+',000000&ext=.png';
	
	mopts.draggable = drag;
	
	if (mopts.labelText)
	{
		var this_mark = new LabeledMarker(point, mopts);
	}
	else
	{
		var this_mark = new GMarker(point, mopts);
	}
	this_mark.pnum = pnum;		//make sure maker knows its own id
	if (ptype) this_mark.ptype = ptype;	//markers should know their own type
	
	map.mlist[pnum] = this_mark;
	
	if (!map.clusters) map.addOverlay(this_mark);
	
	//GEvent.addListener(marker, "dragend", function() { updateMarker(marker, cells);});	//applied marker by marker
	
	return this_mark;
}



function map_click(map, marker, point, extract)
{

	if (map.edit_pline && !extract)	//if editing a user line, dont open a marker window
	{
		//polylines do not have .getLatLng() so if it is present then it is a click on a marker
		if (marker && marker.getLatLng && marker.pnum) if (confirm('Add marker position to line and save?')) map_line_tools(map, 'save', marker.getLatLng());
		if (point && !marker) map_line_tools(map, 'vertex', point);
		return;
	}
	
	if (map.route_pline && marker)
	{
		if (marker && marker.getLatLng) map_route_line(map, 'add', marker)
		return;
	}
	
	if (point)
	{			
		var pnum = mfw_temp_pnum(point);
		var ptype = ((typeof map.mstate['type_mark'] != 'undefined') ? map.mstate['type_mark'] : 'cust');
		var marker = map_make_marker(map, point, pnum, ptype);
		map.addOverlay(marker);
		map.umarks[pnum] = marker;
	}
	
	if (marker && marker.pnum)	//display info box
	{
		map_ajax(map, 'bubble', {'pnum':marker.pnum, 'ptype':marker.ptype});		
	}
	
}



function map_marker_info(map, action, pnum, form)
{	
	if (!(pnum in map.mlist)) return;
	var marker = map.mlist[pnum];
	var prms = 'ptype='+marker.ptype+'&pnum='+marker.pnum;
	switch(action) {
	case('btn_save'):
		prms += '&'+form.serialize();
		map_ajax(map, 'save', prms);
		break;
		
	case('btn_delete'):
		if (confirm('Permanently delete this marker? (this is your only warning)')) map_ajax(map, 'delete', prms);
		break;
		
	case('btn_revert'):
		break;
		
	case('btn_editline'):	//	map.closeInfoWindow(0);
		map_line_tools(map, 'edit');
		break;
		
	case('btn_newline'):
		map_line_tools(map, 'new', marker.getLatLng());
		break;
	}
}



/*
	these are called from the buttons (and manages the button states) on the segment drawing instructions
	some also also called to fake button preses such as save when other actions occur
*/
function map_line_tools(map, tool, point, color)
{
	var tool = (arguments[1] || map.btn_line.value);
	var point = (arguments[2] || []);	//[new GLatLng(37.4419, -122.1419),new GLatLng(37.4519, -122.1519)]
	var index = false;
	
	if (tool == 'new') map_line_tools(map, 'cancel');	//ensures old line is dead and gone

	if (!map.edit_pline && (tool != 'cancel'))	//if no line, add one, and go into add points mode
	{
		if (tool == 'new')
		{
			var color = (arguments[3] || '#ff0000');	//red
			if (!(point instanceof Array)) point = new Array(point);
			map.edit_pline = new GPolyline(point, color, 5);	//GPolyline(points, color?, weight?, opacity?, opts?) {geodesic}
			map.edit_pline.pnum = false;
			map.edit_pline.ptype = ((typeof map.mstate['type_line'] != 'undefined') ? map.mstate['type_line'] : 'cust');
			map.addOverlay(map.edit_pline);
			tool = map.btn_line.value = 'edit';	//set item in interface contol
			map.btn_status.innerHTML = 'Just click the map to start adding points to your new line.';
		}
		else if (!('edit_pline' in map.mlist))
		{
			map.btn_line.selectedIndex = -1;
			map.btn_status.innerHTML = 'You must select or create a line to use this function.';
			return;
		}
		else if (map.mlist['edit_pline'].ptype && map.mlist['edit_pline'].ptype.match(/\w+_\w+/))
		{
			map.btn_line.selectedIndex = -1;
			map.btn_status.innerHTML = 'You cannot edit this type of line as it is automatically generated.';
			return;
		}
		else 
		{
			map.edit_pline = map.mlist['edit_pline'];	//remove existing line if there is one
		}
		
		map.closeInfoWindow(0);
		
		map.edit_pline.enableEditing();
		
		//add event listeners to line that has just been activated or created
		GEvent.addListener(map.edit_pline, 'click', function(latlng, index) { map_line_tools(map, 'click', latlng, index); });
		GEvent.addListener(map.edit_pline, 'endline', function() { map_line_tools(map, 'save'); });
	}
	
	if ((tool == 'click') && (typeof arguments[3] == "number"))
	{
		var index = arguments[3];
		switch(map.btn_line.value) {
		case ('delete'): map.edit_pline.deleteVertex(index); break;
		case ('cut'): tool = 'save'; break;
		case ('extract'): map_click(map, null, map.edit_pline.getVertex(index), true); break;
		}
	}
	
	if ((tool == 'cancel') && map.edit_pline && !confirm('Abandon all changes made to this line?')) tool = '';
	
	switch(tool) {
	case ('save'):
		if (!(point instanceof Array) && (map.btn_line.value != 'cut')) 
		{
			//ending by clicking a marker passes the marker point in, so add it to end of the line
			var pos = ((map.btn_line.value == 'insert') ? 0 : map.edit_pline.getVertexCount());
			map.edit_pline.insertVertex(pos,  point);
		}
		var data = '';
		for(var i=0; i<map.edit_pline.getVertexCount(); i++)
		{
			var point = map.edit_pline.getVertex(i);
			data += point.lat().toFixed(6)+','+point.lng().toFixed(6)+',0\n';
		}
		if (!map.edit_pline.pnum) map.edit_pline.pnum = mfw_temp_pnum(map.edit_pline.getVertex(0));
		var prms = 'ptype='+map.edit_pline.ptype+'&pnum='+map.edit_pline.pnum;
		if (index) prms += '&cut='+index;	//get the line cut on the server.
		data = 'polyline='+escape(data);
		map_ajax(map, 'saveline', prms, data);
		
		//fall through
	case ('cancel'):
		if ('edit_pline' in map.mlist)
		{
			if (!map.edit_pline) map.edit_pline = map.mlist['edit_pline'];
			delete(map.mlist['edit_pline']);
		}
		if (map.edit_pline)
		{
			map.edit_pline.disableEditing();	//ensure not in edit mode because cannot remove overlays in edit mode
			map.removeOverlay(map.edit_pline);
		}
		map.edit_pline = false;
		map.btn_line.selectedIndex = -1;
		break;
		
	case ('vertex'):	//add points to the ends of the line (for all new points, called from click method)
		if ((map.btn_line.value != 'insert') && (map.btn_line.value != 'edit')) break;
		var pos = ((map.btn_line.value == 'insert') ? 0 : map.edit_pline.getVertexCount());
		map.edit_pline.insertVertex(pos,  point);
		break;
	case ('insert'):	//add points but to the beginning end of the line
		map.btn_line.value = tool;
		break;
	case ('edit'):	//put line into edit mode to move or add intermediate verticies
		map.btn_line.value = tool;
		break;
	case ('cut'):	//cut line at a vertex, when clicked
		break;
	case ('delete'):	//delete points, occurs when clicking points.
		map.btn_line.value = tool;
		break;
	}
}


function map_route_line(map, func, index, pline)
{
	if (!map.route_pline && (func != 'init')) return;
	
	switch(func) {
	case ('cancel'):
		if (map.route_pline) map.removeOverlay(map.route_pline);
		if ('route_pline' in map.mlist) delete(map.mlist['route_pline']);
		if ('route_circle' in map.mlist)
		{
			map.removeOverlay(map.mlist['route_circle']);
			delete(map.mlist['route_circle']);
		}
		map.route_pline = false;
		break;
		
	case ('init'):
		
		map.closeInfoWindow(0);
		
		var index = parseInt(arguments[2] || 0);
		map.route_pline = pline;
		map.route_point = Math.min(index, map.route_pline.getVertexCount());

		var icon = new GIcon();
		icon.iconSize = new GSize(55, 55);
		icon.iconAnchor = new GPoint(27, 27);
		icon.image = map.mstate.lc.replace(/[^\/]+$/, 'circle.png');

		var point = map.route_pline.getVertex(map.route_point);
		var lat = point.lat();
		var lng = point.lng();
		
		var pos = new GLatLng(lat+1e-5, lng);
		var marker = new GMarker(pos, icon);
		map.addOverlay(marker);
		map.mlist['route_circle'] = marker;
		break;
		
	case ('add'):
		var marker = (arguments[2] || false);
		if (marker && marker.pnum && marker.ptype && map.route_pline.ptype)
		{
			if ((map.route_pline.pnums.indexOf(marker.pnum)>=0) && !confirm('This stop is already in the route, add it a second time?'))
			{
				//do nothing user canclled
			}
			else if (marker.ptype != map.route_pline.ptype)
			{
				map.btn_status.innerHTML = 'You can only add markers of the same type to a route. ';
			}
			else
			{
				var pline = map.route_pline;
				var prms = {'ptype':pline.ptype, 'pnum':pline.pnum, 'fld':pline.fld, 'stopindex':map.route_point};	//stop index to add to after
				prms['newstop'] = marker.pnum;
				map_ajax(map, 'route_stop', prms);
			}
		}
		break;

	case ('delete'):
		//var dlg = (arguments[3] || true);
		var title = 'unknown';
		if (map.route_pline.names && (map.route_point < map.route_pline.names.length)) title = map.route_pline.names[map.route_point];
		if (confirm('Delete the stop "'+title+'" from the route? (The change is saved immediately)'))
		{
				var pline = map.route_pline;
			var prms = {'ptype':pline.ptype, 'pnum':pline.pnum, 'fld':pline.fld, 'stopindex':map.route_point};	//stop index to delete from route.
			map_ajax(map, 'route_unstop', prms);
		}
		break;
		
	case ('next'):
	case ('prev'):
		//select the point
		if (func == 'next') map.route_point += 1;
		if (func == 'prev') map.route_point -= 1;
		if (map.route_point < 0) map.route_point = map.route_pline.getVertexCount()-1;
		if (map.route_point >= map.route_pline.getVertexCount()) map.route_point = 0;
		
		//move the circle marker
		var point = map.route_pline.getVertex(map.route_point);
		var lat = point.lat();
		var lng = point.lng();
		var pos = new GLatLng(lat+1e-5, lng);
		map.mlist['route_circle'].setLatLng(pos);
		
		//if current mark outside viewport, center stop
		var bounds = map.getBounds();		//GLatLogBounds(sw,ne)
		var pt1 = bounds.getSouthWest();	//GLatLng
		var pt2 = bounds.getNorthEast();	//GLatLng
		if ((lat < pt1.lat()) || (lng < pt1.lng()) || (lat > pt2.lat()) || (lng > pt2.lng())) map.setCenter(pos, map.getZoom());
		

		break;
		
	case ('add'):
		break;
	}
	
	if (map.route_pline)
	{
		var br = '<'+'br /'+'>';
		var msg = 'Stop '+map.route_point;
		msg += ' of '+map.route_pline.getVertexCount();
		msg += br+map.route_pline.pnums[map.route_point];
		msg += br+map.route_pline.names[map.route_point];
		msg += br+'('+map.route_pline.title+')';
		
		map.btn_status.innerHTML = msg;
	}
	
	var index = 0;
	

}





/* Faux Console by Chris Heilmann http://wait-till-i.com 
http://icant.co.uk/sandbox/fauxconsole/
	depends on stack_handler() above
	and the css code below
#fauxconsole{position:absolute;top:0;right:0;width:300px;border:1px solid #999;font-family:courier,monospace;background:#eee;font-size:10px;padding:10px;}
html>body #fauxconsole{position:fixed;}
#fauxconsole a{float:right;padding-left:1em;padding-bottom:.5em;text-align:right;}
	
*/

if(!window.console)
{
	var console={
		init:function(){
			console.d=document.createElement('div');
			document.body.appendChild(console.d);
			var a=document.createElement('a');
			a.href='javascript:console.hide()';
			a.innerHTML='close';
			console.d.appendChild(a);
			var a=document.createElement('a');
			a.href='javascript:console.clear();';
			a.innerHTML='clear';
			console.d.appendChild(a);
			var id='fauxconsole';
			if(!document.getElementById(id)){console.d.id=id;}console.hide();
		},
		
		hide:function(){console.d.style.display='none';},
		
		show:function(){console.d.style.display='block';},
		
		log:function(o){console.d.innerHTML+='<br/>'+o;console.show();},
		
		clear:function(){
			console.d.parentNode.removeChild(console.d);
			console.init();
			console.show();
		}
		
	};
	
	var old_func = window.onload;
	window.onload = function() {
		if (typeof old_func == 'function') old_func();
		console.init();
	}
}

