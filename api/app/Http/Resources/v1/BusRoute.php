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
            'id'                      => $this->uuid,
            'uuid'                    => $this->uuid,
            'public_id'               => $this->public_id,
            'company_uuid'            => $this->company_uuid,
            'vendor_uuid'             => $this->vendor_uuid,           // ✅ sirf UUID
            'vendor_name'             => $this->vendor?->name,
            'vendor_logo'             => $this->vendor?->logo_url,
            'departure_location_uuid' => $this->departure_location_uuid, // ✅ sirf UUID
            'arrival_location_uuid'   => $this->arrival_location_uuid,   // ✅ sirf UUID
            'country'                 => $this->country,
            'travel_type'             => $this->travel_type,
            'departure_city'          => $this->departure_city,
            'arrival_city'            => $this->arrival_city,
            'departure_address'       => $this->departure_address,
            'arrival_address'         => $this->arrival_address,
            'price'                   => (float) $this->price,
            'route_class'             => $this->route_class,
            'departure_time'          => $this->departure_time,
            'operating_days'          => $this->operating_days ?? [],
            'custom_schedule'         => $this->custom_schedule ?? [],
            'status'                  => $this->status,
            'created_at'              => $this->created_at,
            'updated_at'              => $this->updated_at,
        ];
    }
}