<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Resources\Json\JsonResource;

class AdditionalService extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->uuid,
            'uuid'              => $this->uuid,
            'public_id'         => $this->public_id,
            'company_uuid'      => $this->company_uuid,
            'order_config_uuid' => $this->order_config_uuid,
            'order_type_name'   => $this->orderConfig?->name,
            'name'              => $this->name,
            'description'       => $this->description,
            'info_text'         => $this->info_text,
            'price'             => (float) $this->price,
            'add_to_quote'      => (bool) $this->add_to_quote,
            'status'            => $this->status,
            'created_at'        => $this->created_at?->toISOString(),
            'updated_at'        => $this->updated_at?->toISOString(),
        ];
    }
}