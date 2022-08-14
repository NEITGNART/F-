<?php

namespace App\Http\Controllers\Api\V1\Request;

use App\Events\Event;
use App\AccountApproved;
use App\AccountActivated;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Database;
use App\Http\Requests\User\EtaRequest;
use App\Http\Controllers\ApiController;
use App\Transformers\User\EtaTransformer;
use App\Transformers\User\UserTransformer;
use App\Jobs\Notifications\OtpNotification;
use App\Jobs\Notifications\PushNotification;
use App\Jobs\Notifications\AndroidPushNotification;
use Illuminate\Http\Request;
use App\Jobs\Notifications\FcmPushNotification;
use App\Transformers\Requests\TripRequestTransformer;
use App\Base\Constants\Masters\PushEnums;
use App\Models\Request\Request as RequestModel;
use App\Transformers\Requests\PackagesTransformer;
use App\Models\Master\PackageType;
use App\Base\Constants\Auth\Role;

/**
 * @group User-trips-apis
 *
 * APIs for User-trips apis
 */
class EtaController extends ApiController
{
    protected $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
    * Calculate an Eta
    * @bodyParam pick_lat double required pikup lat of the user
    * @bodyParam pick_lng double required pikup lng of the user
    * @bodyParam drop_lat double required drop lat of the user
    * @bodyParam drop_lng double required drop lng of the user
    * @bodyParam vehicle_type string required id of zone_type_id
    * @bodyParam ride_type tinyInteger required type of ride whther ride now or scheduele trip
    * @bodyParam promo_code string optional promo code that the user provided
    * @responseFile responses/user/trips/eta.json
    */
    public function eta(EtaRequest $request)
    {
        $zone_detail = find_zone($request->input('pick_lat'), $request->input('pick_lng'));

        if (!$zone_detail) {
            $this->throwCustomException('service not available with this location');
        }


        if ($request->has('vehicle_type')) {
            $type = $zone_detail->zoneType()->where('id', $request->input('vehicle_type'))->first();
        } else {
            $type = $zone_detail->zoneType;
        }

        if(access()->hasRole(Role::DRIVER)){

            $type_id = auth()->user()->driver->vehicle_type;
            
            $type = $zone_detail->zoneType()->where('type_id', $type_id)->first();

            if(!$type){
                $this->throwCustomException('Your Vehicle Type is not associated with this zone');
            }
        }

        $result = fractal($type, new EtaTransformer);

        $user= auth()->user();

        return $this->respondSuccess($result);
    }

    /**
    * Change Drop Location on trip
    * @bodyParam request_id uuid required id request
    * @bodyParam drop_lat double required drop lat of the user
    * @bodyParam drop_lng double required drop lng of the user
    * @bodyParam drop_address string required drop address of the trip request
    * @response {
    "success": true,
    "message": "drop_changed_successfully"}
    * 
    */
    public function changeDropLocation(Request $request){

        $request->validate([
        'request_id' => 'required|exists:requests,id',
        'drop_lat'=>'required',
        'drop_lng'=>'required',
        'drop_address'=>'required'
        ]);

        // Get Request Detail
        $request_detail = RequestModel::where('id', $request->input('request_id'))->first();

        $request_place_params = ['drop_lat'=>$request->drop_lat,'drop_lng'=>$request->drop_lng,'drop_address'=>$request->drop_address];

        // Update Droped place details
        $request_detail->requestPlace->update($request_place_params);

        $request_detail->fresh();

        $request_result =  fractal($request_detail, new TripRequestTransformer)->parseIncludes('userDetail');

        $title = trans('push_notifications.new_request_title');

        $push_data = ['notification_enum'=>PushEnums::DROP_CHANGED,'result'=>(string)$pus_request_detail];

        $notifable_driver = $request_detail->driverDetail->user;

        $device_token = $notifable_driver->fcm_token;
        // Send FCM Notification
        dispatch(new FcmPushNotification($title,$push_data,$device_token));

        $socket_data = new \stdClass();
        $socket_data->success = true;
        $socket_data->success_message  = PushEnums::DROP_CHANGED;
        $socket_data->result = $request_result;

        dispatch(new NotifyViaMqtt('ontrip_'.$request_detail->driverDetail->id, json_encode($socket_data), $request_detail->driverDetail->id));

        return $this->respondSuccess(null,'drop_changed_successfully');


    }

    /**
    * List Packages
    * @bodyParam pick_lat double required pikup lat of the user
    * @bodyParam pick_lng double required pikup lng of the user
    *
    */
    public function listPackages(Request $request){

        $request->validate([
            'pick_lat'  => 'required',
            'pick_lng'  => 'required',
        ]);

        
        $type = PackageType::active()->get();

        $result = fractal($type, new PackagesTransformer);

        return $this->respondSuccess($result);         

    }
}
