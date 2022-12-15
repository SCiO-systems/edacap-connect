<?php

namespace App\Http\Controllers;

use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Predis\Client as PredisClient;

class GadmSearchController
{

//    Fetch geojsons from dev2 api call
    public function searchGadm($lat, $lon, $resolutionAnalysis, $adminLevel): \Illuminate\Http\Client\Response
    {
        return Http::post(env("ES_GADM_COORDINATES_API"), [
            'lat' => $lat,
            'lon' => $lon,
            'resolution_analysis' => $resolutionAnalysis,
            'admin_level' => $adminLevel,
        ]);
    }

    public function fetchGeoJson(Request $request): \Illuminate\Http\JsonResponse
    {

        try {
            $geoJson = $this->searchGadm( $request->input('latitude'),  $request->input('longitude'),
                                    $request->input('resolution_analysis'),  $request->input('admin_level'));

            $countryLowResBody = json_decode($geoJson->body());

            return response()->json(["response" => "ok", "geo_json" => ($countryLowResBody->response)], 200);
        }
        catch (\Exception $exception)
        {
            return response()->json(["response" => "fail"], 500);

        }
    }

}
