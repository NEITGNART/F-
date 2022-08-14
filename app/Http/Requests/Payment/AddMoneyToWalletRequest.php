<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\BaseRequest;

class AddMoneyToWalletRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'card_id'=>'required|exists:card_info,id',
            'amount'=>'required|double',
        ];
    }
}
