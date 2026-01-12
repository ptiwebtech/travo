<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Geocoder\Laravel\Facades\Geocoder;
use Illuminate\Http\Request;

class GeocoderController extends Controller
{
    /**
     * Reverse geocodes the given coordinates and returns the results as JSON.
     *
     * @param Request $request the HTTP request object
     *
     * @return \Illuminate\Http\Response the JSON response with the geocoded results
     */
    public function reverse(Request $request)
    {
        $query  = $request->or(['coordinates', 'query']);
        $single = $request->boolean('single');

        /** @var \Fleetbase\LaravelMysqlSpatial\Types\Point $coordinates */
        $coordinates = Utils::getPointFromCoordinates($query);

        // if not a valid point error
        if (!$coordinates instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point) {
            return response()->error('Invalid coordinates provided.');
        }

        // get results
        $results = Geocoder::reverse($coordinates->getLat(), $coordinates->getLng())->get();

        if ($results->count()) {
            if ($single) {
                $googleAddress = $results->first();

                return response()->json(Place::createFromGoogleAddress($googleAddress));
            }

            return response()->json(
                $results->map(
                    function ($googleAddress) {
                        return Place::createFromGoogleAddress($googleAddress);
                    }
                )
                    ->values()
                    ->toArray()
            );
        }

        return response()->json([]);
    }

    /**
     * Geocodes the given query and returns the results as JSON.
     *
     * @param Request $request the HTTP request object
     *
     * @return \Illuminate\Http\Response the JSON response with the geocoded results
     */
    public function geocode(Request $request)
    {
        $query  = $request->input('query');
        $single = $request->boolean('single');

        if (is_array($query)) {
            return $this->reverse($request);
        }

        // lookup
        $results = Geocoder::geocode($query)->get();

        if ($results->count()) {
            if ($single) {
                $googleAddress = $results->first();

                return response()->json(Place::createFromGoogleAddress($googleAddress));
            }

            return response()->json(
                $results->map(
                    function ($googleAddress) {
                        return Place::createFromGoogleAddress($googleAddress);
                    }
                )
                    ->values()
                    ->toArray()
            );
        }

        return response()->json([]);
    }

    // public function geocode(Request $request)
    // {
    //     $query  = $request->input('query');
    //     $single = $request->boolean('single', false); // default false

    //     if (is_array($query)) {
    //         return $this->reverse($request);
    //     }

    //     $apiKey = 'AIzaSyDGjeEtV0FnjiB_zrUuBgeA6d7L5JmFH2Q';

    //     $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
    //         'address' => $query,
    //         'key' => $apiKey,
    //     ]);

    //     $data = $response->json();
    //     dd($data);

    //     if (empty($data['results'])) {
    //         return response()->json([]);
    //     }

    //     $places = collect($data['results'])->map(function ($result) {
    //         $components = $result['address_components'] ?? [];

    //         $place = [
    //             'address' => $result['formatted_address'] ?? null,
    //             'street1' => null,
    //             'building' => null,
    //             'neighborhood' => null,
    //             'city' => null,
    //             'state' => null,
    //             'postal_code' => null,
    //             'country' => null,
    //             'country_name' => null,
    //             'location' => [
    //                 'type' => 'Point',
    //                 'coordinates' => [
    //                     $result['geometry']['location']['lng'] ?? null,
    //                     $result['geometry']['location']['lat'] ?? null
    //                 ]
    //             ]
    //         ];

    //         foreach ($components as $comp) {
    //             $types = $comp['types'];
    //             if (in_array('street_number', $types)) $place['building'] = $comp['long_name'];
    //             if (in_array('route', $types)) $place['street1'] = $comp['long_name'];
    //             if (in_array('neighborhood', $types)) $place['neighborhood'] = $comp['long_name'];
    //             if (in_array('locality', $types)) $place['city'] = $comp['long_name'];
    //             if (in_array('administrative_area_level_1', $types)) $place['state'] = $comp['long_name'];
    //             if (in_array('postal_code', $types)) $place['postal_code'] = $comp['long_name'];
    //             if (in_array('country', $types)) {
    //                 $place['country'] = $comp['short_name'];
    //                 $place['country_name'] = $comp['long_name'];
    //             }
    //         }

    //         return $place;
    //     });

    //     // Limit suggestions to first 5 results like autocomplete
    //     //$places = $places->take(5);

    //     if ($single) {
    //         return response()->json($places->first());
    //     }

    //     return response()->json($places->values()->toArray());
    // }

}
