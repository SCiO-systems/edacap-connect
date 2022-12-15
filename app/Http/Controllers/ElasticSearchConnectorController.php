<?php

namespace App\Http\Controllers;

use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\AuthenticationException;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use JetBrains\PhpStorm\ArrayShape;
use Illuminate\Http\Request;
use Predis\Client as PredisClient;

class ElasticSearchConnectorController extends Controller
{

//    function for connecting to Redis
    public function connectToRedis(): PredisClient
    {
        return new PredisClient([
            'scheme' => 'tcp',
            'host'   => env('REDIS_HOST',''),
            'port'   => env('REDIS_PORT',''),
        ]);
    }

//    Function for connecting to ES
    /**
     * @throws AuthenticationException
     */
    public function connectToElasticSearch(): \Elastic\Elasticsearch\Client
    {
        return ClientBuilder::create()
            ->setHosts([env('ES_HOST_PORT', '')])
            ->build();
    }


//    Query builder for specific bounding boxes
    #[ArrayShape(['index' => "mixed", 'type' => "string", 'body' => "\array[][][][][][]", 'from' => "", 'size' => ""])]
    public function bboxQueryBuilder($bbox, $from, $size): array
    {

//        create query
        $query = [
            'bool' => [
                'filter' => [
                    'geo_bounding_box' => [
                        'coordinates' => [
                            'bottom_left' => [
                                'lat'=>  $bbox['bottom_left_lat'],
                                'lon' => $bbox['bottom_left_lon']
                            ],
                            'top_right' => [
                                'lat'=> $bbox['top_right_lat'],
                                'lon' => $bbox['top_right_lon']
                            ]
                        ]
                    ]
                ]
            ]
        ];

        return [
            'index' => env('EDACAP_INDEX'),
            'type' => 'response',
            'body' => [
                'query' => $query
            ],
            'from' => $from,
            'size' => $size
        ];
    }


//    Query builder for autocompletion on admin level names
    #[ArrayShape(['index' => "mixed", 'type' => "string", 'body' => "\array[][]", 'from' => "", 'size' => ""])]
    public function autocompleteQueryBuilder($adminLevelName): array
    {
        $suggest = [
            'completion'=>  [
                'prefix' => $adminLevelName,
                'completion' => [
                    'field' => 'completion_suggester.completion'
                ]
            ]
        ];

        return [
            'index' => env("ADMIN_LEVEL_NAMES_INDEX"),
            'type' => 'response',
            'body' => [
                'suggest' => $suggest
            ]
        ];
    }


    /*
     * Multi match query builder for fetching geoJon containing the requested admin level name.
     * Search in specific fields (NAME_2, NAME_3, NAME_4) that contain the specific information.
     * */
    public function multiMatchQueryBuilder($adminLevelName): array
    {
        $query = [
            "multi_match" => [
                "query" => $adminLevelName,
                "fields" => ["features.properties.NAME_2.keyword",
                            "features.properties.NAME_3.keyword",
                            "features.properties.NAME_4.keyword"]
            ]
        ];

        return [
            'index' => env("GADM_INDEX"),
            'type' => 'response',
            'body' => [
                'query' => $query
            ]
        ];
    }

