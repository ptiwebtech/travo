<?php

namespace App\Http\Controllers;

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController as BaseOrderController;
use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\FleetOps\Events\OrderDispatchFailed;
use Fleetbase\FleetOps\Events\OrderReady;
use Fleetbase\FleetOps\Events\OrderStarted;
use Fleetbase\FleetOps\Exports\OrderExport;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Requests\BulkDispatchRequest;
use Fleetbase\FleetOps\Http\Requests\CancelOrderRequest;
use Fleetbase\FleetOps\Http\Requests\Internal\CreateOrderRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Imports\OrdersImport;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\Internal\BulkActionRequest;
use Fleetbase\Http\Requests\Internal\BulkDeleteRequest;
use Fleetbase\Models\CustomFieldValue;
use Fleetbase\Models\File;
use Fleetbase\Models\Type;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class CustomOrderController extends BaseOrderController
{
    public function query(Request $request)
    {
        $user = $request->user();

        $results = Order::queryWithRequest($request, function (&$query, $request) use ($user) {
            $query->where('company_uuid', $user->company_uuid);
        });

        // check what’s coming before transformation
        dd($results);

        return OrderResource::collection($results);
    }
}
