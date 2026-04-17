<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BusRoute;
use App\Http\Resources\v1\BusRoute as BusRouteResource;
use Illuminate\Support\Facades\DB;

class BusRouteController extends Controller
{
    /**
     * Query bus routes with basic filtering.
     */
    public function query(Request $request)
    {
        $limit = $request->input('limit', 15);
        
        $routes = BusRoute::where('company_uuid', session('company'))
            ->with(['vendor'])
            ->search($request->input('query'))
            ->latest()
            ->paginate($limit);  
        // OR Better: Simple array return karein agar Resource simple hai
        return response()->json([
            'bus_routes' => BusRouteResource::collection($routes),
            'meta' => [
                'total' => $routes->total(),
                'current_page' => $routes->currentPage(),
                // etc...
            ]
        ]);
    }

    /**
     * Create a new bus route.
     */
    public function create(Request $request)
    {
        //dd($request->all());
        $input = $request->input('busRoute');
    
        return DB::transaction(function () use ($input) {
            $route = BusRoute::create([
                'company_uuid'            => session('company'),
                // CHANGE: Direct 'vendor_uuid' read karein, 'vendor.uuid' nahi
                'vendor_uuid'             => data_get($input, 'vendor_uuid'), 
                'country'                 => data_get($input, 'country', 'Nigeria'),
                'travel_type'             => data_get($input, 'travel_type', 'Bus'),
                'departure_city'          => data_get($input, 'departure_city'),
                'arrival_city'            => data_get($input, 'arrival_city'),
                // CHANGE: Same for locations
                'departure_location_uuid' => data_get($input, 'departure_location_uuid'),
                'arrival_location_uuid'   => data_get($input, 'arrival_location_uuid'),
                'price'                   => data_get($input, 'price', 0),
                'route_class'             => data_get($input, 'route_class', 'Economy'),
                'departure_time'          => data_get($input, 'departure_time'),
                'operating_days'          => data_get($input, 'operating_days', []),
                'custom_schedule'         => data_get($input, 'custom_schedule', []),
                'status'                  => 'active',
            ]);
    
            return new BusRouteResource($route);
        });
    }

    /**
     * View a single route by UUID or Public ID.
     */
    public function find($id)
    {
        $route = BusRoute::where('company_uuid', session('company'))
            ->where(function($q) use ($id) {
                $q->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        return new BusRouteResource($route);
    }

    /**
     * Update an existing route.
     */
    public function update(Request $request, $id)
    {
        $input = $request->input('bus_route');
        $route = BusRoute::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();

        $route->update([
            'vendor_uuid'             => data_get($input, 'vendor.uuid', $route->vendor_uuid),
            'departure_location_uuid' => data_get($input, 'departure_location.uuid', $route->departure_location_uuid),
            'arrival_location_uuid'   => data_get($input, 'arrival_location.uuid', $route->arrival_location_uuid),
            'price'                   => data_get($input, 'price', $route->price),
            'departure_time'          => data_get($input, 'departure_time', $route->departure_time),
            'operating_days'          => data_get($input, 'operating_days', $route->operating_days),
            'status'                  => data_get($input, 'status', $route->status),
        ]);

        return new BusRouteResource($route);
    }

    /**
     * Delete a route (Soft Delete).
     */
    public function delete($id)
    {
        $route = BusRoute::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();
        $route->delete();

        return response()->json(['status' => 'success']);
    }
}