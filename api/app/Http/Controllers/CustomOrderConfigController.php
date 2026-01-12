<?php

namespace App\Http\Controllers;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderConfigController as BaseOrderConfigController;

use Fleetbase\FleetOps\Models\OrderConfig;
use Illuminate\Http\Request;

class CustomOrderConfigController extends BaseOrderConfigController
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user && $user->type === 'admin') {
            $orderConfigs = OrderConfig::where('company_uuid', $user->company_uuid)->get();
            return response()->json(['order_configs' => $orderConfigs]);
        }

        $allowedUuids = [
            '96f3909e-081c-4609-a240-9c139a012771',
            '8283f663-9320-482f-94d8-1136a8b1d08e',
            '40146ff3-6980-4711-b33c-308110eb6b4f',
            'ba5bf36d-f2d7-46f5-a43f-b4895dd47aaf',
            '1d9af4c6-979f-4cd9-8583-a8b7ae2c7281',
        ];

        $orderConfigs = OrderConfig::whereIn('uuid', $allowedUuids)->get();
        return response()->json(['order_configs' => $orderConfigs]);
    }
}
