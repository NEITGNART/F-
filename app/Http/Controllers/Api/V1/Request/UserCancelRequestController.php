<?php

namespace App\Http\Controllers\Api\V1\Request;

use App\Jobs\NotifyViaMqtt;
use App\Jobs\NotifyViaSocket;
use App\Models\Request\RequestMeta;
use App\Base\Constants\Masters\UserType;
use App\Base\Constants\Masters\PushEnums;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Request\CancelTripRequest;
use App\Jobs\Notifications\AndroidPushNotification;
use App\Transformers\Requests\TripRequestTransformer;
use App\Base\Constants\Masters\WalletRemarks;
use App\Base\Constants\Masters\zoneRideType;
use App\Base\Constants\Masters\PaymentType;
use App\Models\Admin\CancellationReason;

/**
 * @group User-trips-apis
 *
 * APIs for User-trips apis
 */
class UserCancelRequestController extends BaseController
{

    /**
    * User Cancel Request
    * @bodyParam request_id uuid required id of request
    * @bodyParam reason string optional reason provided by user
    * @bodyParam custom_reason string optional custom reason provided by user
    *@response {
    "success": true,
    "message": "success"}
    */
    public function cancelRequest(CancelTripRequest $request)
    {
        /**
        * Validate the request which is authorised by current authenticated user
        * Cancel the request by updating is_cancelled true with reason if there is any reason
        * Available the driver who belongs to the request
        * Notify the driver that the user is cancelled the trip request
        */
        // Validate the request which is authorised by current authenticated user
        $user = auth()->user();
        $request_detail = $user->requestDetail()->where('id', $request->request_id)->first();
        // Throw an exception if the user is not authorised for this request
        if (!$request_detail) {
            $this->throwAuthorizationException();
        }
        $request_detail->update([
            'is_cancelled'=>true,
            'reason'=>$request->reason,
            'custom_reason'=>$request->custom_reason,
            'cancel_method'=>UserType::USER,
        ]);

        $request_detail->fresh();
        /**
        * Apply Cancellation Fee
        */
        $charge_applicable = false;

        if ($request->custom_reason) {
            $charge_applicable = true;
        }
        if ($request->reason) {
            $reason = CancellationReason::find($request->reason);
            if($reason){

            if ($reason->payment_type=='free') {
                $charge_applicable=false;
            } else {
                $charge_applicable=true;
            }

            }else{

                $charge_applicable = false;
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
            if ($request_detail->payment_opt==PaymentType::WALLET) {
                $requested_user = $request_detail->userDetail;
                $user_wallet = $requested_user->userWallet;
                $user_wallet->amount_spent += $cancellation_fee;
                $user_wallet->amount_balance -= $cancellation_fee;
                $user_wallet->save();
                // Add the history
                $requested_user->userWalletHistory()->create([
                    'amount'=>$cancellation_fee,
                    'transaction_id'=>$request_detail->id,
                    'remarks'=>WalletRemarks::CANCELLATION_FEE,
                    'request_id'=>$request_detail->id,
                    'is_credit'=>false]);
                $request_detail->requestCancellationFee()->create(['user_id'=>$request_detail->user_id,'is_paid'=>true,'cancellation_fee'=>$cancellation_fee,'paid_request_id'=>$request_detail->id]);
            } else {
                $request_detail->requestCancellationFee()->create(['user_id'=>$request_detail->user_id,'is_paid'=>false,'cancellation_fee'=>$cancellation_fee]);
            }
        }

        // Available the driver who belongs to the request
        $request_driver = $request_detail->driverDetail;
        if ($request_driver) {
            $driver = $request_driver;
        } else {
            $request_meta_driver = $request_detail->requestMeta()->where('active', true)->first();
            if($request_meta_driver){
            $driver = $request_meta_driver->driver;

            }else{
                $driver=null;
            }
        }
        
        if ($driver) {
            $driver->available = true;
            $driver->save();
            $driver->fresh();
            // Notify the driver that the user is cancelled the trip request
            $notifiable_driver = $driver->user;
            $request_result =  fractal($request_detail, new TripRequestTransformer)->parseIncludes('userDetail');

            $push_request_detail = $request_result->toJson();
            $title = trans('push_notifications.trip_cancelled_by_user_title');
            $body = trans('push_notifications.trip_cancelled_by_user_body');

            $push_data = ['success'=>true,'success_message'=>PushEnums::REQUEST_CANCELLED_BY_USER,'result'=>(string)$push_request_detail];

            $socket_data = new \stdClass();
            $socket_data->success = true;
            $socket_data->success_message  = PushEnums::REQUEST_CANCELLED_BY_USER;
            $socket_data->result = $request_result;
            // Form a socket sturcture using users'id and message with event name
            $socket_message = structure_for_socket($driver->id, 'driver', $socket_data, 'request_handler');

            dispatch(new NotifyViaSocket('transfer_msg', $socket_message));
            // Send data via Mqtt
            dispatch(new NotifyViaMqtt('request_handler_'.$driver->id, json_encode($socket_data), $driver->id));

            $notifiable_driver->notify(new AndroidPushNotification($title, $body, $push_data));
        }
        // Delete meta records
        // RequestMeta::where('request_id', $request_detail->id)->delete();
        $request_detail->requestMeta()->delete();

        return $this->respondSuccess();
    }
}
