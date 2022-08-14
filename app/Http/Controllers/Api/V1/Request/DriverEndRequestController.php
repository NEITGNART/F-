<?php

namespace App\Http\Controllers\Api\V1\Request;

use App\Jobs\NotifyViaMqtt;
use App\Models\Admin\Promo;
use App\Jobs\NotifyViaSocket;
use Kreait\Firebase\Database;
use Illuminate\Support\Carbon;
use App\Models\Admin\PromoUser;
use App\Base\Constants\Masters\UnitType;
use App\Base\Constants\Masters\PushEnums;
use App\Base\Constants\Masters\PaymentType;
use App\Base\Constants\Masters\WalletRemarks;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Request\DriverEndRequest;
use App\Jobs\Notifications\AndroidPushNotification;
use App\Transformers\Requests\TripRequestTransformer;

/**
 * @group Driver-trips-apis
 *
 * APIs for Driver-trips apis
 */
class DriverEndRequestController extends BaseController
{
    public function __construct(Database $database)
    {
        $this->database = $database;
    }
    /**
    * Driver End Request
    * @bodyParam request_id uuid required id request
    * @bodyParam distance double required distance of request
    * @bodyParam before_arrival_waiting_time double required before arrival waiting time of request
    * @bodyParam after_arrival_waiting_time double required after arrival waiting time of request
    * @bodyParam drop_lat double required drop lattitude of request
    * @bodyParam drop_lng double required drop longitude of request
    * @bodyParam drop_address double required drop drop Address of request
    * @responseFile responses/requests/request_bill.json
    *
    */
    public function endRequest(DriverEndRequest $request)
    {
        // Get Request Detail
        $driver = auth()->user()->driver;

        $request_detail = $driver->requestDetail()->where('id', $request->request_id)->first();

        if (!$request_detail) {
            $this->throwAuthorizationException();
        }
        // Validate Trip request data
        if ($request_detail->is_completed) {
            // @TODO send success response with bill object
            // $this->throwCustomException('request completed already');
            $request_result = fractal($request_detail, new TripRequestTransformer)->parseIncludes('requestBill');
            return $this->respondSuccess($request_result, 'request_ended');
        }
        if ($request_detail->is_cancelled) {
            $this->throwCustomException('request cancelled');
        }

        $firebase_request_detail = $this->database->getReference('requests/'.$request_detail->id)->getValue();

        $request_place_params = ['drop_lat'=>$request->drop_lat,'drop_lng'=>$request->drop_lng,'drop_address'=>$request->drop_address];

        if ($firebase_request_detail) {
            if(array_key_exists('lat_lng_array',$firebase_request_detail)){
                $locations = $firebase_request_detail['lat_lng_array'];
                $request_place_params['request_path'] = $locations;
            }
        }
        // Remove firebase data
        // $this->database->getReference('requests/'.$request_detail->id)->remove();

        // Update Droped place details
        $request_detail->requestPlace->update($request_place_params);
        // Update Driver state as Available
        $request_detail->driverDetail->update(['available'=>true]);
        // Get currency code of Request
        $service_location = $request_detail->zoneType->zone->serviceLocation;

        $currency_code = $service_location->currency_code;
        $requested_currency_symbol = $service_location->currency_symbol;

        if (!$request_detail->is_later) {
            $ride_type = 1;
        } else {
            $ride_type = 2;
        }
        $zone_type = $request_detail->zoneType;
        $zone_type_price = $zone_type->zoneTypePrice()->where('price_type', $ride_type)->first();

        $distance_matrix = get_distance_matrix($request_detail->pick_lat, $request_detail->pick_lng, $request_detail->drop_lat, $request_detail->drop_lng, true);

        $distance = (double)$request->distance;
        $duration = $this->calculateDurationOfTrip($request_detail->trip_start_time);

        if ($distance_matrix->status =="OK" && $distance_matrix->rows[0]->elements[0]->status != "ZERO_RESULTS") {
            $distance_in_meters = get_distance_value_from_distance_matrix($distance_matrix);
            $distance = $distance_in_meters / 1000;

            if ($distance < $request->distance) {
                $distance = (double)$request->distance;
            }

            //If we need we can use these lines
            // $duration = get_duration_text_from_distance_matrix($distance_matrix);
            // $duration_in_mins = explode(' ', $duration);
            // $duration = (double)$duration_in_mins[0];
        }
        if ($request_detail->unit==UnitType::MILES) {
            $distance = kilometer_to_miles($distance);
        }

        // Update Request status as completed
        $request_detail->update([
            'is_completed'=>true,
            'completed_at'=>date('Y-m-d H:i:s'),
            'is_paid'=>1,
            'total_distance'=>$distance,
            'total_time'=>$duration,
            ]);
        $waiting_time = ($request->input('before_arrival_waiting_time')+$request->input('after_arrival_waiting_time'));
        // Calculated Fares
        $promo_detail =null;

        if ($request_detail->promo_id) {
            $promo_detail = $this->validateAndGetPromoDetail($request_detail->promo_id);
        }
        $calculated_bill =  $this->calculateRideFares($zone_type_price, $distance, $duration, $waiting_time, $promo_detail,$request_detail);
        $calculated_bill['requested_currency_code'] = $currency_code;
        $calculated_bill['requested_currency_symbol'] = $requested_currency_symbol;
        // @TODO need to take admin commision from driver wallet
        if ($request_detail->payment_opt==PaymentType::CASH) {
            // Deduct the admin commission + tax from driver walllet
            $admin_commision_with_tax = $calculated_bill['admin_commision_with_tax'];
            $driver_wallet = $request_detail->driverDetail->driverWallet;
            $driver_wallet->amount_spent += $admin_commision_with_tax;
            $driver_wallet->amount_balance -= $admin_commision_with_tax;
            $driver_wallet->save();

            $driver_wallet_history = $request_detail->driverDetail->driverWalletHistory()->create([
                'amount'=>$admin_commision_with_tax,
                'transaction_id'=>str_random(6),
                'remarks'=>WalletRemarks::ADMIN_COMMISSION_FOR_REQUEST,
                'is_credit'=>false
            ]);
        } elseif ($request_detail->payment_opt==PaymentType::CARD) {
            // @TODO in future
        } else { //PaymentType::WALLET
            // To Detect Amount From User's Wallet
            // Need to check if the user has enough amount to spent for his trip
            $chargable_amount = $calculated_bill['total_amount'];
            $user_wallet = $request_detail->userDetail->userWallet;

            if ($chargable_amount<=$user_wallet->amount_balance) {
                $user_wallet->amount_balance -= $chargable_amount;
                $user_wallet->amount_spent += $chargable_amount;
                $user_wallet->save();

                $user_wallet_history = $request_detail->userDetail->userWalletHistory()->create([
                'amount'=>$chargable_amount,
                'transaction_id'=>$request_detail->id,
                'request_id'=>$request_detail->id,
                'remarks'=>WalletRemarks::SPENT_FOR_TRIP_REQUEST,
                'is_credit'=>false]);

                // @TESTED to add driver commision if the payment type is wallet
                $driver_commision = $calculated_bill['driver_commision'];
                $driver_wallet = $request_detail->driverDetail->driverWallet;
                $driver_wallet->amount_added += $driver_commision;
                $driver_wallet->amount_balance += $driver_commision;
                $driver_wallet->save();

                $driver_wallet_history = $request_detail->driverDetail->driverWalletHistory()->create([
                'amount'=>$driver_commision,
                'transaction_id'=>$request_detail->id,
                'remarks'=>WalletRemarks::TRIP_COMMISSION_FOR_DRIVER,
                'is_credit'=>true
            ]);
            } else {
                $request_detail->payment_opt = PaymentType::CASH;
                $request_detail->save();
                $admin_commision_with_tax = $calculated_bill['admin_commision_with_tax'];
                $driver_wallet = $request_detail->driverDetail->driverWallet;
                $driver_wallet->amount_spent += $admin_commision_with_tax;
                $driver_wallet->amount_balance -= $admin_commision_with_tax;
                $driver_wallet->save();

                $driver_wallet_history = $request_detail->driverDetail->driverWalletHistory()->create([
                'amount'=>$admin_commision_with_tax,
                'transaction_id'=>str_random(6),
                'remarks'=>WalletRemarks::ADMIN_COMMISSION_FOR_REQUEST,
                'is_credit'=>false
            ]);
            }
        }
        // @TODO need to add driver commision if the payment type is wallet
        // Store Request bill
        $request_detail->requestBill()->create($calculated_bill);
        $request_result = fractal($request_detail, new TripRequestTransformer)->parseIncludes(['requestBill','userDetail','driverDetail']);
        if ($request_detail->if_dispatch || $request_detail->user_id==null ) {
            goto dispatch_notify;
        }
        // Send Push notification to the user
        $user = $request_detail->userDetail;
        $title = trans('push_notifications.trip_completed_title');
        $body = trans('push_notifications.trip_completed_body');



        $pus_request_detail = $request_result->toJson();
        $push_data = ['notification_enum'=>PushEnums::DRIVER_END_THE_TRIP,'result'=>(string)$pus_request_detail];

        $socket_data = new \stdClass();
        $socket_data->success = true;
        $socket_data->success_message  = PushEnums::DRIVER_END_THE_TRIP;
        $socket_data->result = $request_result;
        // Form a socket sturcture using users'id and message with event name
        $socket_message = structure_for_socket($user->id, 'user', $socket_data, 'trip_status');
        dispatch(new NotifyViaSocket('transfer_msg', $socket_message));

        dispatch(new NotifyViaMqtt('trip_status_'.$user->id, json_encode($socket_data), $user->id));

        $user->notify(new AndroidPushNotification($title, $body));
        dispatch_notify:
        // @TODO Send email & sms
        return $this->respondSuccess($request_result, 'request_ended');
    }

