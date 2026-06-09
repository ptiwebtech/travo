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
        // $limit = $request->input('limit', 15);
        
        // $routes = BusRoute::with(['vendor'])
        //     ->search($request->input('query'))
        //     ->latest()
        //     ->paginate($limit);  
        // // OR Better: Simple array return karein agar Resource simple hai
        // return response()->json([
        //     'bus_routes' => BusRouteResource::collection($routes),
        //     'meta' => [
        //         'total' => $routes->total(),
        //         'current_page' => $routes->currentPage(),
        //         // etc...
        //     ]
        // ]);

        $limit = $request->input('limit', 15);
        $query = BusRoute::with(['vendor'])
            ->where('company_uuid', session('company')) // Security: apni company ke routes
            ->where('status', 'active');

        // 📍 Departure City Filter
        if ($request->has('departure_city')) {
            $query->where('departure_city', $request->input('departure_city'));
        }

        // 📍 Arrival City Filter
        if ($request->has('arrival_city')) {
            $query->where('arrival_city', $request->input('arrival_city'));
        }

        // Existing search logic (if any)
        if ($request->has('query')) {
            $query->search($request->input('query'));
        }

        $routes = $query->latest()->paginate($limit);  

        return response()->json([
            'bus_routes' => BusRouteResource::collection($routes),
            'meta' => [
                'total'        => $routes->total(),
                'current_page' => $routes->currentPage(),
                'last_page'    => $routes->lastPage(),
                'per_page'     => $routes->perPage(),
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
                'departure_address'       => data_get($input, 'departure_address'), // ✅ ADD
                'arrival_address'         => data_get($input, 'arrival_address'),
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
        // 1. Key check: Payload se 'busRoute' ya 'bus_route' dono handle kar lega
        $input = $request->input('busRoute') ?? $request->input('bus_route');
        
        $route = BusRoute::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();

        // 2. Data Update: Direct keys use karein jo payload mein aa rahi hain
        $route->update([
            'vendor_uuid'             => data_get($input, 'vendor_uuid', $route->vendor_uuid),
            'departure_location_uuid' => data_get($input, 'departure_location_uuid', $route->departure_location_uuid),
            'arrival_location_uuid'   => data_get($input, 'arrival_location_uuid', $route->arrival_location_uuid),
            'departure_city'          => data_get($input, 'departure_city', $route->departure_city),
            'arrival_city'            => data_get($input, 'arrival_city', $route->arrival_city),
            'departure_address'       => data_get($input, 'departure_address', $route->departure_address),
            'arrival_address'         => data_get($input, 'arrival_address', $route->arrival_address),
            'travel_type'             => data_get($input, 'travel_type', $route->travel_type),
            'price'                   => data_get($input, 'price', $route->price),
            'departure_time'          => data_get($input, 'departure_time', $route->departure_time),
            'operating_days'          => data_get($input, 'operating_days', $route->operating_days),
            'status'                  => data_get($input, 'status', $route->status),
        ]);

        // fresh() call karein taaki resource mein updated data return ho
        return new BusRouteResource($route->fresh());
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