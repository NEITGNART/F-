<?php

namespace App\Http\Controllers\Api\V1\Driver;

use Illuminate\Http\Request;
use App\Models\Admin\DriverDocument;
use App\Models\Admin\DriverNeededDocument;
use App\Http\Controllers\Api\V1\BaseController;
use App\Base\Constants\Masters\DriverDocumentStatus;
use App\Transformers\DriverNeededDocumentTransformer;
use App\Transformers\Driver\DriverDocumentTransformer;
use App\Http\Requests\Driver\DriverDocumentUploadRequest;
use App\Base\Services\ImageUploader\ImageUploaderContract;

/**
 * @group Driver Document Management
 *
 * APIs for DriverNeededDocument's
 */
class DriverDocumentController extends BaseController
{
    /**
    * ImageUploader instance.
    *
    * @var ImageUploaderContract
    */
    protected $imageUploader;

    /**
     * DriverDocumentController constructor.
     *
     * @param ImageUploaderContract $imageUploader
     */
    public function __construct(ImageUploaderContract $imageUploader)
    {
        $this->imageUploader = $imageUploader;
    }
    /**
    * Get All documents needed to be uploaded
    * @responseFile responses/driver/ListAllDocumentNeededWithUploadedDocuments.json
    */
    public function index()
    {
        $driver_id = auth()->user()->driver->id;

        $driverneededdocumentQuery  = DriverNeededDocument::active()->get();

        // $driverneededdocument = filter($driverneededdocumentQuery, new DriverNeededDocumentTransformer)->get();

        $driverneededdocument =  fractal($driverneededdocumentQuery, new DriverNeededDocumentTransformer);

        // @TODO needs to be changed after document upload api completion
        foreach (DriverNeededDocument::active()->get() as $key => $needed_document) {
            if (DriverDocument::where('driver_id', $driver_id)->where('document_id', $needed_document->id)->exists()) {
                $uploaded_document = true;
            } else {
                $uploaded_document = false;
            }
        }

        $formated_document = $this->formatResponseData($driverneededdocument);

        return response()->json(['success'=>true,"message"=>'success','enable_submit_button'=>$uploaded_document,'data'=>$formated_document['data']]);
    }

    /**
    * Upload Driver's Document
    * @bodyParam document_id integer required id of the documents needed uploaded
    * @bodyParam identify_number string optional identify number of the document, required sometimes depends on the document
    * @bodyParam expiry_date date required expiry date of the document, the date should be in the format "date_format:Y-m-d H:i:s", eg:2020-08-13 00:00:00
    * @bodyParam document image required document file provided by user
    * @response {
    "success": true,
    "message": "success"
    }
    */
    public function uploadDocuments(DriverDocumentUploadRequest $request)
    {
        $created_params = $request->only(['document_id','identify_number','expiry_date']);

        $created_params['document_status'] =DriverDocumentStatus::UPLOADED_AND_WAITING_FOR_APPROVAL;

        $document_exists = auth()->user()->driver->driverDocument()->where('document_id', $request->document_id)->exists();

        if ($document_exists) {
            $created_params['document_status'] =DriverDocumentStatus::REUPLOADED_AND_WAITING_FOR_APPROVAL;
        }
        $driver_id = auth()->user()->driver->id;

        $created_params['driver_id'] = $driver_id;

        if ($uploadedFile = $this->getValidatedUpload('document', $request)) {
            $created_params['image'] = $this->imageUploader->file($uploadedFile)
                ->saveDriverDocument($driver_id);
        }
        // Check if document exists
        $driver_documents = DriverDocument::where('driver_id', $driver_id)->where('document_id', $request->input('document_id'))->first();

        if ($driver_documents) {
            DriverDocument::where('driver_id', $driver_id)->where('document_id', $request->input('document_id'))->update($created_params);
        } else {
            DriverDocument::create($created_params);
        }

        $driver_documents = DriverDocument::where('driver_id', $driver_id)->get();

        // $result = fractal($driver_documents, new DriverDocumentTransformer);

        return $this->respondSuccess();
    }

    /**
    * List All Uploaded Documents
    * @responseFile responses/driver/listAllUploadedDocuments.json
    */
    public function listUploadedDocuments()
    {
        $driver_documents = DriverDocument::where('driver_id', auth()->user()->driver->id)->get();

        $result = fractal($driver_documents, new DriverDocumentTransformer);

        return $this->respondSuccess($result);
    }
}
