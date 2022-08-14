<?php

namespace App\Http\Controllers\Api\V1\Driver;

use Carbon\Carbon;
use App\Models\Request\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Request\RequestBill;
use App\Models\Admin\DriverAvailability;
use App\Base\Constants\Master\PaymentType;
use App\Http\Controllers\Api\V1\BaseController;

/**
 * @group Driver Earnings
 *
 * APIs for Driver's Earnings
 */
class EarningsController extends BaseController
{
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    /**
    * Today-Earnings
    * @responseFile responses/driver/today-earnings.json
    */
    public function index()
    {
        $driver = auth()->user()->driver;

        $current_date = Carbon::now();//->subDays(1)

        $timezone = auth()->user()->timezone?:env('SYSTEM_DEFAULT_TIMEZONE');

        $converted_current_date = Carbon::parse($current_date)->setTimezone($timezone)->format('jS M Y');

        $total_trips = $this->request->where('driver_id', $driver->id)->where('is_completed', 1)->whereDate('trip_start_time', $current_date)->get()->count();

        // Total Trip kms
        $total_trip_kms = $this->request->where('driver_id', $driver->id)->where('is_completed', 1)->whereDate('trip_start_time', $current_date)->sum('total_distance');
        // Total Earnings
        $total_earnings = RequestBill::whereHas('requestDetail', function ($query) use ($driver,$current_date) {
            $query->where('driver_id', $driver->id)->where('is_completed', 1)->whereDate('trip_start_time', $current_date);
        })->sum('driver_commision');

        //Total cash trip amount
        $total_cash_trip_amount = RequestBill::whereHas('requestDetail', function ($query) use ($driver,$current_date) {
            $query->where('driver_id', $driver->id)->where('is_completed', 1)->whereDate('trip_start_time', $current_date)->where('payment_opt', '1'); //cash
        })->sum('driver_commision');

        // Driver duties
        $driver_duties = DriverAvailability::whereDate('online_at', $current_date)->select(DB::raw(" driver_id, date(online_at) AS working_date, SUM(duration) AS total_hours_worked"))->groupBy(DB::raw("driver_id, date(online_at)"))->first();

        $total_hours_worked = $driver_duties?$driver_duties->total_hours_worked:0;

        $total_hours_worked = $total_hours_worked>60?round($total_hours_worked/60, 3).' Hrs':$total_hours_worked.' Mins';

        // Total Wallet trip amount
        $total_wallet_trip_amount = RequestBill::whereHas('requestDetail', function ($query) use ($driver,$current_date) {
            $query->where('driver_id', $driver->id)->where('is_completed', 1)->whereDate('trip_start_time', $current_date)->where('payment_opt', '2'); //cash
        })->sum('driver_commision');

        $total_cash_trip_count = $this->request->where('driver_id', $driver->id)->where('is_completed', 1)->whereDate('trip_start_time', $current_date)->where('payment_opt', '1')->get()->count();

        $total_wallet_trip_count = $this->request->where('driver_id', $driver->id)->where('is_completed', 1)->whereDate('trip_start_time', $current_date)->where('payment_opt', '2')->get()->count();

        $currency_symbol = auth()->user()->countryDetail->currency_symbol;

        return response()->json(['success'=>true,'message'=>'todays_earnings','data'=>['current_date'=>$converted_current_date,'total_trips_count'=>$total_trips,'total_trip_kms'=>$total_trip_kms,'total_earnings'=>$total_earnings,'total_cash_trip_amount'=>$total_cash_trip_amount,'total_wallet_trip_amount'=>$total_wallet_trip_amount,'total_cash_trip_count'=>$total_cash_trip_count,'total_wallet_trip_count'=>$total_wallet_trip_count,'currency_symbol'=>$currency_symbol,'total_hours_worked'=>$total_hours_worked]]);
    }

