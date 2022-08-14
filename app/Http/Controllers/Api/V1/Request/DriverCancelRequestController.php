<?php

namespace App\Http\Controllers\Api\V1\Request;

use App\Jobs\NotifyViaMqtt;
use App\Jobs\NotifyViaSocket;
use App\Base\Constants\Masters\UserType;
use App\Base\Constants\Masters\PushEnums;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Request\CancelTripRequest;
use App\Jobs\Notifications\AndroidPushNotification;
use App\Transformers\Requests\TripRequestTransformer;
use App\Models\Admin\CancellationReason;
use App\Base\Constants\Masters\zoneRideType;
use App\Base\Constants\Masters\WalletRemarks;


/**
 * @group Driver-trips-apis
 *
 * APIs for Driver-trips apis
 */
class DriverCancelRequestController extends BaseController
{

    /**
    * Driver Cancel Trip Request
    * @bodyParam request_id uuid required id of request
    * @bodyParam reason string optional reason provided by user
    * @bodyParam custom_reason string optional custom reason provided by user
    *@response {
    "success": true,
    "message": "driver_cancelled_trip"}
    */
    public function cancelRequest(CancelTripRequest $request)
    {
        /**
        * Validate the request which is authorised by current authenticated user
        * Cancel the request by updating is_cancelled true with reason if there is any reason
        * Notify the user that is cancelled the trip request by driver
        */
        // Validate the request which is authorised by current authenticated user
        $driver = auth()->user()->driver;
        // Update the availble status
        $driver->available = true;
        $driver->save();

        $request_detail = $driver->requestDetail()->where('id', $request->request_id)->first();
        // Throw an exception if the user is not authorised for this request
        if (!$request_detail) {
            $this->throwAuthorizationException();
        }
        $request_detail->update([
            'is_cancelled'=>true,
            'reason'=>$request->reason,
            'custom_reason'=>$request->custom_reason,
            'cancel_method'=>UserType::DRIVER,
        ]);

        /**
        * Apply Cancellation Fee
        */
        $charge_applicable = false;
        if ($request->custom_reason) {
            $charge_applicable = true;
        }
        if ($request->reason) {
            $reason = CancellationReason::find($request->reason);

            if ($reason->payment_type=='free') {
                $charge_applicable=false;
            } else {
                $charge_applicable=true;
            }
        }

          /**
         * get prices from zone type
         */
        if ($request_detail->is_later) {
            $ride_type = zoneRideType::RIDELATER;

        } else {
            $ride_type = zoneRideType::RIDENOW;

        }

        if ($charge_applicable) {
            
            $zone_type_price = $request_detail->zoneType->zoneTypePrice()->where('price_type', $ride_type)->first();

            $cancellation_fee = $zone_type_price->cancellation_fee;

            $requested_driver = $request_detail->driverDetail;
            $driver_wallet = $requested_driver->driverWallet;
            $driver_wallet->amount_spent += $cancellation_fee;
            $driver_wallet->amount_balance -= $cancellation_fee;
            $driver_wallet->save();

            // Add the history
            $requested_driver->driverWalletHistory()->create([
            'amount'=>$cancellation_fee,
            'transaction_id'=>$request_detail->id,
            'remarks'=>WalletRemarks::CANCELLATION_FEE,
            'request_id'=>$request_detail->id,
            'is_credit'=>false]);

            $request_detail->requestCancellationFee()->create(['driver_id'=>$request_detail->driver_id,'is_paid'=>true,'cancellation_fee'=>$cancellation_fee,'paid_request_id'=>$request_detail->id]);
        }

        // Get the user detail
        $user = $request_detail->userDetail;

        // Notify the user that the driver is cancelled the trip request
        if ($user) {
            $request_result =  fractal($request_detail, new TripRequestTransformer)->parseIncludes('driverDetail');

            $push_request_detail = $request_result->toJson();
            $title = trans('push_notifications.trip_cancelled_by_driver_title');
            $body = trans('push_notifications.trip_cancelled_by_driver_body');

            $push_data = ['success'=>true,'success_message'=>PushEnums::REQUEST_CANCELLED_BY_DRIVER,'result'=>(string)$push_request_detail];

            $socket_data = new \stdClass();
            $socket_data->success = true;
            $socket_data->success_message  = PushEnums::REQUEST_CANCELLED_BY_DRIVER;
            $socket_data->result = $request_result;
            // Form a socket sturcture using users'id and message with event name
            $socket_message = structure_for_socket($user->id, 'user', $socket_data, 'trip_status');
            dispatch(new NotifyViaSocket('transfer_msg', $socket_message));

            dispatch(new NotifyViaMqtt('trip_status_'.$user->id, json_encode($socket_data), $user->id));
            $user->notify(new AndroidPushNotification($title, $body, $push_data));
        }

        return $this->respondSuccess(null, 'driver_cancelled_trip');
    }
}
