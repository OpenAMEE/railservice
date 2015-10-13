/*
	mfw_routefinder.js
	(c) 2008 Logogriph Ltd
	http://www.logogriph.com
*/



/*
	RRDirections class constructor, analogous to GDirections
	
	PUBLIC FUNCTIONS
	
	map: reference to the GMap to display route line
	panel: element id or DOM element of html element to display results which will replace innerHTML of element
	url: relative url or absolute url to the route finder service
*/
function mfw_Directions(map, panel, url)
{
	this.map	= arguments[0] || null;
	this.panel	= arguments[1] || null;
	this.url	= arguments[2] || 'route_service.php';

	this.overlays = [];
	this.clear();
}

/*
	load a new set of directions based on a query string
	
	query: is a string like "london paddington to bristol temple meads" 
		the three letter station codes can be used instead of station names
		using less specific names like "London to Bristol" will work because the station names contain the place name, 
		but obviously which station is chosen if multiples match may vary
		if you have lat/lng coordinates it may be better to use loadFromWaypoints(waypoints, queryOpts)
	queryOpts: {locale:"en_GB", getPolyline:true, getSteps:true, preserveViewport:true}
	//add event listenders onload, onaddoverlay, onerror as queryOpts values and NOT via GEvent.addListener()
*/
mfw_Directions.prototype.load = function(query, queryOpts)
{
	this.send_request('&q='+query, queryOpts);	
}

/*
	waypoints is an array of strings or GLatLng elements
*/
mfw_Directions.prototype.loadFromWaypoints = function(waypoints, queryOpts)
{
	if (!(waypoints instanceof Array))
	{
		this.clear(400, 'loadFromWaypoints: waypoints is not an array');
	}
	else
	{
		var query = '';
		for(var i=0; i<waypoints.length; i++)
		{
			if ((typeof waypoints[i] == 'object') && (typeof waypoints[i].lat == 'function')) //(waypoints[i] instanceof GLatLng)
			{
				query += '&w'+i+'='+escape(waypoints[i].lat()+','+waypoints[i].lng());
			}
			else
			{
				query += '&w'+i+'='+escape(waypoints[i]);
			}
		}
		this.send_request(query, queryOpts);
	}
}




mfw_Directions.prototype.clear = function()
{
	this.status = arguments[0] || 0;
	this.error = arguments[1] || '';
	this.info = false;	
	this.options = false;	
	if (this.map && (this.overlays.length))
	{
		for(var i=0; i<this.overlays.length; i++)
		{
			map.removeOverlay(this.overlays[i]);
		}
	}
	this.overlays = [];
	if (this.panel) this.panel.innerHTML = '';
}





