<?php

namespace App\Http\Controllers\Web\Admin;

use App\Base\Constants\Auth\Role as RoleSlug;
use App\Base\Filters\Master\CommonMasterFilter;
use App\Base\Libraries\QueryFilter\QueryFilterContract;
use App\Base\Services\ImageUploader\ImageUploaderContract;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\BaseController;
use App\Http\Requests\Admin\Fleet\FleetStoreRequest;
use App\Models\Admin\AreaType;
use App\Models\Admin\Fleet;
use App\Models\Admin\VehicleType;
use App\Models\Master\CarMake;
use App\Models\Master\CarModel;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class FleetController extends BaseController
{
    
    /**
     * The Fleet model instance.
     *
     * @var \App\Models\Admin\Fleet
     */
    protected $fleet;

    /**
     * FleetController constructor.
     *
     * @param \App\Models\Admin\Fleet $fleet
     */
    public function __construct(Fleet $fleet, ImageUploaderContract $imageUploader)
    {
        $this->fleet = $fleet;
        $this->imageUploader = $imageUploader;
    }

    public function index()
    {
        $page = trans('pages_names.fleets');
        $main_menu = 'manage_fleet';
        $sub_menu = '';

        return view('admin.fleets.index', compact('page', 'main_menu', 'sub_menu'))->render();
    }

    public function fetch(QueryFilterContract $queryFilter)
    {
        $query = Fleet::query();
        $results = $queryFilter->builder($query)->customFilter(new CommonMasterFilter)->paginate();

        return view('admin.fleets._fleets', compact('results'))->render();
    }

    public function create()
    {
        $page = trans('pages_names.add_fleet');

        $main_menu = 'manage_fleet';
        $sub_menu = '';

        $carmake = CarMake::active()->get();
         $carmodel = CarModel::active()->get();
        // $types = AreaType::whereActive(true);

        // if(auth()->user()->hasRole(RoleSlug::OWNER)){
        //     $types = $types->whereServiceLocationId(auth()->user()->owner->service_location_id);
        // }
        // $types = $types->get();
        $types = VehicleType::active()->get();

        return view('admin.fleets.create', compact('page', 'main_menu', 'sub_menu','carmake','types','carmodel'));
    }

    public function store(FleetStoreRequest $request)
    {
        // dd($request->all());
        $authUser = auth()->user();
        $vehicleToRegister = $authUser->owner->no_of_vehicles;

        if($vehicleToRegister <= $this->fleet->whereOwnerId(auth()->user()->id)->count()){
            return back()->withErrors('You have already reached the fleet limit,For details contact Admin')->withInput($request->all());
        }

        $created_params = $request->only(['brand','model','license_number','permission_number']);
        $created_params['vehicle_type'] = $request->type;
        $created_params['owner_id'] = $authUser->id;

        // if($request->has('class_one'))
        //     $created_params['class_one'] = true;

        // if($request->has('class_two'))
        //     $created_params['class_two'] = true;

        $fleet = $this->fleet->create($created_params);

        $qrCode = $this->generateQRCode($fleet);
        
        $fleet->update([
            'fleet_id' => $qrCode['code'],
            'qr_image' => $qrCode['qrcode'],
        ]);

        if ($uploadedFile = $this->getValidatedUpload('registration_certificate', $request)) {
            $fleet_document['registration_certificate'] = $this->imageUploader->file($uploadedFile)
                ->saveFleetRegistrationCertificateImage();
        }

        if ($uploadedFile = $this->getValidatedUpload('vehicle_back_side', $request)) {
            $fleet_document['vehicle_back_side'] = $this->imageUploader->file($uploadedFile)
                ->saveFleetBackSideImage();
        }

        if($uploadedFile){
            foreach($fleet_document as $key => $image){
                $fleet->fleetDocument()->create(['name' => $key,'image' => $image]);
            }
        }

        $message = trans('succes_messages.fleet_added_succesfully');

        return redirect('fleets')->with('success', $message);
    }

    public function getById(Fleet $fleet)
    {
        $page = trans('pages_names.edit_fleet');
        $item = $fleet;

        $main_menu = 'manage_fleet';
        $sub_menu = '';

        $carmake = CarMake::active()->get();
         $carmodel = CarModel::active()->get();
        // $types = AreaType::whereActive(true)->whereServiceLocationId($fleet->user->owner->service_location_id)->get();
        $types = VehicleType::active()->get();

        return view('admin.fleets.update', compact('page', 'item', 'main_menu', 'sub_menu','types','carmake','carmodel'));
    }


    public function update(FleetStoreRequest $request,Fleet $fleet)
    {
        $updated_params = $request->only(['brand','model','license_number','permission_number']);
        $updated_params['vehicle_type'] = $request->type;

        // if($request->has('class_one'))
        //     $updated_params['class_one'] = true;

        // if($request->has('class_two'))
        //     $updated_params['class_two'] = true;

        $fleet->update($updated_params);
   
        if ($uploadedFile = $this->getValidatedUpload('registration_certificate', $request)) {
            $fleet_document['registration_certificate'] = $this->imageUploader->file($uploadedFile)
                ->saveFleetRegistrationCertificateImage();
        }

        if ($uploadedFile = $this->getValidatedUpload('vehicle_back_side', $request)) {
            $fleet_document['vehicle_back_side'] = $this->imageUploader->file($uploadedFile)
                ->saveFleetBackSideImage();
        }

        if($uploadedFile){
            foreach($fleet_document as $key => $image){
                $fleet->fleetDocument()->update(['name' => $key,'image' => $image]);
            }
        }

        $message = trans('succes_messages.fleet_updated_succesfully');

        return redirect('fleets')->with('success', $message);
    }


    public function toggleStatus(Fleet $fleet)
    {
        $status = $fleet->active == 1 ? 0 : 1;

        $fleet->update([
            'active' => $status
        ]);

        $message = trans('succes_messages.fleet_status_changed_succesfully');

        return redirect('fleets')->with('success', $message);
    }

    public function toggleApprove(Fleet $fleet)
    {
        $status = $fleet->approve == 1 ? 0 : 1;
        $fleet->update([
            'approve' => $status
        ]);

        $message = trans('succes_messages.fleet_approval_status_changed_succesfully');
        return redirect('fleets')->with('success', $message);
    }

    public function updateFleetDeclineReason(Request $request){
        Fleet::whereId($request->id)->update([
            'reason' => $request->reason
        ]);

        return 'success';
    }

    public function delete(Fleet $fleet)
    {
        $fleet->delete();

        $message = trans('succes_messages.fleet_deleted_succesfully');

        return redirect('fleets')->with('success', $message);
    }

    public function generateQRCode($fleet){
        do {
            $code = str_random(30);
        } while ($this->fleet->whereFleetId($code)->exists());

        $qr_code_image_name = 'qrcode-'.$fleet->id.'.svg';

        $qr_code = QrCode::size(500)
            // ->format('svg')
            ->generate($code, storage_path('app/public/uploads/qr-codes/'.$qr_code_image_name));

        return ['code'=>$code,'qrcode'=>$qr_code_image_name];
    }
}
