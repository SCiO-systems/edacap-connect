<?php

namespace App\Http\Controllers;

use Aws\S3\S3Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Predis\Client as PredisClient;

class FetchGeoServerData
{

    public function connectToS3(): S3Client
    {
//             connect to s3 bucket
        return new S3Client([
            'credentials' => [
                'key'     =>  env('S3_KEY'),
                'secret'  => env('S3_SECRET'),
            ],
            'version' => 'latest',
            'region'  => 'eu-central-1',
        ]);
    }

    public function connectToRedis(): PredisClient
    {
        return new PredisClient([
            'scheme' => 'tcp',
            'host'   => env('REDIS_HOST',''),
            'port'   => env('REDIS_PORT',''),
        ]);
    }


//    trigger lambda function for fetching calculations for the specific area based on the requested file
    public function triggerLambdaRasterStatsCalc($filename, $area): \Illuminate\Http\Client\Response
    {
        return Http::post(env("LAMBDA_RASTER_STATS_CALC"), [
            'filename' => $filename,
            'area' => $area
        ]);
    }


//    Check validity of request
    public function checkRequest($geoJson, $adminLevelId, $adminLevel, $layers, $date)
    {
        $tmpGeoJson = (array) $geoJson;
        $tmpAdminLeveLId = (array) $adminLevelId;
        $tmpAdminLeveL = (array) $adminLevel;

        if ($date != null)
        {
            $tmpDate = (array) $date;

            if (((empty($tmpAdminLeveLId) == 1) && (empty($tmpGeoJson) == 1)
                    && (empty($tmpAdminLeveL) == 1) && (empty($tmpDate) == 1))
                || sizeof($layers) == 0) {
                return response()->json(["response" => ('BAD REQUEST')], 400);
            }
        }
        else
        {
            if (((empty($tmpAdminLeveLId) == 1) && (empty($tmpGeoJson) == 1) && (empty($tmpAdminLeveL) == 1))
                || sizeof($layers) == 0) {
                return response()->json(["response" => ('BAD REQUEST')], 400);
            }
        }
    }


//    function called when there are not any results for the requested layer and bbox/geojson/admin area
    private function noResultsResponse($layer, $layerSplit): \stdClass
    {
        $tempClass = new \stdClass();

        $tempClass->layer = $layer;
        $tempClass->layer_short_name = ucfirst(end($layerSplit));
        $tempClass->mean = -1;
        $tempClass->standard_deviation = -1;
        $tempClass->min = -1;
        $tempClass->max = -1;

        return $tempClass;
    }


//     function for calculating statistics of a tiff file.
//     Tiff files are fetched from geoserver and are processed through lambda rester calc function.
    public function layersStatisticsCalculation(Request $request): \Illuminate\Http\JsonResponse
    {
        $redisClient = $this->connectToRedis();

//        parse input request
        $layers = $request->input("layers");
        $geoJson = $request->input("geo_json");
        $adminLevelId = $request->input("admin_level_id");
        $adminLevel = $request->input("admin_level");

        $geoJsonArray = (array) $geoJson;
        $this->checkRequest($geoJson, $adminLevelId, $adminLevel, $layers, null);

        $statisticsArray = array();

//        for each input layer call geoserver for fetching the relevant geotiff
//        for each geotiff call lambda raster calculator and fetch statistics
        foreach ($layers as $layer) {

            $layerSplit = explode("_", $layer);

//            if there is a requested geojson
            if ($adminLevelId == ""){
                $fileName = $layer.".tif";

//                trigger lambda function for calculating statistics
                $statisticsObj = $this->triggerLambdaRasterStatsCalc($fileName, $geoJson);
                //Log::info("ΑΑΑΗΑΑΑΑΑΑΑ FOUND YOU", [$statisticsObj]);
                try {
                    $statisticsBody = json_decode($statisticsObj->body());
                    $responseBody = $statisticsBody->response;
                    //Log::info("This is the response body,MAYBE THE CULPRIT??", [$responseBody]);

//                check if response is an stdClass => response contains statistics
                    if (!is_object($responseBody)) {

//                if returned array is 0 -> no data found for specific geo json
                        if (strcmp($responseBody, "zero-size array to reduction operation maximum which has no identity")) {
                            $statisticsArray [] = $this->noResultsResponse($layer, $layerSplit);
                        }
                    }
                    //Log::info("This is the layer, THE CULPRIT", [$layer]);

                    $responseBody->layer = $layer;
//                  setting short name of layer
                    $responseBody->layer_short_name = ucfirst(end($layerSplit));

                    $statisticsArray [] = $responseBody;
                }
                catch (\Exception $e) {
                    return response()->json(["response" => json_decode($statisticsObj->body())->message], 500);
                }
            }
//            user gives a specific geojson id.
            else if (!empty($geoJsonArray)) {
//                example: ETH.1.1_1_level_2_seasonal_country_et_probabilistic_above: id+_+level_+layer
                $redisKey = $adminLevelId."_level_".$adminLevel."_".$layer;

                $value = json_decode($redisClient->get($redisKey));

                if ($value != null) {
                    $value->layer = $layer;
                    $value->layer_short_name = ucfirst(end($layerSplit));
                    $statisticsArray [] =  ($value);
                }
                else {
//                    return response()->json(["response" => "ok", "statistics" => $statisticsArray], 200);
                    $statisticsArray [] = $this->noResultsResponse($layer, $layerSplit);
                }
            }

        }

        return response()->json(["response" => "ok", "statistics" => $statisticsArray], 200);
    }



