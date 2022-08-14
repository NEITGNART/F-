<?php

namespace App\Http\Controllers\Api\V1\Request;

use Carbon\Carbon;
use Ramsey\Uuid\Uuid;
use App\Jobs\NotifyViaMqtt;
use App\Models\Admin\Driver;
use App\Jobs\NotifyViaSocket;
use App\Models\Admin\ZoneType;
use App\Models\Request\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Request\RequestMeta;
use Illuminate\Support\Facades\Log;
use App\Base\Constants\Masters\PushEnums;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Request\CreateTripRequest;
use App\Jobs\Notifications\AndroidPushNotification;
use App\Transformers\Requests\TripRequestTransformer;
use App\Jobs\Notifications\FcmPushNotification;

/**
 * @group User-trips-apis
 *
 * APIs for User-trips apis
 */
class CreateRequestController extends BaseController
{
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    /**
    * Create Request
    * @bodyParam pick_lat double required pikup lat of the user
    * @bodyParam pick_lng double required pikup lng of the user
    * @bodyParam drop_lat double required drop lat of the user
    * @bodyParam drop_lng double required drop lng of the user
    * @bodyParam drivers json required drivers json can be fetch from firebase db
    * @bodyParam vehicle_type string required id of zone_type_id
    * @bodyParam ride_type tinyInteger required type of ride whther ride now or scheduele trip
    * @bodyParam payment_opt tinyInteger required type of ride whther cash or card, wallet('0 => card,1 => cash,2 => wallet)
    * @bodyParam pick_address string required pickup address of the trip request
    * @bodyParam drop_address string required drop address of the trip request
    * @bodyParam is_later tinyInteger sometimes it represent the schedule rides param must be 1.
    * @bodyParam trip_start_time timestamp sometimes it represent the schedule rides param must be datetime format:Y-m-d H:i:s.
    * @bodyParam promocode_id uuid optional id of promo table
    * @bodyParam rental_pack_id integer optional id of package type
    * @responseFile responses/requests/create-request.json
    *
    */
    public function createRequest(CreateTripRequest $request)
    {
        /**
        * Check if the user has registred a trip already
        * Validate payment option is available.
        * if card payment choosen, then we need to check if the user has added thier card.
        * if the paymenr opt is wallet, need to check the if the wallet has enough money to make the trip request
        * Check if thge user created a trip and waiting for a driver to accept. if it is we need to cancel the exists trip and create new one
        * Find the zone using the pickup coordinates & get the nearest drivers
        * create request along with place details
        * assing driver to the trip depends the assignment method
        * send emails and sms & push notifications to the user& drivers as well.
        */
        // Check whether the trip is schedule ride or not
        if ($request->has('is_later') && $request->is_later) {
            return $this->createRideLater($request);
        }
        // Check if the user has registred a trip already
        $user_exists_trip = $this->request->where('is_completed', 0)->where('is_cancelled', 0)->where('user_id', auth()->user()->id)->where('is_later', 0)->exists();
        if ($user_exists_trip) {
            $this->throwCustomException('user_already_in_trip');
        }
        // Validate payment option is available.
        // @TODO
        //Check if thge user created a trip and waiting for a driver to accept. if it is we need to cancel the exists trip and create new one
        $request_meta_with_current_user = RequestMeta::where('user_id', auth()->user()->id);
        $check_request_data_with_user = $request_meta_with_current_user->exists();
        if ($check_request_data_with_user) {
            // get request detail
            $request_with_user = $request_meta_with_current_user->pluck('request_id')->first();
            if ($request_with_user) {
                $this->request->where('id', $request_with_user)->update(['is_cancelled'=>1,'cancel_method'=>1]);
            }
            // Delete all meta details
            $request_meta_with_current_user->delete();
        }
        // get type id
        $zone_type_detail = ZoneType::where('id', $request->vehicle_type)->first();
        $type_id = $zone_type_detail->type_id;

        // Get currency code of Request
        $service_location = $zone_type_detail->zone->serviceLocation;
        $currency_code = $service_location->currency_code;
        //Find the zone using the pickup coordinates & get the nearest drivers

        if ($request->drivers) {
            $nearest_drivers =  $this->getDrivers($request, $type_id);
        } else {
            $nearest_drivers =  $this->getFirebaseDrivers($request, $type_id);
        }

        // fetch unit from zone
        $unit = $zone_type_detail->zone->unit;
        // Fetch user detail
        $user_detail = auth()->user();

        $user_detail->timezone = $service_location->timezone;
        $user_detail->save();

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


        $request_params = [
            'request_number'=>$request_number,
            'user_id'=>$user_detail->id,
            'zone_type_id'=>$request->vehicle_type,
            'payment_opt'=>$request->payment_opt,
            'unit'=>$unit,
            'promo_id'=>$request->promocode_id,
            'requested_currency_code'=>$currency_code,
            'service_location_id'=>$service_location->id,
            'ride_otp'=>rand(1111, 9999)
        ];

        if($request->has('rental_pack_id') && $request->rental_pack_id){

            $request_params['is_rental'] = true;
            
            $request_params['rental_package_id'] = $request->rental_pack_id;
        }

        if($request->has('myself') && $request->input('myself')==0){

            $request_params['book_for_other'] = 1;

            if(!$request->has('contact_no_other') || $request->input('contact_no_other')==null){
                
                $this->throwCustomException('please provide the valid contact');

            }
            $request_params['book_for_other_contact'] = $request->input('contact_no_other');

        }

        $request_params['company_key'] = auth()->user()->company_key;
        // store request details to db
        // DB::beginTransaction();
        // try {
        $request_detail = $this->request->create($request_params);
        // request place detail params
        $request_place_params = [
            'pick_lat'=>$request->pick_lat,
            'pick_lng'=>$request->pick_lng,
            'drop_lat'=>$request->drop_lat,
            'drop_lng'=>$request->drop_lng,
            'pick_address'=>$request->pick_address,
            'drop_address'=>$request->drop_address];
        // store request place details
        $request_detail->requestPlace()->create($request_place_params);
        $selected_drivers = [];
        $i = 0;
        foreach ($nearest_drivers as $driver) {
            // $selected_drivers[$i]["request_id"] = $request_detail->id;
            $selected_drivers[$i]["user_id"] = $user_detail->id;
            $selected_drivers[$i]["driver_id"] = $driver->id;
            $selected_drivers[$i]["active"] = $i == 0 ? 1 : 0;
            $selected_drivers[$i]["assign_method"] = 1;
            $selected_drivers[$i]["created_at"] = date('Y-m-d H:i:s');
            $selected_drivers[$i]["updated_at"] = date('Y-m-d H:i:s');
            $i++;
        }

        // Send notification to the very first driver
        $first_meta_driver = $selected_drivers[0]['driver_id'];
        $request_result =  fractal($request_detail, new TripRequestTransformer)->parseIncludes('userDetail');
        $pus_request_detail = $request_result->toJson();
        $push_data = ['notification_enum'=>PushEnums::REQUEST_CREATED,'result'=>$pus_request_detail];
        $title = trans('push_notifications.new_request_title');
        $body = trans('push_notifications.new_request_body');

        $socket_data = new \stdClass();
        $socket_data->success = true;
        $socket_data->success_message  = PushEnums::REQUEST_CREATED;
        $socket_data->result = $request_result;

        $driver = Driver::find($first_meta_driver);

        $notifable_driver = $driver->user;
        $notifable_driver->notify(new AndroidPushNotification($title, $body, $push_data));

        $device_token = $notifable_driver->fcm_token;
        // Send FCM Notification
        dispatch(new FcmPushNotification($title,json_encode((object)$push_data),$device_token));


        // Form a socket sturcture using users'id and message with event name
        // $socket_message = structure_for_socket($driver->id, 'driver', $socket_data, 'create_request');

        // dispatch(new NotifyViaSocket('transfer_msg', $socket_message));

        dispatch(new NotifyViaMqtt('create_request_'.$driver->id, json_encode($socket_data), $driver->id));

        foreach ($selected_drivers as $key => $selected_driver) {
            $request_detail->requestMeta()->create($selected_driver);
        }
        // @TODO send sms & email to the user
        // } catch (\Exception $e) {
        //     DB::rollBack();
        //     Log::error($e);
        //     Log::error('Error while Create new request. Input params : ' . json_encode($request->all()));
        //     return $this->respondBadRequest('Unknown error occurred. Please try again later or contact us if it continues.');
        // }
        // DB::commit();

        return $this->respondSuccess($request_result, 'created_request_successfully');
    }


