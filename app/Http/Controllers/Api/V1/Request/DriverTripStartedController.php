<?php

namespace App\Http\Controllers\Api\V1\Request;

use App\Models\User;
use App\Jobs\NotifyViaMqtt;
use Illuminate\Http\Request;
use App\Jobs\NotifyViaSocket;
use App\Base\Constants\Masters\PushEnums;
use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Request\Request as RequestModel;
use App\Jobs\Notifications\AndroidPushNotification;
use App\Transformers\Requests\TripRequestTransformer;

/**
 * @group Driver-trips-apis
 *
 * APIs for Driver-trips apis
 */
class DriverTripStartedController extends BaseController
{
    protected $request;

    public function __construct(RequestModel $request)
    {
        $this->request = $request;
    }

    /**
    * Driver Trip started
    * @bodyParam request_id uuid required id of request
    * @bodyParam pick_lat double required pikup lat of the user
    * @bodyParam pick_lng double required pikup lng of the user
    * @bodyParam pick_address string optional pickup address of the trip request
    * @response {
    "success": true,
    "message": "driver_trip_started"}
    */
    public function tripStart(Request $request)
    {
        $request->validate([
        'request_id' => 'required|exists:requests,id',
        'pick_lat'  => 'required',
        'pick_lng'  => 'required',
        'ride_otp'=>'sometimes|required'
        ]);
        // Get Request Detail
        $request_detail = $this->request->where('id', $request->input('request_id'))->first();

        if($request->has('ride_otp')){

        if($request_detail->ride_otp != $request->ride_otp){

          $this->throwCustomException('provided otp is invalid');
        }

        }


        // Validate Trip request data
        $this->validateRequest($request_detail);
        // Update the Request detail with arrival state
        $request_detail->update(['is_trip_start'=>true,'trip_start_time'=>date('Y-m-d H:i:s')]);
        // Update pickup detail to the request place table
        $request_place = $request_detail->requestPlace;
        $request_place->pick_lat = $request->input('pick_lat');
        $request_place->pick_lng = $request->input('pick_lng');
        $request_place->save();
        if ($request_detail->if_dispatch) {
            goto dispatch_notify;
        }
        // Send Push notification to the user
        $user = User::find($request_detail->user_id);
        $title = trans('push_notifications.trip_started_title');
        $body = trans('push_notifications.trip_started_body');

        $request_result =  fractal($request_detail, new TripRequestTransformer)->parseIncludes('driverDetail');

        $pus_request_detail = $request_result->toJson();
        $push_data = ['notification_enum'=>PushEnums::DRIVER_STARTED_THE_TRIP,'result'=>(string)$pus_request_detail];

        $socket_data = new \stdClass();
        $socket_data->success = true;
        $socket_data->success_message  = PushEnums::DRIVER_STARTED_THE_TRIP;
        $socket_data->result = $request_result;
        // Form a socket sturcture using users'id and message with event name
        $socket_message = structure_for_socket($user->id, 'user', $socket_data, 'trip_status');
        dispatch(new NotifyViaSocket('transfer_msg', $socket_message));
        dispatch(new NotifyViaMqtt('trip_status_'.$user->id, json_encode($socket_data), $user->id));
        $user->notify(new AndroidPushNotification($title, $body));
        dispatch_notify:
        return $this->respondSuccess(null, 'driver_trip_started');
    }

    /**
    * Validate Request
    */
    public function validateRequest($request_detail)
    {
        if ($request_detail->driver_id!=auth()->user()->driver->id) {
            $this->throwAuthorizationException();
        }

        if ($request_detail->is_trip_start) {
            $this->throwCustomException('trip started already');
        }

        if ($request_detail->is_completed) {
            $this->throwCustomException('request completed already');
        }
        if ($request_detail->is_cancelled) {
            $this->throwCustomException('request cancelled');
        }
    }
}
