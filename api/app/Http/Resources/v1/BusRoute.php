<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Resources\Json\JsonResource;
use Fleetbase\FleetOps\Http\Resources\v1\Vendor as VendorResource;
use Fleetbase\FleetOps\Http\Resources\v1\Place as PlaceResource;

class BusRoute extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                 => $this->public_id,
            'uuid'               => $this->uuid,
            'country'            => $this->country,
            'travel_type'        => $this->travel_type,
            'departure_city'     => $this->departure_city,
            'arrival_city'       => $this->arrival_city,
            'price'              => (float) $this->price, // Type casting safe rehti hai
            'route_class'        => $this->route_class,
            'departure_time'     => $this->departure_time,
            'operating_days'     => $this->operating_days ?? [],
            'status'             => $this->status,
            
            // Fix: Sirf tabhi bhejien jab relationship exist karti ho
            'vendor'             => $this->when($this->vendor_uuid, new VendorResource($this->vendor)),
            'departure_location' => $this->when($this->departure_location_uuid, new PlaceResource($this->departureLocation)),
            'arrival_location'   => $this->when($this->arrival_location_uuid, new PlaceResource($this->arrivalLocation)),
            
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }
}