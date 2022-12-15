<?php

namespace App\Http\Controllers;

use Aws\S3\S3Client;
use Gaufrette\Adapter\AwsS3 as AwsS3Adapter;
use Gaufrette\Filesystem;
use Predis\Client as PredisClient;


class FetchLayersController
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
            'region'  => 'us-east-2',
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


//    downloading csv file for layer's information
    public function downloadLayersInfo(): array
    {
        $s3client = $this->connectToS3();
        $adapter = new AwsS3Adapter($s3client, 'scio-edacap-dev');
        $filesystem = new Filesystem($adapter);
        $file = $filesystem->get('layers/layers_csv.csv');

        $csv = ($file->getContent());
        return array_map("str_getcsv", explode("\n", $csv));
    }


    /*
     * Check if layer exists in list.
     * If yes -> return its position
     * In not -> return -1
     * */
    public function layerExists($layer, $layersArray): int
    {

        for ($i = 0; $i < count($layersArray); $i++)
        {
            try
            {
                if ($layersArray[$i]->category == $layer)
                {
                    return $i;
                }
            }
            catch (\Exception $exception)
            {
            }
        }
        return -1;
    }


//    Layer's information initialization
    public function layersJsonPopulation()
    {
        $layersInfo = $this->downloadLayersInfo();
        $layersArray [] = array();

        for ($i = 1; $i < count($layersInfo) - 1; $i++)
        {
//            if category does not exist in returned list
            $layerPos = $this->layerExists($layersInfo[$i][0], $layersArray);

//            layer does not exist in list
            if ($layerPos == -1)
            {
                $layerObj = new \stdClass();

                $layerObj->category = $layersInfo[$i][0];
                $layerObj->geoserver_domain = env("GEOSERVER_DOMAIN");
                $layerObj->workspace = $layersInfo[$i][4];

                $layersData = new \stdClass();
                $layersData->name = $layersInfo[$i][1];
                $layersData->variable = $layersInfo[$i][3];

                $layerObj->layers[] = $layersData;
                $layersArray [] = $layerObj;
            }
//            else if category exists
            else
            {
//                add in array
                $layersData = new \stdClass();
                $layersData->name = $layersInfo[$i][1];
                $layersData->variable = $layersInfo[$i][3];

                $layersArray[$layerPos]->layers [] = $layersData;
            }
        }

//        remove empty object from list
        if (count($layersArray) > 1)
        {
            array_shift($layersArray);
        }


        return $layersArray;
    }

    public function idExistsInRedis($redisKey): string
    {
        $redisClient = $this->connectToRedis();
        $layersInfo = $redisClient->get($redisKey);

        if ($layersInfo == null)
        {
            return "";
        }
        else
        {
            return $layersInfo;
        }
    }


//    Fetch details about layers.
    public function fetchGeoserverDetails(): \Illuminate\Http\JsonResponse
    {
        try
        {
            $redisClient = $this->connectToRedis();
        }
        catch (\Exception $e)
        {
            return response()->json(["response" => "fail", "exception" => "CONNECTION ERROR"], 500);
        }

        $layersInfo = $this->idExistsInRedis('tabs_details');
        if ($layersInfo == "")
        {

            $redisResponse = $redisClient->set(('tabs_details'), json_encode($this->layersJsonPopulation()));

            if (!$redisResponse)
            {
                return response()->json(["response" => "fail"], 500);
            }
            else
            {
                return response()->json(["response" => "ok", "tabs"=>$this->layersJsonPopulation()], 200);
            }
        }
        else
        {
            return response()->json(["response" => "ok", "tabs" => json_decode($layersInfo)], 200);
        }
    }

}
