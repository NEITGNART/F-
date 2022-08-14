@section('map-view')
<div class="row">
    <div class="col-12">
        <div id="map"></div>

        <div id="legend"><h3>@lang('view_pages.legend')</h3></div>
    </div>
</div>
@endsection
@section('map-script')
<script type="text/javascript" src="https://maps.google.com/maps/api/js?key={{env('GOOGLE_MAP_KEY')}}&libraries=visualization"></script>

<!-- The core Firebase JS SDK is always required and must be listed first -->
<script src="https://www.gstatic.com/firebasejs/7.19.0/firebase-app.js"></script>
<script src="https://www.gstatic.com/firebasejs/7.19.0/firebase-database.js"></script>
<!-- TODO: Add SDKs for Firebase products that you want to use https://firebase.google.com/docs/web/setup#available-libraries -->
<script src="https://www.gstatic.com/firebasejs/7.19.0/firebase-analytics.js"></script>

<script type="text/javascript">
    var heatmapData = [];
    var pickLat = [];
    var pickLng = [];
     var default_lat = '{{$default_lat ?? env("DEFAULT_LAT")}}';
    var default_lng = '{{$default_lng ?? env("DEFAULT_LNG")}}';
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

    var map = new google.maps.Map(document.getElementById('map'), {
            center: new google.maps.LatLng(default_lat, default_lng),
            zoom: 5,
            mapTypeId: 'roadmap'
        });

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
            var div = document.createElement('div');
            div.innerHTML = '<img src="' + icon + '"> ' + name;
            legend.appendChild(div);
        }

        map.controls[google.maps.ControlPosition.RIGHT_BOTTOM].push(legend);

    function loadDriverIcons(data){
        // var result = Object.entries(data);
        Object.entries(data).forEach(([key, val]) => {
            // var infowindow = new google.maps.InfoWindow({
            //     content: contentString
            // });

            var iconImg = '';
            if(val.is_available == true){
                iconImg = icons['available'].icon;
            }else{
                iconImg = icons['ontrip'].icon;
            }

            var marker = new google.maps.Marker({
                position: new google.maps.LatLng(val.l[0],val.l[1]),
                icon: iconImg,
                map: map
            });

            // marker.addListener('click', function() {
            //     infowindow.open(map, marker);
            // });
        });
    }
</script>
@endsection