    /**
    * Get nearest Drivers using requested co-ordinates
    *  @param request
    */
    public function getDrivers($request, $type_id)
    {
        $driver_detail = [];
        $driver_ids = [];

        if (!$request->drivers) {
            $this->throwCustomException('no drivers available');
        }
        foreach (json_decode($request->drivers) as $key => $driver) {
            $driver_data = new \stdClass();
            $driver_data->id = (integer)$driver->driver_id;
            $driver_data->lat = (double)$driver->driver_lat;
            $driver_data->lng = (double)$driver->driver_lng;
            $driver_ids[]= $driver_data->id;
            $driver_detail []= $driver_data;
        }

        $pick_lat = $request->pick_lat;
        $pick_lng = $request->pick_lng;
        $driver_search_radius = get_settings('driver_search_radius')?:30;

        $haversine = "(6371 * acos(cos(radians($pick_lat)) * cos(radians(pick_lat)) * cos(radians(pick_lng) - radians($pick_lng)) + sin(radians($pick_lat)) * sin(radians(pick_lat))))";

        // Get Drivers who are all going to accept or reject the some request that nears the user's current location.

        // whereHas('request.requestPlace', function ($query) use ($haversine,$driver_search_radius) {
        //     $query->select('request_places.*')->selectRaw("{$haversine} AS distance")
        //         ->whereRaw("{$haversine} < ?", [$driver_search_radius]);
        // })->
        $meta_drivers = RequestMeta::whereIn('driver_id', $driver_ids)->pluck('driver_id')->toArray();

        // $driver_haversine = "(6371 * acos(cos(radians($pick_lat)) * cos(radians(latitude)) * cos(radians(longitude) - radians($pick_lng)) + sin(radians($pick_lat)) * sin(radians(latitude))))";
        // get nearest driver exclude who are all struck with request meta
        // ->whereHas('driverDetail', function ($query) use ($driver_haversine,$driver_search_radius,$type_id) {
        //     $query->select('driver_details.*')->selectRaw("{$driver_haversine} AS distance")
        //         ->whereRaw("{$driver_haversine} < ?", [$driver_search_radius]);
        // })
        $drivers = Driver::with(['user'])->where('active', 1)->where('approve', 1)->where('available', 1)->where('vehicle_type', $type_id)->whereIn('id', $driver_ids)->whereNotIn('id', $meta_drivers)->limit(10)->get();

        if ($drivers->isEmpty()) {
            $this->throwCustomException('all drivers are busy');
        }
        return $drivers;
    }
    /**
    * Get Drivers from firebase
    */
    public function getFirebaseDrivers($request, $type_id)
    {
        $pick_lat = $request->pick_lat;
        $pick_lng = $request->pick_lng;

        // NEW flow
        $client = new \GuzzleHttp\Client();
        $url = env('NODE_APP_URL').':'.env('NODE_APP_PORT').'/'.$pick_lat.'/'.$pick_lng.'/'.$type_id;

        $res = $client->request('GET', "$url");
        if ($res->getStatusCode() == 200) {
            $fire_drivers = \GuzzleHttp\json_decode($res->getBody()->getContents());
            if (empty($fire_drivers->data)) {
                $this->throwCustomException('no drivers available');
            } else {
                $nearest_driver_ids = [];
                foreach ($fire_drivers->data as $key => $fire_driver) {
                    $nearest_driver_ids[] = $fire_driver->id;
                }

                $driver_search_radius = get_settings('driver_search_radius')?:30;

                $haversine = "(6371 * acos(cos(radians($pick_lat)) * cos(radians(pick_lat)) * cos(radians(pick_lng) - radians($pick_lng)) + sin(radians($pick_lat)) * sin(radians(pick_lat))))";
                // Get Drivers who are all going to accept or reject the some request that nears the user's current location.
                $meta_drivers = RequestMeta::whereHas('request.requestPlace', function ($query) use ($haversine,$driver_search_radius) {
                    $query->select('request_places.*')->selectRaw("{$haversine} AS distance")
                ->whereRaw("{$haversine} < ?", [$driver_search_radius]);
                })->pluck('driver_id')->toArray();

                $nearest_drivers = Driver::where('active', 1)->where('approve', 1)->where('available', 1)->where('vehicle_type', $type_id)->whereIn('id', $nearest_driver_ids)->whereNotIn('id', $meta_drivers)->limit(10)->get();

                if ($nearest_drivers->isEmpty()) {
                    $this->throwCustomException('all drivers are busy');
                }

                return $nearest_drivers;
            }
        } else {
            $this->throwCustomException('there is an error-getting-drivers');
        }
    }