    public function calculateDurationOfTrip($start_time)
    {
        $current_time = date('Y-m-d H:i:s');
        $start_time = Carbon::parse($start_time);
        $end_time = Carbon::parse($current_time);
        $totald_duration = $end_time->diffInMinutes($start_time);

        return $totald_duration;
    }

    /**
    * Calculate Ride fares
    *
    */
    public function calculateRideFares($zone_type_price, $distance, $duration, $waiting_time, $coupon_detail,$request_detail)
    {   
        $request_place = $request_detail->requestPlace;

        $airport_surge = find_airport($request_place->pick_lat,$request_place->pick_lng);
        if($airport_surge==null){
            $airport_surge = find_airport($request_place->drop_lat,$request_place->drop_lng);
        }

        $airport_surge_fee = 0;

        if($airport_surge){

            $airport_surge_fee = $airport_surge->airport_surge_fee?:0;

        }

        // Distance Price
        $calculatable_distance = $distance - $zone_type_price->base_distance;
        $calculatable_distance = $calculatable_distance<0?0:$calculatable_distance;
        $distance_price = $calculatable_distance * $zone_type_price->price_per_distance;
        // Time Price
        $time_price = $duration * $zone_type_price->price_per_time;
        // Waiting charge
        $waiting_charge = $waiting_time * $zone_type_price->waiting_charge;
        // Base Price
        $base_price = $zone_type_price->base_price;

        // Sub Total
        $sub_total = $base_price+$distance_price+$time_price+$waiting_charge + $airport_surge_fee;
        $discount_amount = 0;
        if ($coupon_detail) {
            if ($coupon_detail->minimum_trip_amount && $coupon_detail->minimum_trip_amount < $sub_total) {
                $discount_amount = $sub_total * ($coupon_detail->coupon_detail/100);
                if ($discount_amount > $coupon_detail->maximum_discount_amount) {
                    $discount_amount = $coupon_detail->maximum_discount_amount;
                }
                $sub_total = $sub_total - $discount_amount;
            }
        }

        // Get service tax percentage from settings
        $tax_percent = get_settings('service_tax');
        $tax_amount = ($sub_total * ($tax_percent / 100));
        // Get Admin Commision
        $service_fee = get_settings('admin_commission');
        // Admin commision
        $admin_commision = ($sub_total * ($service_fee / 100));
        // Admin commision with tax amount
        $admin_commision_with_tax = $tax_amount + $admin_commision;
        // Driver Commission
        $driver_commision = $sub_total+$discount_amount;
        // Total Amount
        $total_amount = $sub_total + $admin_commision_with_tax;

        return $result = [
        'base_price'=>$base_price,
        'base_distance'=>$zone_type_price->base_distance,
        'price_per_distance'=>$zone_type_price->price_per_distance,
        'distance_price'=>$distance_price,
        'price_per_time'=>$zone_type_price->price_per_time,
        'time_price'=>$time_price,
        'promo_discount'=>$discount_amount,
        'waiting_charge'=>$waiting_charge,
        'service_tax'=>$tax_amount,
        'service_tax_percentage'=>$tax_percent,
        'admin_commision'=>$admin_commision,
        'admin_commision_with_tax'=>$admin_commision_with_tax,
        'driver_commision'=>$driver_commision,
        'total_amount'=>$total_amount,
        'total_distance'=>$distance,
        'total_time'=>$duration,
        'airport_surge_fee'=>$airport_surge_fee
        ];
    }

    /**
    * Validate & Apply Promo code
    *
    */
    public function validateAndGetPromoDetail($promo_code_id)
    {
        $user = auth()->user();
        // Validate if the promo is expired
        $current_date = Carbon::today()->toDateTimeString();

        $expired = Promo::where('id', $promo_code_id)->where('from', '<=', $current_date)->orWhere('to', '>=', $current_date)->first();

        if ($expired) {
            return null;
        }
        $exceed_usage = PromoUser::where('promo_code_id', $expired->id)->where('user_id', $user->id)->get()->count();
        if ($exceed_usage >= $expired->uses_per_user) {
            return null;
        }
        if ($expired->total_uses > $expired->total_uses+1) {
            return null;
        }
        return $expired;
    }
}