/*
returned structure
{
	'error':200,
	'error_str':'no errors',
	'NumRoutes':1,
	'NumGeocodes':2,
	'CopyrightsHtml':'&copy;2008 Logoriph Ltd',
	'SummaryHtml':'111.3 mi',
	'Distance':{'meters':179075, 'miles':'111.3 mi', 'html':'111.3 mi'},
	'Duration':{},
	'Routes':[
		{
			'NumSteps':4,
			'StartGeocode':{'address':'', 'Point':{'coordinates':[51.516624, -0.176999, '0']}},
			'EndGeocode':{'address':'', 'Point':{'coordinates':[51.514118, -2.543220, '0']}},
			'EndLatLng':{'lat':51.565549, 'lng':-1.785453},
			'SummaryHtml':'111.3 mi',
			'Distance':{'meters':179075, 'miles':'111.3 mi', 'html':'111.3 mi'},
			'Duration':{}, 
			'Steps':[
				{'lat':51.516663, 'lng':-0.176931, 'PolylineIndex':'0', 'DescriptionHtml':'To <b>Slough</b>, rail stations',
					'Distance':{'meters':29421, 'miles':'18.3 mi', 'html':'111.3 mi'}, 'Duration':{}},
				{'lat':51.512039, 'lng':-0.591712, 'PolylineIndex':'0', 'DescriptionHtml':'To <b>Reading</b>, rail stations',
					'Distance':{'meters':28084, 'miles':'17.5 mi', 'html':'111.3 mi'}, 'Duration':{}},
				{'lat':51.459139, 'lng':-0.971563, 'PolylineIndex':'0', 'DescriptionHtml':'To <b>Didcot Parkway</b>, rail stations',
					'Distance':{'meters':27518, 'miles':'17.1 mi', 'html':'111.3 mi'}, 'Duration':{}},
				{'lat':51.611166, 'lng':-1.242633, 'PolylineIndex':'0', 'DescriptionHtml':'To <b>Bristol Parkway</b>, rail stations',
					'Distance':{'meters':94052, 'miles':'58.4 mi', 'html':'111.3 mi'}, 'Duration':{}}
			]
		}
	],
	'Bounds':{'n':51.62132, 's':51.45861, 'e':-0.17693, 'w':-2.54325},
	'Polylines':[
		{'ident':'seg0', 'pline':{'color':'#0000ff', 'weight':5, 'opacity':0.5, 'zoomFactor':2, 'numLevels':18, 'points':'data', 'levels':'data'}}
	],
	'html':'table',
	'Markers':[
		{'url':'http://chart.apis.google.com/chart?chst=d_map_xpin_letter&chld=pin|A|65BA4A|000000|000000', 'lat':51.516624, 'lng':-0.176999, 'name':'London Paddington', 'desc':'Praed Street, London, Greater London'},
		{'url':'http://chart.apis.google.com/chart?chst=d_map_xpin_letter&chld=pin|B|65BA4A|000000|000000', 'lat':51.514118, 'lng':-2.543220, 'name':'Bristol Parkway', 'desc':'Stoke Gifford, Bristol Parkway, BS34 8PU'}
	],
	'querytime':'137.386 (interpret); 132.652 (load array); 0.545 (917); 0.999 (65); 1370.39 (shortest path); 29.884 (shortest changes); 41.915 (504); 25.002 (expansion); 118.713 (querytime); '
}
 
	
*/
/*
	returns a GGeoStatusCode value
*/
mfw_Directions.prototype.getStatus = function()
{
	return {'code':this.status, 'request':this.error};
}

/*
	returns a GLatLngBounds object
*/

mfw_Directions.prototype.getBounds = function()
{
	if (!this.info || !this.info.Bounds) return false;
	return new GLatLngBounds( new GLatLng(this.info.Bounds.s, this.info.Bounds.w), new GLatLng(this.info.Bounds.n, this.info.Bounds.e));
}

mfw_Directions.prototype.getNumRoutes = function()
{
	if (!this.info) return false;
	return this.info.NumRoutes;
}

mfw_Directions.prototype.getRoute = function(i)
{
	if (!this.info || !this.info.Routes || !this.info.Routes.length || (i >= this.info.Routes.length)) return false;
	return new mfw_Route(this.info.Routes[i]);
}

mfw_Directions.prototype.getNumGeocodes = function()
{
	if (!this.info) return false;
	return this.info.NumGeocodes;
}


mfw_Directions.prototype.getGeocode = function(i)
{
	if (!this.info || !this.info.Routes || !this.info.Routes.length || (i > this.info.Routes.length)) return false;
	return ((i == this.info.Routes.length) ? this.info.Routes[i-1].getEndGeocode() : this.info.Routes[i].getStartGeocode());	
}

mfw_Directions.prototype.getSummaryHtml = function()
{
	if (!this.info || !this.info.SummaryHtml) return false;
	return this.info.SummaryHtml;
}

mfw_Directions.prototype.getDistance = function() 	//miles, km, html
{
	if (!this.info || (typeof this.info.Distance != 'object')) return false;
	return this.info.Distance;	
}

mfw_Directions.prototype.getDuration = function()	//seconds, html
{
	if (!this.info || (typeof this.info.Duration != 'object')) return false;
	return this.info.Duration;	
}

mfw_Directions.prototype.getPolyline = function()
{
	if (!this.info || (typeof this.info.Polyline == 'undefined') || !this.info.Polyline.length) return false;
	return this.info.Polyline[0];	//unlike google interface we can return an array of polylines, but currently only returning one
}

