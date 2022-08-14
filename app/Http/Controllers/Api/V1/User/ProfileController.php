<?php

namespace App\Http\Controllers\Api\V1\User;

use Illuminate\Http\Request;
use App\Http\Controllers\ApiController;
use App\Transformers\User\UserTransformer;
use App\Transformers\Driver\DriverTransformer;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Requests\User\ChangePasswordRequest;
use App\Http\Requests\User\UpdateDriverProfileRequest;
use App\Base\Services\ImageUploader\ImageUploaderContract;
use App\Base\Constants\Auth\Role;
use App\Models\User;
use App\Models\Request\FavouriteLocation;

/**
 * @group Profile-Management
 *
 * APIs for Profile-Management
 */
class ProfileController extends ApiController
{
    /**
     * ImageUploader instance.
     *
     * @var ImageUploaderContract
     */
    protected $imageUploader;

    protected $user;


    /**
     * ProfileController constructor.
     *
     * @param ImageUploaderContract $imageUploader
     */
    public function __construct(ImageUploaderContract $imageUploader,User $user)
    {
        $this->imageUploader = $imageUploader;

        $this->user = $user;
    }

    /**
     * Update user profile.
     *
     * @param UpdateProfileRequest $request
     * @return \Illuminate\Http\JsonResponse
     * @response
     * {
     *"success": true,
     *"message": "success"
     *}
     */
    public function updateProfile(UpdateProfileRequest $request)
    {
        $data = $request->only(['name', 'email', 'last_name','mobile']);

        if ($uploadedFile = $this->getValidatedUpload('profile_picture', $request)) {
            $data['profile_picture'] = $this->imageUploader->file($uploadedFile)
                ->saveProfilePicture();
        }
        $user = auth()->user();

        $mobile = $request->mobile;

        if($mobile){
             $validate_exists_mobile = $this->user->belongsTorole(Role::USER)->where('mobile', $mobile)->where('id','!=',$user->id)->exists();

        if ($validate_exists_mobile) {
            $this->throwCustomException('Provided mobile has already been taken');
        }

        }

        
         if($request->email){
             $validate_exists_email = $this->user->belongsTorole(Role::USER)->where('email', $request->email)->where('id','!=',$user->id)->exists();

        if ($validate_exists_email) {
            $this->throwCustomException('Provided email has already been taken');
        }

        }

        $user->update($data);
        $user = fractal($user->fresh(), new UserTransformer);

        return $this->respondSuccess($user);
    }

    /**
    * Update Driver Profile
    *
    */
    public function updateDriverProfile(UpdateDriverProfileRequest $request)
    {
        $user_params = $request->only(['name', 'email', 'last_name','mobile']);


        $user = auth()->user();


        $mobile = $request->mobile;

        if($mobile){
             $validate_exists_mobile = $this->user->belongsTorole(Role::DRIVER)->where('mobile', $mobile)->where('id','!=',$user->id)->exists();

        if ($validate_exists_mobile) {
            $this->throwCustomException('Provided mobile has already been taken');
        }

        }

        if($request->email){
             $validate_exists_email = $this->user->belongsTorole(Role::DRIVER)->where('email', $request->email)->where('id','!=',$user->id)->exists();

        if ($validate_exists_email) {
            $this->throwCustomException('Provided email has already been taken');
        }

        }
       

        if ($uploadedFile = $this->getValidatedUpload('profile_picture', $request)) {
            $user_params['profile_picture'] = $this->imageUploader->file($uploadedFile)
                ->saveProfilePicture();
        }
        $user->update($user_params);

        $driver_params = $request->only(['vehicle_type','car_make','car_model','car_color','car_number','name','email']);

        $user->driver()->update($driver_params);

        $driver_details = $user->driver;

        $result = fractal($driver_details, new DriverTransformer)->parseIncludes(['onTripRequest.userDetail','onTripRequest.requestBill','metaRequest.userDetail']);

        return $this->respondOk($result);
    }

    /**
     * Update user password.
     * @bodyParam old_password password required old_password provided user
     * @bodyParam password password required password provided user
     * @bodyParam password_confirmation password required  confirmed password provided user
     * @param ChangePasswordRequest $request
     * @return \Illuminate\Http\JsonResponse
     * @response
     * {
     *"success": true,
     *"message": "success"
     *}
     */
    public function updatePassword(ChangePasswordRequest $request)
    {
        $oldPassword = $request->input('old_password');
        $password = $request->input('password');

        $user = auth()->user();

        if (!hash_check($oldPassword, $user->password)) {
            $this->throwCustomValidationException('Invalid old password entered.', 'old_password');
        }

        $user->forceFill(['password' => bcrypt($password)])->save();

        return $this->respondSuccess();
    }

    /**
    * Update My Language
    * @bodyParam lang string required language provided user
     * @return \Illuminate\Http\JsonResponse
     * @response
     * {
     *"success": true,
     *"message": "success"
     *}
    */
    public function updateMyLanguage(Request $request)
    {
        // Validate Request id
        $request->validate([
        'lang' => 'required',
        ]);
        $user = auth()->user();
        $user->forceFill(['lang' => $request->lang])->save();
        return $this->respondSuccess();
    }

    /**
    * Add favourite location
    * @bodyParam pick_lat double required pikup lat of the user
    * @bodyParam pick_lng double required pikup lng of the user
    * @bodyParam drop_lat double optional drop lat of the user
    * @bodyParam drop_lng double optional drop lng of the user
    * @bodyParam pick_address string required pickup address of the favourite location
    * @bodyParam drop_address string optional drop address of the favourite location
    * @bodyParam address_name string required address name of the favourite location
    * @bodyParam landmark string optional drop address of the favourite location
    * @response
     * {
     *"success": true,
     *"message": "address added successfully"
     *}
    */
    public function addFavouriteLocation(Request $request){

        // Validate Request id
        $request->validate([
            'pick_lat'  => 'required',
            'pick_lng'  => 'required',
            'pick_address'=>'required',
            'drop_lat'  =>'sometimes|required',
            'drop_lng'  =>'sometimes|required',
            'drop_address'=>'sometimes|required',
            'address_name'=>'required',
        ]);

        $created_params = $request->all();

        $created_params['user_id'] = auth()->user()->id;

        $locations = FavouriteLocation::where('user_id',auth()->user()->id)->get()->count();

        if($locations==4){
            $this->throwCustomException('You have reached your limits');
        }
        FavouriteLocation::create($created_params);

        return $this->respondSuccess(null,'address added successfully');



    }

    /**
    * List Favourite Locations
    *
    */
    public function FavouriteLocationList()
    {
        $user = auth()->user();

        $locations = FavouriteLocation::where('user_id',$user->id)->get();

        return $this->respondSuccess($locations,'address listed successfully');

    }

    /**
     * Delete Favourite Location
     *
     * @response
     * {
     * "success": true,
     * "message": "favorite location deleted successfully"
     * }
     * */
    public function deleteFavouriteLocation(FavouriteLocation $favourite_location){

        $favourite_location->delete();

        return $this->respondSuccess(null,'favorite location deleted successfully');


    }
}