    //     function for calculating statistics of a tiff file.
//     Tiff files are fetched from geoserver and are processed through lambda rester calc function.
    public function subSeasonalLayersStatisticsCalculation(Request $request): \Illuminate\Http\JsonResponse
    {
        $redisClient = $this->connectToRedis();

//        parse input request
        $layers = $request->input("layers");
        $geoJson = $request->input("geo_json");
        $adminLevelId = $request->input("admin_level_id");
        $adminLevel = $request->input("admin_level");

//        requested format: 2022-01-1 (1, 2, 3, 4 for the number of week)
        $date = $request->input("date");


        $geoJsonArray = (array) $geoJson;
        $this->checkRequest($geoJson, $adminLevelId, $adminLevel, $layers, $date);

        $statisticsArray = array();

//        for each input layer call geoserver for fetching the relevant geotiff
//        for each geotiff call lambda raster calculator and fetch statistics
        foreach ($layers as $layer) {
//            print_r($layer);

            $layerSplit = explode("_", $layer);

//            if there is a requested geojson
            if ($adminLevelId == ""){
                $fileName = $layer."_".$date.".tif";

//                trigger lambda function for calculating statistics
                $statisticsObj = $this->triggerLambdaRasterStatsCalc($fileName, $geoJson);
                try {
                    $statisticsBody = json_decode($statisticsObj->body());
                    $responseBody = $statisticsBody->response;

//                check if response is an stdClass => response contains statistics
                    if (!is_object($responseBody)) {

//                if returned array is 0 -> no data found for specific geo json
                        if (strcmp($responseBody, "zero-size array to reduction operation maximum which has no identity")) {
                            $statisticsArray [] = $this->noResultsResponse($layer, $layerSplit);
                        }
                    }

                    $responseBody->layer = $layer;
//                  setting short name of layer
                    $responseBody->layer_short_name = ucfirst(end($layerSplit));

                    $statisticsArray [] = $responseBody;
                }
                catch (\Exception $e) {
                    return response()->json(["response" => json_decode($statisticsObj->body())->message], 500);
                }
            }
//            user gives a specific geojson id.
            else if (!empty($geoJsonArray)) {
//                example: ETH.1.1_1_level_2_seasonal_country_et_probabilistic_above: id+_+level_+layer
                $redisKey = $adminLevelId."_level_".$adminLevel."_".$layer."_".$date;

                $value = json_decode($redisClient->get($redisKey));

                if ($value != null) {
                    $value->layer = $layer;
                    $value->layer_short_name = ucfirst(end($layerSplit));
                    $statisticsArray [] =  ($value);
                }
                else {
//                    return response()->json(["response" => "ok", "statistics" => $statisticsArray], 200);
                    $statisticsArray [] = $this->noResultsResponse($layer, $layerSplit);
                }
            }

        }

        return response()->json(["response" => "ok", "statistics" => $statisticsArray], 200);
    }
}