mfw_Directions.prototype.getMarker = function(i, gstyle)	//optional gstyle=true returns a GMarker object, if false or null returns {lat, lng, url, name, addr}
{
	if (!this.info || !this.info.Markers || !this.info.Markers.length || (i >= this.info.Markers.length)) return false;
	
	//if we have the overlays return the actual GMarker, like the GDirections class
	if (gstyle && this.overlays && this.overlays.length && (i < this.overlays.length)) return this.overlays[i];
	
	//if not then return the raw marker info
	return this.info.Markers[i];
}

///**** mfw_Route class

function mfw_Route(r)
{
	return r;
}

mfw_Route.prototype.getNumSteps = function() //Returns the number of steps in this route.
{
	return this.NumSteps || 0;
}

mfw_Route.prototype.getStep = function(i)	//Return the GStep object for the ith step in this route.
{
	if (!this.Steps || !this.Steps.length || (this.Steps.length <= i)) return false;
	return new mfw_Step(this.Steps[i]);
}

///**** mfw_Geocode class
/*
	{
		"address": "1600 Amphitheatre Pkwy, Mountain View, CA 94043, USA",
		"AddressDetails": {
			"Country": { "CountryNameCode": "US",
				"AdministrativeArea": { "AdministrativeAreaName": "CA",
					"SubAdministrativeArea": { "SubAdministrativeAreaName": "Santa Clara",
						"Locality": { "LocalityName": "Mountain View",
							"Thoroughfare": { "ThoroughfareName": "1600 Amphitheatre Pkwy" },
							"PostalCode": { "PostalCodeNumber": "94043" }
						}
					}
				}
			},
			"Accuracy": 8
		},
		"Point": { "coordinates": [-122.083739, 37.423021, 0] }
	}

*/


mfw_Route.prototype.getStartGeocode = function() //Return the geocode result for the starting point of this route. 
{
	if (!this.StartGeocode || !this.StartGeocode) return false;
	return this.StartGeocode;
}


mfw_Route.prototype.getEndGeocode = function() //Return the geocode result for the ending point of this route.
{
	if (!this.EndGeocode || !this.EndGeocode) return false;
	return this.EndGeocode;
}


mfw_Route.prototype.getEndLatLng = function() //Returns a GLatLng object for the last point along the polyline for this route. Note that this point may be different from the lat,lng in GRoute.getEndGeocode() because getEndLatLng() always returns a point that is snapped to the road network. There is no corresponding getStartLatLng() method because that is identical to calling GRoute.getStep(0).getLatLng().
{
	if (!this.EndLatLng || (typeof this.EndLatLng.lat != 'number') || (typeof this.EndLatLng.lng != 'number')) return false;
	return new GLatLng(this.EndLatLng.lat, this.EndLatLng.lng);
}


mfw_Route.prototype.getSummaryHtml = function() //Returns an HTML snippet containing a summary of the distance and time for this route.
{
	if (!this.SummaryHtml || !this.SummaryHtml) return false;
	return this.SummaryHtml;
}


mfw_Route.prototype.getDistance = function() //Returns an object literal representing the total distance of this route. See GDirections.getDistance() for the structure of this object.
{
	if (!this.Distance || (typeof this.Distance != 'object')) return false;
	return this.Distance;	
}


mfw_Route.prototype.getDuration = function() //Returns an object literal representing the total time of this route. See GDirections.getDuration() for the structure of this object.
{
	if (!this.info || (typeof this.Duration != 'object')) return false;
	return this.Duration;	
}

///*** mfw_Step class

function mfw_Step(s)
{
	return s;
}

mfw_Step.prototype.getLatLng = function()	//Returns a GLatLng object for the first point along the polyline for this step.
{
	if ((typeof this.lat != 'number') || (typeof this.lng != 'number')) return false;
	return new GLatLng(this.lat, this.lng);

}

mfw_Step.prototype.getPolylineIndex = function()	//Returns the index of the first point along the polyline for this step.
{
	if (typeof this.PolylineIndex != 'number') return false;
	return this.PolylineIndex;	
}

mfw_Step.prototype.getDescriptionHtml = function()	//Return an HTML string containing the description of this step.
{
	if (typeof this.DescriptionHtml != 'string') return false;
	return this.DescriptionHtml;	
}

