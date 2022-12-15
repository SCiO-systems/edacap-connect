<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Predis\Client as PredisClient;

class KebeleController extends Controller
{

    public function connectToRedis(): PredisClient
    {
        return new PredisClient([
            'scheme' => 'tcp',
            'host'   => env('REDIS_HOST',''),
            'port'   => env('REDIS_PORT',''),
        ]);
    }

    public function processKebeleGeojson()
    {
        $redisClient = $this->connectToRedis();
        //Log::info("these are the redis keys", [$redisClient->keys('*')]);

        if(file_exists('C:\Users\User\Documents\Usefull_Files_Projectwise\Edacap\kebele_part13.geojson'))
        {
            Log::info("ela ti");
            $filename = 'C:\Users\User\Documents\Usefull_Files_Projectwise\Edacap\kebele_part13.geojson';
            try {
                $data = file_get_contents($filename); //data read from json file
                $kebeleArray = json_decode($data);
                Log::info("GOT THIS FAR");
            }catch (\Exception $e){
                Log::info("Something went wrong", [$e]);
                return response()->json(["result" => "failed", "errorMessage" => $e->getMessage()], 500);
            }

            Log::info("The data were extracted from file");
            foreach ($kebeleArray as $singleKebele)
            {
                Log::info("Eit", [$singleKebele->kebele]);
                $redisResponse = $redisClient->set(($singleKebele->kebele), json_encode($singleKebele->geojson));
                if (!$redisResponse)
                {
                    return response()->json(["result" => "failed"], 500);
                }
            }


            /*Log::info("About to add", [$kebeleArray[0]]);
            $redisResponse = $redisClient->set(($kebeleArray[0]->kebele), json_encode($kebeleArray[0]->geojson));
            if (!$redisResponse)
            {
                return response()->json(["result" => "failed"], 500);
            }*/


            return response()->json(["result" => "ok", "kebele_used"=> $filename], 200);

        }
        else
        {
            return response()->json(["result" => "failed","errorMessage" => "Didn't find or couldn't open the file"], 200);
        }

    }

    public function fetchKebeleGeojson(Request $request)
    {
        $redisClient = $this->connectToRedis();
        Log::info("About to get all you want");
        $geojson = $redisClient->get($request->kebele_name);
        //$deleteResult = $redisClient->del($request->kebele_name);
        return response()->json(["result" => "ok", "geojson"=> json_decode($geojson)], 200);
        //, "delete" => $deleteResult
    }

}