    /**
    * Weekly Earnings
    * @urlParam week_number integer week number of year
    * @responseFile responses/driver/weekly-earnings.json
    */
    public function weeklyEarnings()
    {
        $driver = auth()->user()->driver;
        $current_date = Carbon::now();
        $disable_next_week = true;
        $disable_previous_week = false;

        $current_week_number = $current_date->weekOfYear;

        if (request()->has('week_number')) {
            if ($current_week_number == request()->week_number) {
                $current_week_number = (integer)request()->week_number;
            } else {
                $current_week_number = (integer)request()->week_number;
                $disable_next_week = false;
            }
        }

        // $current_date->week($current_week_number)->format('Y-m-d H:i');

        $start_of_week = $current_date->startOfWeek()->toDateString();

        $end_of_week = $current_date->endOfWeek()->toDateString();

        $timezone = auth()->user()->timezone?:env('SYSTEM_DEFAULT_TIMEZONE');

        $converted_start_of_week = Carbon::parse($start_of_week)->setTimezone($timezone)->format('jS M Y');
        $converted_end_of_week = Carbon::parse($end_of_week)->setTimezone($timezone)->format('jS M Y');


        $weekDays = [];

        $week_days_string = ['sun','mon','tues','wed','thurs','fri','sat'];


        for ($i = 0; $i < 7; $i++) {
            $week_date =  Carbon::parse($start_of_week)->addDay($i)->format('Y-m-d');
            $weekly_total_earnings = RequestBill::whereHas('requestDetail', function ($query) use ($driver,$week_date) {
                $query->where('driver_id', $driver->id)->where('is_completed', 1)->whereDate('trip_start_time', $week_date);
            })->sum('driver_commision');
            foreach ($week_days_string as $key => $week_day) {
                if ($key==$i) {
                    $weekDays[$week_day] = $weekly_total_earnings;
                }
            }
        }

        $weeks = [$start_of_week,$end_of_week];

        $converted_current_date = Carbon::parse($current_date)->setTimezone($timezone)->format('jS M Y');

        $total_trips = $this->request->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks)->get()->count();

