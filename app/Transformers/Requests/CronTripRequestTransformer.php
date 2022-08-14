<?php

namespace App\Transformers\Requests;

use Carbon\Carbon;
use App\Transformers\Transformer;
use App\Transformers\User\UserTransformer;
use App\Transformers\Driver\DriverTransformer;
use App\Models\Request\Request as RequestModel;
use App\Transformers\User\AdHocUserTransformer;
use App\Transformers\Requests\RequestBillTransformer;

class CronTripRequestTransformer extends Transformer
{
    /**
     * Resources that can be included if requested.
     *
     * @var array
     */
    protected $availableIncludes = [
        'userDetail'
    ];

    /**
     * A Fractal transformer.
     *
     * @param RequestModel $request
     * @return array
     */
    public function transform(RequestModel $request)
    {
        $param =  [
            'id' => $request->id,
            'request_number' => $request->request_number,
            'is_later' => $request->is_later,
            'user_id' => $request->user_id,
            'trip_start_time' => $request->trip_start_time,
            'is_driver_started'=>$request->is_driver_started,
            'is_driver_arrived'=>$request->is_driver_arrived,
            'is_trip_start'=>$request->is_trip_start,
            'total_distance'=>$request->total_distance,
            'total_time'=>$request->total_time,
            'is_completed'=>$request->is_completed,
            'is_cancelled' => $request->is_cancelled,
            'cancel_method'=>$request->cancel_method,
            'payment_opt' => $request->payment_opt,
            'is_paid' => $request->is_paid,
            'user_rated' => $request->user_rated,
            'driver_rated' => $request->driver_rated,
            'unit' => $request->unit==0?'MILES':'KM',
            'zone_type_id'=>$request->zone_type_id,
            'vehicle_type_name'=>$request->vehicle_type_name,
            'pick_lat'=>$request->pick_lat,
            'pick_lng'=>$request->pick_lng,
            'drop_lat'=>$request->drop_lat,
            'drop_lng'=>$request->drop_lng,
            'pick_address'=>$request->pick_address,
            'drop_address'=>$request->drop_address,
            'requested_currency_code'=>$request->requested_currency_code,
            'requested_currency_symbol'=>$request->requested_currency_symbol,

        ];

        $timezone = $request->userDetail->timezone?:env('SYSTEM_DEFAULT_TIMEZONE');

        $param['trip_start_time'] =  Carbon::parse($request->trip_start_time)->setTimezone($timezone)->format('jS M h:i A');

        return $param;
    }
    /**
     * Include the user of the request.
     *
     * @param RequestModel $request
     * @return \League\Fractal\Resource\Item|\League\Fractal\Resource\NullResource
     */
    public function includeUserDetail(RequestModel $request)
    {
        if ($request->if_dispatch) {
            // @TODO need to redirect with adhoc user transformer
            $userDetail = $request->adHocuserDetail;
            return $userDetail
        ? $this->item($userDetail, new AdHocUserTransformer)
        : $this->null();
        } else {
            $userDetail = $request->userDetail;
            return $userDetail
        ? $this->item($userDetail, new UserTransformer)
        : $this->null();
        }
    }
}