    /**
     * @throws AuthenticationException
     */
    public function searchWeatherStations(Request $request): \Illuminate\Http\JsonResponse
    {

        try
        {
            $bbox = $request->input('details')['bounding_box'];
        }
        catch (\Exception $e)
        {
            return response()->json(["response" => ('BAD REQUEST')], 400);
        }


        if ($request->input('action') != 'search')
        {
            return response()->json(["response" => ('BAD REQUEST: ONLY SEARCH ACTION IS SUPPORTED')], 400);

        }

        $client = $this->connectToElasticSearch();

        try {

            $params = array("index" => "edacap_index", "type" => "page");

//            get size of index for passing as argument in next call
            $count = $client->count($params);

            $hits = $client->search($this->bboxQueryBuilder($bbox, 0, $count['count']));

            $weatherStations [] = array();

            for ($i = 0; $i < count($hits['hits']['hits']); $i++)
            {
                $weatherStations [] = $hits['hits']['hits'][$i]['_source'];
            }

//            remove empty object from list
            if (count($weatherStations) > 1)
            {

                array_shift($weatherStations);
            }

            return response()->json(["response" => "ok", "weather_stations" => $weatherStations], 200);
        }
        catch (ClientResponseException|ServerResponseException $e)
        {
            $errorMessage = ($e->getMessage());
            $test = (object)json_decode(json_encode($errorMessage), true);

            if (str_contains($test->scalar, "400 Bad Request"))
            {
                return response()->json(["response" => "Internal Server Error due to Bad Request"], 500);

            }
            else
            {
                return response()->json(["response" => $errorMessage], 500);

            }

        }
    }


//    Fetch admin level polygons from api call of dev2.
    public function fetchAdminLevelPolygons($isoCode3, $adminLevel, $resolutionAnalysis): \Illuminate\Http\Client\Response
    {
        return Http::post(env("ES_GADM_ADMIN_LEVEL_POLYGONS_API"), [
            'iso_code_3' => $isoCode3,
            'resolution_analysis' => $resolutionAnalysis,
            'admin_level' => $adminLevel,
        ]);
    }


//    Function for checking if redis key exists in redis
    public function idExistsInRedis($redisKey): string
    {
        $redisClient = $this->connectToRedis();
        $gadmData = $redisClient->get($redisKey);

        if ($gadmData == null)
        {
            return "";
        }
        else
        {
            return $gadmData;
        }
    }



//    Fetch admin level data based on iso code, resolution analysis and admin level
    public function fetchAdminLevelData($isoCode2, $resolutionAnalysis, $adminLevel): \Illuminate\Http\JsonResponse
    {
        try
        {
            try
            {
                $redisClient = $this->connectToRedis();
            }
            catch (\Exception $e)
            {
                return response()->json(["response" => "fail", "exception" => "CONNECTION ERROR"], 500);
            }

//            initialize redis key
            $redisKey = $isoCode2."_".$resolutionAnalysis."_".$adminLevel;
            $gadmData = $this->idExistsInRedis($redisKey);

            if ($gadmData == "")
            {
                $countryLowRes = $this->fetchAdminLevelPolygons($isoCode2, $adminLevel, $resolutionAnalysis);
                $countryLowResBody = json_decode($countryLowRes->body());

//            if response is not an stdClass, it is a string => bad request
                if (!($countryLowResBody->response instanceof  \stdClass))
                {
                    return response()->json(["response" => ("BAD REQUEST: HIGH OR LOW RESOLUTION ANALYSIS EXPECTED")], 500);
                }
                else
                {
                    $redisResponse = $redisClient->set(($redisKey), json_encode($countryLowResBody->response));

                    if (!$redisResponse)
                    {
                        return response()->json(["response" => "fail"], 500);
                    }
                    else
                    {
                        return response()->json(["response" => "ok", "admin_level_data"=>($countryLowResBody->response)], 200);
                    }
                }

            }
            else
            {
                return response()->json(["response" => "ok", "admin_level_data" => json_decode($gadmData)], 200);
            }

        }
        catch (\Exception $e)
        {
            return response()->json(["response" => ($e->getMessage())], 500);
        }
    }

//    Fetch autocompleted admin level names based on user's input. - NOT USED
    /**
     * @throws AuthenticationException
     */
    public function fetchAutocompletedAdminLevelNames($adminLevelName): \Illuminate\Http\JsonResponse
    {
        $client = $this->connectToElasticSearch();

        try {
            $hits = $client->search($this->autocompleteQueryBuilder($adminLevelName));
        }
        catch (ClientResponseException|ServerResponseException $e)
        {
            return response()->json(["response" => ($e->getMessage())], 500);
        }

        $autocompletes [] = array();

//        limit of i based on json object of ES
        for ($i = 0; $i < count($hits['suggest']['completion'][0]['options']); $i++)
        {

            $suggestion = new \stdClass();
            $suggestion->admin_level_name = $hits['suggest']['completion'][0]['options'][$i]['_source']['admin_level_name'];
            $autocompletes [] = $suggestion;
        }

//        remove empty object
        if (count($autocompletes) > 1)
        {
            array_shift($autocompletes);
        }

        return response()->json(["response" => "ok", "suggestions" => $autocompletes], 200);
    }


//    Fetch geojson from ES based on admin level name.
    /**
     * @throws AuthenticationException
     */
    public function fetchGeoJsonBasedOnAdminLevelName($adminLevelName): \Illuminate\Http\JsonResponse
    {
        $client = $this->connectToElasticSearch();

        try
        {
            $hits = $client->search($this->multiMatchQueryBuilder($adminLevelName));

            try
            {
//                Keep only the first hit of the list of hits, because it will be the best fir for the request
                return response()->json(["response" => "ok", "geo_jons" => $hits['hits']['hits'][0]['_source']], 200);
            }
            catch (\Exception $e)
            {
                return response()->json(["response" => "ok", "geo_jons" => new \stdClass()], 200);
            }


        }
        catch (\Exception $e)
        {
            return response()->json(["response" => ($e->getMessage())], 500);

        }

    }
}
