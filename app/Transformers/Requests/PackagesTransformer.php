<?php

namespace App\Transformers\Requests;

use App\Models\Master\PackageType;
use App\Transformers\Transformer;
use App\Helpers\Exception\ExceptionHelpers;
use App\Models\Admin\ZoneType;
use App\Transformers\User\ZoneTypeTransformer;
use App\Transformers\Requests\ZoneTypeWithPackagePriceTransformer;

class PackagesTransformer extends Transformer
{
    use ExceptionHelpers;
     /**
     * Resources that can be included if requested.
     *
     * @var array
     */
    protected $availableIncludes = [
        
    ];
    /**
     * Resources that can be included default.
     *
     * @var array
     */
    protected $defaultIncludes = [
        
        'typesWithPrice'
    ];

    /**
     * A Fractal transformer.
     *
     * @param PackageType $package
     * @return array
     */
    public function transform(PackageType $package)
    {
        $params = [
            'id' => $package->id,
            'package_name'=>$package->name,
        ];

       
        return $params;
    }


    /**
    * Include the vehicle type along with price.
    *
    * @param User $user
    * @return \League\Fractal\Resource\Collection|\League\Fractal\Resource\NullResource
    */
    public function includeTypesWithPrice(PackageType $package)
    {   

        $zone_detail = find_zone(request()->input('pick_lat'), request()->input('pick_lng'));

        if (!$zone_detail) {
            $this->throwCustomException('service not available with this location');
        }

        $types = ZoneType::where('zone_id',$zone_detail->id)->whereHas('zoneTypePackage',function($query)use($package){
            $query->where('package_type_id',$package->id);
        })->get();

        $zone_types = [];

        foreach ($types as $key => $type) {

            $prices = $type->zoneTypePackage()->where('package_type_id',$package->id)->first();

            $zone_types[] = array(
                'zone_type_id'=>$type->id,
                'type_id'=>$type->type_id,
                'name'=>$type->vehicle_type_name,
                'icon'=>$type->icon,
                'capacity'=>$type->vehicleType->capacity,
                'currency'=> $type->zone->serviceLocation->currency_symbol,
                'unit' => $type->zone->unit,
                'unit_in_words' => $type->zone->unit ? 'Km' : 'Miles',
                'distance_price_per_km'=>$prices->distance_price_per_km,
                'time_price_per_min'=>$prices->time_price_per_min,
                'free_distance'=>$prices->free_distance,
                'free_min'=>$prices->free_min,
                'fare_amount'=>$prices->base_price,
                'description'=> $type->vehicleType->description,
                'short_description'=> $type->vehicleType->short_description,
                'supported_vehicles'=> $type->vehicleType->supported_vehicles,
                'is_default'=>$type->zone->default_vehicle_type==$type->type_id?true:false,
            );

        }

        // dd(collect($zone_types));
    
        return $zone_types
        ? $this->collection(collect($zone_types), new ZoneTypeWithPackagePriceTransformer)
        : $this->null();
    }
}
