<?php

namespace App\Http\Controllers\Api\V1\Request;

use Carbon\Carbon;
use Ramsey\Uuid\Uuid;
use App\Models\Admin\Driver;
use App\Models\Admin\ZoneType;
use App\Models\Request\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Request\RequestMeta;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Request\CreateTripRequest;
use App\Transformers\Requests\TripRequestTransformer;

/**
 * @group Driver-trips-apis
 *
 * APIs for Driver-trips apis
 */
class InstantRideController extends BaseController
{
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    /**
    * Create Instant Ride
    * @bodyParam pick_lat double required pikup lat of the user
    * @bodyParam pick_lng double required pikup lng of the user
    * @bodyParam drop_lat double required drop lat of the user
    * @bodyParam drop_lng double required drop lng of the user
    * @bodyParam pick_address string required pickup address of the trip request
    * @bodyParam drop_address string required drop address of the trip request
    * @bodyParam pickup_poc_name string required customer name for the request
    * @bodyParam pickup_poc_mobile string required customer name for the request
    * @responseFile responses/requests/instant-ride.json
    *
    */
    public function createRequest(CreateTripRequest $request)
    {
        
        $zone_detail = find_zone($request->input('pick_lat'), $request->input('pick_lng'));
        $unit = $zone_detail->unit;
        
        // Get last request's request_number
        $request_number = $this->request->orderBy('created_at', 'DESC')->pluck('request_number')->first();
        if ($request_number) {
            $request_number = explode('_', $request_number);
            $request_number = $request_number[1]?:000000;
        } else {
            $request_number = 000000;
        }
        // Generate request number
        $request_number = 'REQ_'.sprintf("%06d", $request_number+1);

        $service_location = $zone_detail->serviceLocation;

        $currency_code = $service_location->currency_code;

        $type_id = auth()->user()->driver->vehicle_type;

        $zone_type_id = $zone_detail->zoneType()->where('type_id',$type_id)->pluck('id')->first();

        if(!$zone_type_id){
            $this->throwCustomException('Your Vehicle Type is not associated with this zone');
        }


        $request_params = [
            'request_number'=>$request_number,
            'driver_id'=>auth()->user()->driver->id,
            'zone_type_id'=>$zone_type_id,
            'payment_opt'=>'1',
            'unit'=>$unit,
            'requested_currency_code'=>$currency_code,
            'service_location_id'=>$service_location->id,
            'accepted_at'=>date('Y-m-d H:i:s'),
            'is_driver_started'=>true,
            'is_driver_arrived'=>true,
            'arrived_at'=>date('Y-m-d H:i:s'),
            'is_trip_start'=>true,
            'trip_start_time'=>date('Y-m-d H:i:s'),
            'instant_ride'=>true
        ];
        
        $request_params['company_key'] = auth()->user()->company_key;

        $request_detail = $this->request->create($request_params);

        $ad_hoc_user_params['name'] = $request->pickup_poc_name;
        $ad_hoc_user_params['mobile'] = $request->pickup_poc_mobile;

        // Store ad hoc user detail of this request
        $request_detail->adHocuserDetail()->create($ad_hoc_user_params);

        // request place detail params
        $request_place_params = [
            'pick_lat'=>$request->pick_lat,
            'pick_lng'=>$request->pick_lng,
            'drop_lat'=>$request->x,
            'drop_lng'=>$request->drop_lng,
            'pick_address'=>$request->pick_address,
            'drop_address'=>$request->drop_address];

        // store request place details
        $request_detail->requestPlace()->create($request_place_params);

        $request_result =  fractal($request_detail, new TripRequestTransformer)->parseIncludes('userDetail');

        return $this->respondSuccess($request_result, 'created_instant_ride_successfully');
    }


}
