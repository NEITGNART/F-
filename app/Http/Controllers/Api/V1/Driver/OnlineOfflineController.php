<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Models\Admin\Driver;
use Illuminate\Support\Carbon;
use App\Transformers\Driver\DriverTransformer;
use App\Http\Controllers\Api\V1\BaseController;

class OnlineOfflineController extends BaseController
{
    protected $driver;

    public function __construct(Driver $driver)
    {
        $this->driver = $driver;
    }

    /**
    * Online-Offline driver
    * @group Driver-trips-apis
    * @return \Illuminate\Http\JsonResponse
    * @responseFile responses/driver/Online-OfflineResponse.json
    * @responseFile responses/driver/DriverOfflineResponse.json
    */
    public function toggle()
    {
        $driver = Driver::where('user_id', auth()->user()->id)->first();

        $status = $driver->active?0:1;
        $current_date = Carbon::now();


        if ($status) {
            // check if any record is exists with same date
            $availability = $driver->driverAvailabilities()->whereDate('online_at', $current_date)->orderBy('online_at', 'desc')->first();

            $created_params['is_online'] = true;
            $created_params['online_at'] = $current_date->toDateTimeString();

            if ($availability) {
                $created_params['duration'] = 0;
            }
            // store record for online
            $driver->driverAvailabilities()->create($created_params);
        } else {
            // get last online availability record
            $availability = $driver->driverAvailabilities()->where('is_online', true)->orderBy('online_at', 'desc')->first();

            if (Carbon::parse($availability->online_at)->toDateString()!=$current_date->toDateString()) {
                // Need to create offline record for last online date
                $created_params['is_online'] = false;
                // Temporary
                $last_offline_date = Carbon::parse($availability->online_at)->addMinutes(30)->toDateTimeString();

                if (Carbon::parse($last_offline_date)->toDateString()==$current_date->toDateString()) {
                    $last_offline_date = Carbon::parse($availability->online_at)->endOfDay()->toDateTimeString();
                }
                // $last_offline_date = Carbon::parse($availability->online_at)->toDateString().' 23:59:59';
                $offline_update_params['offline_at'] = $last_offline_date;
                $last_online_online_at = Carbon::parse($availability->online_at);
                $last_offline_date = Carbon::parse($last_offline_date);
                $duration = $last_offline_date->diffInMinutes($last_online_online_at);
                $offline_update_params['duration'] = $availability->duration+$duration;
                // store offline record
                $availability->update($offline_update_params);
                // store online record
                $online_created_params['is_online'] = true;
                // Temporary
                $to_online_date = $current_date->subMinutes(30)->toDateTimeString();
                if ($current_date->subMinutes(30)->toDateString()!=$current_date->toDateString()) {
                    $to_online_date = $current_date->startOfDay()->toDateTimeString();
                }
                $online_created_params['online_at'] = $to_online_date;
                $online_created_params['duration'] = 0;
                $availability =  $driver->driverAvailabilities()->create($online_created_params);
            }
            // Store offllne record
            $created_params['is_online'] = false;
            $created_params['offline_at'] = $current_date->toDateTimeString();
            $last_online_online_at = Carbon::parse($availability->online_at);
            $duration = $current_date->diffInMinutes($last_online_online_at);
            $created_params['duration'] = $availability->duration+$duration;
            $availability->update($created_params);
        }

        $success_message = $status?'online-success':'offline-success';
        $driver->active = $status;
        $driver->available = true;
        $driver->save();
        $driver->fresh();

        $user = filter()->transformWith(new DriverTransformer)
            ->loadIncludes($driver);

        return $this->respondSuccess($user, $success_message);
    }
}