mfw_Step.prototype.getDistance = function()	//Returns an object literal representing the total distance of this step. See GDirections.getDistance = function()	// for the structure of this object.
{
	if (!this.Distance || (typeof this.Distance != 'object')) return false;
	return this.Distance;
}

mfw_Step.prototype.getDuration = function()	//Returns an object literal representing the total time of this step. See GDirections.getDuration() for the structure of this object.
{
	if (!this.info || (typeof this.Duration != 'object')) return false;
	return this.Duration;	
}


function mfw_Geocode(g)
{
	
}


/*
	PRIVATE FUNCTIONS for mfw_Directions
*/

mfw_Directions.prototype.send_request = function (query, queryOpts)
{
	var options = queryOpts || {};
	
	this.preserveViewport = (('preserveViewport' in options) && options.preserveViewport);

	this.onload = false;
	if (('onload' in options) && (typeof options.onload == 'function')) this.onload = options.onload.mfw_dir_bind(this);
	this.onaddoverlay = false;
	if (('onaddoverlay' in options) && (typeof options.onaddoverlay == 'function')) this.onaddoverlay = options.onaddoverlay.mfw_dir_bind(this);
	this.onerror = false;
	if (('onerror' in options) && (typeof options.onerror == 'function')) this.onerror = options.onerror.mfw_dir_bind(this);

	if ('locale' in options) query += '&loc='+escape(options.locale);
	
	//polyline done by default but if no map and no option requesting it then turn it off
	if (!this.map || (('getPolyline' in options) && !options.getPolyline)) query += '&gp=0';
	
	//steps done by default but if no panel and no option requesting it then turn it off
	if (!this.panel || (('getSteps' in options) && !options.getSteps)) query += '&gs=0';
	
	if ('color' in options) query += '&c='+escape(options.color);
	if ('stroke' in options) query += '&k='+escape(options.stroke);
	if ('opacity' in options) query += '&t='+escape(options.opacity);
	if ('units' in options) query += '&u='+escape(options.units);
	if ('opto' in options) query += '&o='+escape(options.opto);	//dist|changes|stops|time
	if ('modes' in options) query += '&m='+escape(options.modes);	//tube|rail|tram|all
	
	mfw_dir_json.make(this.url+'?'+query.slice(1), this);

}


/*
	Note that the load() method initiates a new query, which in turn
	triggers a "load" event once the query has finished loading. The "load"
	event is triggered before any overlay elements are added to the
	map/panel. "addoverlay": This event is triggered after the polyline
	and/or textual directions components are added to the map and/or DIV
	elements. Note that the "addoverlay" event is not triggered if neither
	of these elements are attached to a GDirections object. "error": This
	event is triggered if a directions request results in an error. Callers
	can use GDirections.getStatus() to get more information about the error.
	When an "error" event occurs, no "load" or "addoverlay" events will be
	triggered. (Since 2.81)
*/


