<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AdditionalService;
use App\Http\Resources\v1\AdditionalService as AdditionalServiceResource;
use Illuminate\Support\Facades\DB;

class AdditionalServiceController extends Controller
{
    public function query(Request $request)
    {
        $limit = $request->input('limit', 15);
    
        // $query = AdditionalService::where('company_uuid', session('company'));
        $query = AdditionalService::query();
    
        if ($request->filled('order_config_uuid')) {
            $query->where('order_config_uuid', $request->input('order_config_uuid'));
        }
    
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
    
        if ($request->filled('query')) {
            $query->search($request->input('query'));
        }
    
        $services = $query->latest()->paginate($limit);
    
        return response()->json([
            'additional_services' => AdditionalServiceResource::collection($services),
            'meta' => [
                'total'        => $services->total(),
                'current_page' => $services->currentPage(),
                'last_page'    => $services->lastPage(),
                'per_page'     => $services->perPage(),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $input = $request->input('additionalService');

        return DB::transaction(function () use ($input) {
            $service = AdditionalService::create([
                'company_uuid'      => session('company'),
                'order_config_uuid' => data_get($input, 'order_config_uuid'),
                'name'              => data_get($input, 'name'),
                'description'       => data_get($input, 'description'),
                'info_text'         => data_get($input, 'info_text'),
                'price'             => data_get($input, 'price', 0),
                'add_to_quote'      => data_get($input, 'add_to_quote', true),
                'status'            => data_get($input, 'status', 'active'),
            ]);

            return new AdditionalServiceResource($service);
        });
    }

    public function find($id)
    {
        $service = AdditionalService::where('company_uuid', session('company'))
            ->where(function ($q) use ($id) {
                $q->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        return new AdditionalServiceResource($service);
    }

    public function update(Request $request, $id)
    {
        $input = $request->input('additionalService')
                 ?? $request->input('additional_service');

        $service = AdditionalService::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $service->update([
            'order_config_uuid' => data_get($input, 'order_config_uuid', $service->order_config_uuid),
            'name'              => data_get($input, 'name',              $service->name),
            'description'       => data_get($input, 'description',       $service->description),
            'info_text'         => data_get($input, 'info_text',         $service->info_text),
            'price'             => data_get($input, 'price',             $service->price),
            'add_to_quote'      => data_get($input, 'add_to_quote',      $service->add_to_quote),
            'status'            => data_get($input, 'status',            $service->status),
        ]);

        return new AdditionalServiceResource($service->fresh());
    }

    public function delete($id)
    {
        $service = AdditionalService::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $service->delete();

        return response()->json(['status' => 'success']);
    }
}