<?php

namespace App\Http\Controllers\Api\V1\Request;

use App\Models\Admin\Promo;
use App\Http\Controllers\Api\V1\BaseController;
use App\Transformers\Requests\PromoCodesTransformer;

/**
 * @group User-trips-apis
 *
 * APIs for User-trips apis
 */
class PromoCodeController extends BaseController
{
    protected $promocode;

    public function __construct(Promo $promocode)
    {
        $this->promocode = $promocode;
    }

    /**
    * List Promo codes for user
    * @responseFile responses/user/trips/promocode-list.json
    */
    public function index()
    {
        $query = $this->promocode->get();

        $result = fractal($query, new PromoCodesTransformer);

        return $this->respondSuccess($result, 'promo_listed');
    }
}