function mfw_dir_result(sid, rdata)
{
	
	var inst = mfw_dir_json.get_baggage(sid);

	if ((typeof inst != 'undefined') && (inst != null))
	{
		inst.clear.call(inst);
		
		if ((typeof rdata != 'object') || !('error' in rdata))
		{
			inst.status = 500;
			inst.error = 'no data returned from web server';
			
		}
		else
		{
			inst.info = rdata;
			inst.status = rdata.error || 0;
			inst.error = rdata.error_str || '';
			
			if (inst.status != 200)
			{
				if (typeof inst.onerror == 'function') inst.onerror(inst.info.status, inst.info.error);
				
			}
			else
			{
				if (typeof inst.onload == 'function') inst.onload();
				
				if (inst.map)
				{
					if ((typeof inst.info.Markers != 'undefined') && inst.info.Markers)
					{
						for(var i=0; i<inst.info.Markers.length; i++)
						{
							var marker = inst.info.Markers[i];
							if (typeof marker == 'object')
							{
								mobject = mfw_dir_add_marker(inst.map, marker.lat, marker.lng, marker.url);
								mobject.info = marker;
								inst.overlays[i] = mobject;
							}
						}
					}
					
					if ((typeof inst.info.Polylines != 'undefined') && inst.info.Polylines)
					{
						if (!this.preserveViewport && (typeof inst.info.Bounds != 'undefined'))
						{
							var pt1 = new GLatLng(inst.info.Bounds.s, inst.info.Bounds.w);
							var pt2 = new GLatLng(inst.info.Bounds.n, inst.info.Bounds.e);
							var rout_bounds = new GLatLngBounds(pt1, pt2);
							var new_zoom = inst.map.getBoundsZoomLevel(rout_bounds);
							inst.map.setCenter(new GLatLng((inst.info.Bounds.s+inst.info.Bounds.n)/2, (inst.info.Bounds.w+inst.info.Bounds.e)/2), new_zoom);
						}
						
						//Polyline could be a single line or an array of lines
						if (inst.info.Polylines instanceof Array)
						{
							var plines = inst.info.Polylines
						}
						else
						{
							var plines = new Array();
							plines.push({ident:'singlepath', pline:inst.info.Polylines});

						}
						
						for(var i = 0; i < plines.length; i++)
						{
							var encodedPolyline = new GPolyline.fromEncoded(plines[i].pline, {clickable:false});
							inst.map.addOverlay(encodedPolyline);
							inst.overlays.push(encodedPolyline);
						}
						
	
						if (typeof inst.onaddoverlay == 'function') inst.onaddoverlay();
					}
						
				}

				if (inst.panel && (typeof inst.info.html != 'undefined') && inst.info.html)
				{
					inst.panel.innerHTML = inst.info.html;
				}
			}
		}
	}
	mfw_dir_json.cleanup(sid);
}

/*
	UTILITY FUNCTIONS
*/

function mfw_dir_iterable(iterable)
{
  if (!iterable) return [];
  if (iterable.toArray) return iterable.toArray();
  var length = iterable.length, results = new Array(length);
  while (length--) results[length] = iterable[length];
  return results;
}

Function.prototype.mfw_dir_bind = function()
{
	if (arguments.length < 2 && arguments[0] === undefined) return this;
	var __method = this, args = mfw_dir_iterable(arguments), object = args.shift();
	return function()
	{
		return __method.apply(object, args.concat(mfw_dir_iterable(arguments)));
	}
}

var mfw_dir_json = {

	scriptCounter: 1,
	baggage: {},

	make: function (url, baggage)
	{
		var script_id = 'JSONreq' + this.scriptCounter++;
		url += ((url.indexOf('?') >= 0) ? '&' : '?')+'sid='+script_id; //add the script id to the parameters so the call back can clean up the script object

		var scriptObj = document.createElement("script");
		
		// Add script object attributes
		scriptObj.setAttribute("type", "text/javascript");
		scriptObj.setAttribute("src", url);
		scriptObj.setAttribute("id", script_id);
		var headLoc = document.getElementsByTagName("head").item(0);

		if (typeof baggage != 'undefined') this.baggage[script_id] = baggage;

		headLoc.appendChild(scriptObj);
		
		return script_id;
	},
	
	get_baggage: function (script_id)
	{
		if (script_id in this.baggage) return this.baggage[script_id];
		return null;
	},
	
	cleanup: function (script_id)
	{
		if (script_id in this.baggage) delete this.baggage[script_id];
		var headLoc = document.getElementsByTagName("head").item(0);
		var scriptObj = document.getElementById(script_id);
		if (scriptObj && headLoc) headLoc.removeChild(scriptObj);
	}
}

function mfw_dir_add_marker(map, lat, lng, path)
{
	var icon = new GIcon();
	icon.image = path;
	var parts = path.split('/');
	parts.pop();
	path = parts.join('/');
	icon.shadow = 'http://chart.apis.google.com/chart?chst=d_map_pin_shadow';
	icon.iconSize = new GSize(24.0, 38.0);
	icon.shadowSize = new GSize(44.0, 38.0);
	icon.iconAnchor = new GPoint(12.0, 38.0);
	icon.infoWindowAnchor = new GPoint(12.0, 19.0);
	
	var point = new GLatLng(lat, lng);
	var marker = new GMarker(point, icon);
	map.addOverlay(marker);
	return marker;
}