    /**
    * Create Ride later trip
    */
    public function createRideLater(CreateTripRequest $request)
    {
        /**
        * @TODO validate if the user has any trip with same time period
        *
        */
        // get type id
        $zone_type_detail = ZoneType::where('id', $request->vehicle_type)->first();
        $type_id = $zone_type_detail->type_id;

        // Get currency code of Request
        $service_location = $zone_type_detail->zone->serviceLocation;
        $currency_code = $service_location->currency_code;

        // fetch unit from zone
        $unit = $zone_type_detail->zone->unit;
        // Fetch user detail
        $user_detail = auth()->user();
        // Get last request's request_number
        $request_number = $this->request->orderBy('updated_at', 'DESC')->pluck('request_number')->first();
        if ($request_number) {
            $request_number = explode('_', $request_number);
            $request_number = $request_number[1]?:000000;
        } else {
            $request_number = 000000;
        }
        // Generate request number
        $request_number = 'REQ_'.sprintf("%06d", $request_number+1);

        // Convert trip start time as utc format
        $timezone = $service_location->timezone?:env('SYSTEM_DEFAULT_TIMEZONE');

        // Update timezone for user
        $user_detail->timezone = $service_location->timezone;

        $user_detail->save();

        $trip_start_time = Carbon::parse($request->trip_start_time, $timezone)->setTimezone('UTC')->toDateTimeString();

        $request_params = [
            'request_number'=>$request_number,
            'user_id'=>$user_detail->id,
            'is_later'=>true,
            'trip_start_time'=>$trip_start_time,
            'zone_type_id'=>$request->vehicle_type,
            'payment_opt'=>$request->payment_opt,
            'unit'=>$unit,
            'requested_currency_code'=>$currency_code,
            'service_location_id'=>$service_location->id];
        // store request details to db
        DB::beginTransaction();
        try {
            $request_detail = $this->request->create($request_params);
            // request place detail params
            $request_place_params = [
            'pick_lat'=>$request->pick_lat,
            'pick_lng'=>$request->pick_lng,
            'drop_lat'=>$request->drop_lat,
            'drop_lng'=>$request->drop_lng,
            'pick_address'=>$request->pick_address,
            'drop_address'=>$request->drop_address];
            // store request place details
            $request_detail->requestPlace()->create($request_place_params);

            $request_result =  fractal($request_detail, new TripRequestTransformer)->parseIncludes('userDetail');
            // @TODO send sms & email to the user
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            Log::error('Error while Create new schedule request. Input params : ' . json_encode($request->all()));
            return $this->respondBadRequest('Unknown error occurred. Please try again later or contact us if it continues.');
        }
        DB::commit();

        return $this->respondSuccess($request_result,'created_request_successfully');
    }
}
