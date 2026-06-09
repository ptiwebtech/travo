<?php

namespace App\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdditionalService extends Model
{
    use HasUuid, HasPublicId, Searchable, SoftDeletes;

    protected $table = 'additional_services';
    protected $publicIdType = 'service';

    protected $fillable = [
        'company_uuid',
        'order_config_uuid',
        'name',
        'description',
        'info_text',
        'price',
        'add_to_quote',
        'status',
    ];

    protected $casts = [
        'price'        => 'float',
        'add_to_quote' => 'boolean',
    ];

    public function orderConfig(): BelongsTo
    {
        return $this->belongsTo(\Fleetbase\FleetOps\Models\OrderConfig::class, 'order_config_uuid', 'uuid');
    }
}