        if ($total_trips==0) {
            $disable_previous_week = true;
        }
        // Total Trip kms
        $total_trip_kms = $this->request->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks)->sum('total_distance');

        // Driver Duties
        $query = "SELECT SUM(total_hours_worked) AS total_hours_worked
        FROM (SELECT date(online_at), SUM(duration) AS total_hours_worked
        FROM driver_availabilities where driver_id= $driver->id AND date(online_at) BETWEEN '$start_of_week' and '$end_of_week'
        GROUP BY date(online_at)) as duration";

        $driver_duties = DB::select($query);

        $total_hours_worked = $driver_duties[0]->total_hours_worked;
        $total_hours_worked = $total_hours_worked>60?round($total_hours_worked/60, 3).' Hrs':$total_hours_worked.' Mins';

        // Total Earnings
        $total_earnings = RequestBill::whereHas('requestDetail', function ($query) use ($driver,$weeks) {
            $query->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks);
        })->sum('driver_commision');

        //Total cash trip amount
        $total_cash_trip_amount = RequestBill::whereHas('requestDetail', function ($query) use ($driver,$weeks) {
            $query->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks)->where('payment_opt', '1'); //cash
        })->sum('driver_commision');

        // Total Wallet trip amount
        $total_wallet_trip_amount = RequestBill::whereHas('requestDetail', function ($query) use ($driver,$weeks) {
            $query->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks)->where('payment_opt', '2'); //cash
        })->sum('driver_commision');

        $total_cash_trip_count = $this->request->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks)->where('payment_opt', '1')->get()->count();

        $total_wallet_trip_count = $this->request->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks)->where('payment_opt', '2')->get()->count();

        $currency_symbol = auth()->user()->countryDetail->currency_symbol;

        return response()->json(['success'=>true,'message'=>'weekly_earnings','data'=>['week_days'=>$weekDays,'current_date'=>$converted_current_date,'current_week_number'=>$current_week_number,'start_of_week'=>$converted_start_of_week,'end_of_week'=>$converted_end_of_week,'disable_next_week'=>$disable_next_week,'disable_previous_week'=>$disable_previous_week,'total_trips_count'=>$total_trips,'total_trip_kms'=>$total_trip_kms,'total_earnings'=>$total_earnings,'total_cash_trip_amount'=>$total_cash_trip_amount,'total_wallet_trip_amount'=>$total_wallet_trip_amount,'total_cash_trip_count'=>$total_cash_trip_count,'total_wallet_trip_count'=>$total_wallet_trip_count,'currency_symbol'=>$currency_symbol,'total_hours_worked'=>$total_hours_worked]]);
    }
    /**
    * Earnings Report
    * @urlParam from_date date date string
    * @urlParam to_date date date string
    * @responseFile responses/driver/earnings-report.json
    */
    public function earningsReport($from_date, $to_date)
    {
        $driver = auth()->user()->driver;

        $timezone = auth()->user()->timezone?:env('SYSTEM_DEFAULT_TIMEZONE');

        $weeks = [$from_date,$to_date];

        $total_trips = $this->request->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks)->get()->count();

        if ($total_trips==0) {
            $disable_previous_week = true;
        }
        // Total Trip kms
        $total_trip_kms = $this->request->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks)->sum('total_distance');

        // Total Earnings
        $total_earnings = RequestBill::whereHas('requestDetail', function ($query) use ($driver,$weeks) {
            $query->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks);
        })->sum('driver_commision');

        //Total cash trip amount
        $total_cash_trip_amount = RequestBill::whereHas('requestDetail', function ($query) use ($driver,$weeks) {
            $query->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks)->where('payment_opt', '1'); //cash
        })->sum('driver_commision');

        $query = "SELECT SUM(total_hours_worked) AS total_hours_worked
        FROM (SELECT date(online_at), SUM(duration) AS total_hours_worked
        FROM driver_availabilities where driver_id= $driver->id AND date(online_at) BETWEEN '$from_date' and '$to_date'
        GROUP BY date(online_at)) as duration";

        $driver_duties = DB::select($query);

        $total_hours_worked = $driver_duties[0]->total_hours_worked;
        $total_hours_worked = $total_hours_worked>60?round($total_hours_worked/60, 3).' Hrs':$total_hours_worked.' Mins';

        // Total Wallet trip amount
        $total_wallet_trip_amount = RequestBill::whereHas('requestDetail', function ($query) use ($driver,$weeks) {
            $query->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks)->where('payment_opt', '2'); //cash
        })->sum('driver_commision');

        $total_cash_trip_count = $this->request->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks)->where('payment_opt', '1')->get()->count();

        $total_wallet_trip_count = $this->request->where('driver_id', $driver->id)->where('is_completed', 1)->whereBetween('trip_start_time', $weeks)->where('payment_opt', '2')->get()->count();

        $currency_symbol = auth()->user()->countryDetail->currency_symbol;

        $converted_from_date = Carbon::parse($from_date)->format('jS M Y');
        $converted_to_date = Carbon::parse($to_date)->format('jS M Y');

        return response()->json(['success'=>true,'message'=>'earnings_report','data'=>['from_date'=>$converted_from_date,'to_date'=>$converted_to_date,'total_trips_count'=>$total_trips,'total_trip_kms'=>$total_trip_kms,'total_earnings'=>$total_earnings,'total_cash_trip_amount'=>$total_cash_trip_amount,'total_wallet_trip_amount'=>$total_wallet_trip_amount,'total_cash_trip_count'=>$total_cash_trip_count,'total_wallet_trip_count'=>$total_wallet_trip_count,'currency_symbol'=>$currency_symbol,'total_hours_worked'=>$total_hours_worked]]);
    }
}
