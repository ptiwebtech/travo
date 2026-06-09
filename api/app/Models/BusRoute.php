<?php

namespace App\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Fleetbase\FleetOps\Models\Vendor; // Path to Fleetbase Vendor
use Fleetbase\FleetOps\Models\Place;  // Path to Fleetbase Place

class BusRoute extends Model
{
    use HasUuid;
    use HasPublicId;
    use Searchable;

    protected $table = 'bus_routes';
    protected $publicIdType = 'route';

    protected $fillable = [
        'company_uuid', 'vendor_uuid', 'country', 'travel_type',
        'departure_city', 'arrival_city', 'departure_location_uuid',
        'arrival_location_uuid', 'price', 'route_class',
        'departure_time', 'operating_days', 'custom_schedule', 'status','departure_address','arrival_address',
    ];

    protected $casts = [
        'operating_days' => 'array',
        'custom_schedule' => 'array',
        'price' => 'float',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_uuid', 'uuid');
    }

    public function departureLocation(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'departure_location_uuid', 'uuid');
    }

    public function arrivalLocation(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'arrival_location_uuid', 'uuid');
    }
}