<?php

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Predis\Client as PredisClient;

class CropDataController extends Controller
{

    public function connectToRedis(): PredisClient
    {
        return new PredisClient([
            'scheme' => 'tcp',
            'host'   => env('REDIS_HOST',''),
            'port'   => env('REDIS_PORT',''),
        ]);
    }

    public function getCropsInfo()
    {

        try {
            $webApiResponse = Http::get("https://webapi.aclimate.org/api/Agronomic/true/json");
            $webApiResponse = json_decode($webApiResponse->body(), 1);
        }
        catch (\Exception $e)
        {
            Log::error("Exception thrown by web response", [$e]);
            return response()->json(["response" => "failed", "exception" => "CONNECTION ERROR"], 500);
        }

        $cropInfo = array();
        foreach ($webApiResponse as $singleCrop)
        {
            $transformedCultivars = array();
            foreach ($singleCrop["cultivars"] as $singleCultivar)
            {
                array_push($transformedCultivars, ["id" => $singleCultivar["id"],"name" => $singleCultivar["name"]]);
            }
            $cropTransformed = ["cp_id" => $singleCrop["cp_id"], "cp_name" => $singleCrop["cp_name"], "soils" => $singleCrop["soils"], "cultivars" => $transformedCultivars];
            array_push($cropInfo, $cropTransformed);
        }


        return response()->json(["result" => "ok", "cropInfo"=> $cropInfo], 200);
    }

    public function getYieldRanges($weatherStationID, $cropID)
    {
        Log::info("These are the variables ", [$weatherStationID, $cropID]);
        try {

            $webApiResponse = Http::get("https://webapi.aclimate.org/api/Geographic/61e59d829d5d2486e18d2ea9/json");
            $webApiResponse = json_decode($webApiResponse->body(), 1);
            //Log::info("This is the response", [$webApiResponse]);
        }
        catch (\Exception $e)
        {
            Log::error("Exception thrown by web response", [$e]);
            return response()->json(["response" => "failed", "exception" => "CONNECTION ERROR"], 500);
        }

        $rangesExtracted = [];
        foreach ($webApiResponse as $singleArea)
        {
            foreach ($singleArea["municipalities"] as $singleMunicipalities)
            {
                foreach ($singleMunicipalities["weather_stations"] as $singleWeatherStation)
                {
                    if(strcmp($singleWeatherStation["id"] , $weatherStationID) == 0)
                    {
                        Log::info("FOUND IT", [$singleWeatherStation["id"], $singleWeatherStation["name"]]);
                        $rangesExtracted = $singleWeatherStation["ranges"];
                    }
                }
            }
        }
        $labelsArray = array();
        foreach ($rangesExtracted as $singleRange)
        {
            if(strcmp($singleRange["crop_id"] , $cropID) == 0)
            {
                $label = [ "label" => $singleRange["label"], "lower" => $singleRange["lower"], "upper" => $singleRange["upper"]];
                array_push($labelsArray, $label);
            }
        }


        return response()->json(["result" => "ok", "ranges"=> $labelsArray], 200);

    }

    public function getYieldForecast($weatherStationID, $soilID, $cultivarID)
    {

        $redisClient = $this->connectToRedis();

        //Construct a redis key
        $redisKey = "EDACAP-".$weatherStationID."-".$soilID."-".$cultivarID;
        Log::info("About to get all you want", [$redisKey]);
        //$redisResult = $redisClient->keys('*');
        $redisYield = $redisClient->get($redisKey);
        return response()->json(["result" => "ok", "yield"=> json_decode($redisYield)], 200);

    }


    public function populateYieldForecast(Request $request)
    {

        $webApiResponse = Http::get("https://webapi.aclimate.org/api/Forecast/Log/2022/json");
        $webApiResponse = json_decode($webApiResponse->body(), 1);

        $yieldsCombined = array();
        foreach ($webApiResponse as $singleForecast)
        {
            try {
                Log::info("These are the variables ", [$singleForecast["id"], $request->weather_station_id]);
                $forecastResponse = Http::get("https://webapi.aclimate.org/api/Forecast/YieldPrevious/".$singleForecast["id"]."/".$request->weather_station_id."/json");
                $forecastResponse = json_decode($forecastResponse->body(), 1);
                //Log::info("This is the response", [$webApiResponse]);
            }
            catch (\Exception $e)
            {
                Log::error("Exception thrown by web response", [$e]);
                return response()->json(["response" => "failed", "exception" => "CONNECTION ERROR"], 500);
            }

            if(!empty($forecastResponse["yield"]))
            {
                Log::info("Soil and cultivar found");
                foreach ($forecastResponse["yield"][0]["yield"] as $singleYield)
                {
                    if( (strcmp($request->soil_id, $singleYield["soil"]) ==0) && (strcmp($request->cultivar_id, $singleYield["cultivar"])==0) )
                    {
                        Log::info("They were the same ");
                        foreach ($singleYield["data"] as $singleData)
                        {
                            if(strcmp("yield_0", $singleData["measure"]) == 0){
                                $data = [
                                    "avg" => $singleData["avg"],
                                    "min" => $singleData["min"],
                                    "max" => $singleData["max"]
                                ];
                                break;
                            }
                        }
                        //$yieldTransformed = ["cultivar" => $singleYield["cultivar"], "soil" => $singleYield["soil"],"date" => $singleYield["start"], "data" => $data];
                        $yieldTransformed = ["date" => $singleYield["start"], "data" => $data];
                        array_push($yieldsCombined, $yieldTransformed);
                    }

                }
            }


            //array_push($allForecasts, $forecastResponse);

        }

        $redisClient = $this->connectToRedis();

        //Construct a redis key
        $redisKey = "EDACAP-".$request->weather_station_id."-".$request->soil_id."-".$request->cultivar_id;
        Log::info("About to add all you want", [$redisKey]);

        $redisResponse = $redisClient->set($redisKey, json_encode($yieldsCombined));
        if (!$redisResponse)
        {
            return response()->json(["result" => "failed"], 500);
        }

        return response()->json(["response" => "ok", "redis_key" => $redisKey, "yield" => json_encode($yieldsCombined)]);

        //return response()->json(["response" => "ok", "web_api_response" => $allForecasts]);

    }

}
