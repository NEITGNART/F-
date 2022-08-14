@extends('admin.layouts.app')

@section('title', 'Map View')

@section('content')
<style>
    #map {
        height: 70vh;
        margin: 15px;
    }
   .map-overlay {
position: absolute;
bottom: 187px;
right: 30px;
/*background: rgba(255, 255, 255, 0.8);
margin-right: 20px;
font-family: Arial, sans-serif;
overflow: auto;
border-radius: 3px;*/
}
    #legend {
        font-family: Arial, sans-serif;
        background: #fff;
        padding: 10px;
        margin: 10px;
        border: 3px solid #000;
       /* padding: 10px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        line-height: 18px;
        height: 150px;
        margin-bottom: 40px;
        width: 100px;*/
    }
    #legend h3 {
        margin-top: 0;
    }
    #legend img {
        vertical-align: middle;
    }

    .legend-key {
display: inline-block;
border-radius: 20%;
width: 10px;
height: 10px;
margin-right: 5px;
}
</style>
<!-- Start Page content -->
<section class="content">
    <div class="row">
        <div class="col-12">
            <div class="box">

                <div class="box-header with-border">
                    <h3>{{ $page }}</h3>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div id="map"></div>

                        <div class="map-overlay" id="legend"><h3>@lang('view_pages.legend')</h3></div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</section>


{{-- <script type="text/javascript" src="https://maps.google.com/maps/api/js?key={{get_settings('google_map_key')}}&libraries=visualization"></script> --}}





<!-- The core Firebase JS SDK is always required and must be listed first -->
<script src="https://www.gstatic.com/firebasejs/7.19.0/firebase-app.js"></script>
<script src="https://www.gstatic.com/firebasejs/7.19.0/firebase-database.js"></script>
<!-- TODO: Add SDKs for Firebase products that you want to use https://firebase.google.com/docs/web/setup#available-libraries -->
<script src="https://www.gstatic.com/firebasejs/7.19.0/firebase-analytics.js"></script>

<script type="text/javascript">
    var heatmapData = [];
    var pickLat = [];
    var pickLng = [];
     var default_lat = '{{$default_lat}}';
    var default_lng = '{{$default_lng}}';

    // var default_lat = 11.015956;
    // var default_lng = 76.968985;
     var company_key='{{auth()->user()->company_key}}';
    var driverLat,driverLng,bearing,type;

    // Your web app's Firebase configuration
    var firebaseConfig = {
    apiKey: "AIzaSyBVE-WE-lwXhxWFHJthZ6FleF1WQ3NmGAU",
    authDomain: "cabeezie.firebaseapp.com",
    databaseURL: "https://cabeezie.firebaseio.com",
    projectId: "cabeezie",
    storageBucket: "cabeezie.appspot.com",
    messagingSenderId: "656697310655",
    appId: "1:656697310655:web:b2b93485dff3591cb9f50a",
    measurementId: "G-TJZ64ECJB0"
  };

    // Initialize Firebase
    firebase.initializeApp(firebaseConfig);
    firebase.analytics();

    var tripRef = firebase.database().ref('drivers');

    tripRef.on('value', async function(snapshot) {
        var data = snapshot.val();

        await loadDriverIcons(data);
    });

  
    mapboxgl.accessToken = '{{get_settings('map_box_key')}}';
var map = new mapboxgl.Map({
container: 'map', // container id  
style: 'mapbox://styles/mapbox/streets-v11',
center: new mapboxgl.LngLat(default_lng, default_lat), // starting position
zoom: 5 // starting zoom
});


    // Add zoom and rotation controls to the map.
map.addControl(new mapboxgl.NavigationControl());

 

        var iconBase = '{{ asset("map/icon/") }}';
        var icons = {
          available: {
            name: 'Available',
            icon: iconBase + '/driver_available.png'
          },
          ontrip: {
            name: 'OnTrip',
            icon: iconBase + '/driver_on_trip.png'
          }
        };

        var legend = document.getElementById('legend');

        for (var key in icons) {
            var type = icons[key];
            var name = type.name;
            var icon = type.icon;

            var item = document.createElement('div');
            var keys = document.createElement('span');
            keys.className = 'legend-key';
           
             
            var value = document.createElement('span');
            value.innerHTML = '<img src="' + icon + '"> ' + name;
            item.appendChild(keys);
            item.appendChild(value);
            legend.appendChild(item);

        }

     
       

    function loadDriverIcons(data){

         Object.entries(data).forEach(([key, val]) => {
            if (val.l === undefined) {
            }else{
            // var infowindow = new google.maps.InfoWindow({
            //     content: contentString
            // });
             var latitude = val.l[0];
                var longitude = val.l[1];

            var iconImg = '';
            if(val.company_key==company_key){
               if(val.is_available == true){
                iconImg = icons['available'].icon;
            }else{
                iconImg = icons['ontrip'].icon;
            }

             var el = document.createElement('div');
                el.className = 'marker';
                el.style.backgroundImage = 'url(' + iconImg + ')';

                el.style.width = '25px';
                el.style.height = '24px';



            var marker = new mapboxgl.Marker(el)
            .setLngLat([longitude,latitude])
             .addTo(map);

            }

           
        }
        });
    }


</script>

@endsection

