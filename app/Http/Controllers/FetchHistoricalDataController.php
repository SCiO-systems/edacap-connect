<?php

namespace App\Http\Controllers;

use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\AuthenticationException;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use JetBrains\PhpStorm\ArrayShape;
use Predis\Client as PredisClient;

class FetchHistoricalDataController
{

    public function connectToRedis(): PredisClient
    {
        return new PredisClient([
            'scheme' => 'tcp',
            'host'   => env('REDIS_HOST',''),
            'port'   => env('REDIS_PORT',''),
        ]);
    }


    /**
     * @throws AuthenticationException
     */
    public function connectToElasticSearch(): \Elastic\Elasticsearch\Client
    {
        return ClientBuilder::create()
            ->setHosts([env('ES_HOST_PORT')])
            ->build();
    }


//    Fetch data from ES based on the requested weather station id.
    #[ArrayShape(['index' => "mixed", 'type' => "string", 'body' => "\array[][]"])]
    public function historicalQueryBuilder($weatherStationId): array
    {

//        create query
        $query = [
            'match' => [
                'weatherStationId.keyword' =>  $weatherStationId
            ]
        ];

        return [
            'index' => env('EDACAP_HISTORICAL_INDEX'),
            'type' => 'response',
            'body' => [
                'query' => $query,
                'fields' =>  [
                    'historicalClimatic.monthlyData.data.value',
                    'historicalClimatic.year'
                ]
            ]
        ];
    }


//    Map modified list in the requested output for frontend.
    public function historicalClimatologyInitialization($list): array
    {
        $innerDataPrec = new \stdClass();
        $innerDataPrec->category = 'Precipitation';
        $innerDataPrec->unit = 'mm';

        $innerDataMaxTemp = new \stdClass();
        $innerDataMaxTemp->category = 'Maximum temperature';
        $innerDataMaxTemp->unit = 'Celsius';

        $innerDataMinTemp = new \stdClass();
        $innerDataMinTemp->category = 'Minimum temperature';
        $innerDataMinTemp->unit = 'Celsius';

        $innerDataSol = new \stdClass();
        $innerDataSol->category = 'Solar radiation';
        $innerDataSol->unit = 'Kilo Watts per Square Metre  kW/m2';

        $updatedListPrec [] = array();
        $updatedListMinTemp [] = array();
        $updatedListMaxTemp [] = array();
        $updatedListSol [] = array();

        for ($month = 1; $month < 13; $month++)
        {
            $precObj = new \stdClass();
            $solRadObj = new \stdClass();
            $tempMin = new \stdClass();
            $tempMax = new \stdClass();

            $precObj->month = $month;
            $solRadObj->month = $month;
            $tempMin->month = $month;
            $tempMax->month = $month;

            for ($i = 0; $i < count($list); $i++)
            {
                if ($list[$i]->month == $month)
                {

                    for ($j = 0; $j < count($list[$i]->data); $j++)
                    {
                        if ($list[$i]->data[$j]['measure'] == 'prec') {
                            $precObj->value = round($list[$i]->data[$j]['value'], 2);
                        }
                        else if ($list[$i]->data[$j]['measure'] == 'sol_rad')
                        {
                            $solRadObj->value = round($list[$i]->data[$j]['value'], 2);
                        }
                        else if ($list[$i]->data[$j]['measure'] == 't_max')
                        {
                            $tempMax->value = round($list[$i]->data[$j]['value'], 2);
                        }
                        else if ($list[$i]->data[$j]['measure'] == 't_min')
                        {
                            $tempMin->value = round($list[$i]->data[$j]['value'], 2);
                        }
                    }
                }
            }

            $updatedListPrec [] = $precObj;
            $updatedListMinTemp [] = $tempMin;
            $updatedListMaxTemp [] = $tempMax;
            $updatedListSol [] = $solRadObj;

        }

//        shift first position of empty array
        if (count($updatedListPrec) > 1)
        {
            array_shift($updatedListPrec);
        }
//        shift first position of empty array
        if (count($updatedListMaxTemp) > 1)
        {
            array_shift($updatedListMaxTemp);
        }
//        shift first position of empty array
        if (count($updatedListMinTemp) > 1)
        {
            array_shift($updatedListMinTemp);
        }
//        shift first position of empty array
        if (count($updatedListSol) > 1)
        {
            array_shift($updatedListSol);
        }


//        map data to object
        $innerDataPrec->bar_chart_data = $updatedListPrec;
        $innerDataMinTemp->bar_chart_data = $updatedListMinTemp;
        $innerDataMaxTemp->bar_chart_data = $updatedListMaxTemp;
        $innerDataSol->bar_chart_data = $updatedListSol;

//        add data in list
        $tableData [] = $innerDataPrec;
        $tableData [] = $innerDataMinTemp;
        $tableData [] = $innerDataMaxTemp;
        $tableData [] = $innerDataSol;

        return $tableData;
    }


//    Map climatic data to the requested output for frontend
    public function climaticInitialization($hits): array
    {

        try{
            $valuesObj = $hits['hits']['hits'][0]['fields']["historicalClimatic.monthlyData.data.value"];
            $yearsObj = $hits['hits']['hits'][0]['fields']["historicalClimatic.year"];
        }
        catch (\Exception $exception)
        {
            return array();
        }

        $categoriesList = array();

        $precList = array();
        $tempMinList = array();
        $tempMaxList = array();
        $solRadList = array();


        $precCategory = new \stdClass();
        $precCategory->category = "Precipitation";
        $precCategory->unit = "mm";
        $tempMinCategory = new \stdClass();
        $tempMinCategory->category = 'Minimum temperature';
        $tempMinCategory->unit = 'Celsius';
        $tempMaxCategory = new \stdClass();
        $tempMaxCategory->category = 'Maximum temperature';
        $tempMaxCategory->unit = 'Celsius';
        $solRadiusCategory = new \stdClass();
        $solRadiusCategory->category = 'Solar radiation';
        $solRadiusCategory->unit = 'Kilo Watts per Square Metre  kW/m2';

        $precPos = 0;
        $tempMinPos = 1;
        $tempMaxPos = 2;
        $solRadPos = 3;

        for ($month = 1; $month < 13; $month++) {
            $monthPrecObj = new \stdClass();
            $monthPrecObj->month = $month;

            $monthTempMinObj = new \stdClass();
            $monthTempMinObj->month = $month;

            $monthTempMaxObj = new \stdClass();
            $monthTempMaxObj->month = $month;

            $monthSolRadObj = new \stdClass();
            $monthSolRadObj->month = $month;

            $dataPrecList = array();
            $dataTempMinList = array();
            $dataTempMaxList = array();
            $dataSolRadList = array();

            for ($year = $yearsObj[0]; $year <= $yearsObj[count($yearsObj)-1]; $year++) {
                $precData = new \stdClass();

                $precData->year = $year;
                $precData->value = round($valuesObj[$precPos], 2);

                $tempMinData = new \stdClass();
                $tempMinData->year = $year;
                $tempMinData->value = round($valuesObj[$tempMinPos], 2);

                $tempMaxData = new \stdClass();
                $tempMaxData->year = $year;
                $tempMaxData->value = round($valuesObj[$tempMaxPos], 2);

                $solRadData = new \stdClass();
                $solRadData->year = $year;
                $solRadData->value = round($valuesObj[$solRadPos], 2);


//                fetch the next values.
                $precPos += 4;
                $tempMinPos += 4;
                $tempMaxPos += 4;
                $solRadPos += 4;

                $dataPrecList [] = $precData;
                $dataTempMinList [] = $tempMinData;
                $dataTempMaxList [] = $tempMaxData;
                $dataSolRadList [] = $solRadData;
            }
            $monthPrecObj->data = $dataPrecList;
            $precList[] = $monthPrecObj;

            $monthTempMinObj->data = $dataTempMinList;
            $tempMinList[] = $monthTempMinObj;

            $monthTempMaxObj->data = $dataTempMaxList;
            $tempMaxList[] = $monthTempMaxObj;

            $monthSolRadObj->data = $dataSolRadList;
            $solRadList[] = $monthSolRadObj;

        }

//        map data to relevant fields
        $precCategory->line_chart_data = $precList;
        $tempMinCategory->line_chart_data = $tempMinList;
        $tempMaxCategory->line_chart_data = $tempMaxList;
        $solRadiusCategory->line_chart_data = $solRadList;

//        add data in table
        $categoriesList [] = $precCategory;
        $categoriesList [] = $tempMinCategory;
        $categoriesList [] = $tempMaxCategory;
        $categoriesList [] = $solRadiusCategory;

        return $categoriesList;
    }


//    Map climatology objects fetched from ES.
    public function climatologyInitialization($hits): array
    {
        $historicalClimatologyObj = $hits['hits']['hits'][0]['_source']['historicalClimatology'];

        $list [] = array();
        for ($month = 1; $month < 13; $month++) {
            for ($i = 0; count($historicalClimatologyObj); $i++) {
                if ($i == 0) {
                    for ($j = 0; count($historicalClimatologyObj[$i]['monthlyData']); $j++) {
                        $monthObj = new \stdClass();

                        if ($j < count($historicalClimatologyObj[$i]['monthlyData'])) {
                            if ($historicalClimatologyObj[$i]['monthlyData'][$j]['month'] == $month) {

                                $monthObj->month = $month;
                                $monthObj->data = $historicalClimatologyObj[$i]['monthlyData'][$j]['data'];
                                $list[] = $monthObj;
                            }
                        } else {
                            break;
                        }
                    }
                } else {
                    break;
                }
            }
        }
        return $list;
    }


    /*
     * Historical data contain information for both climatic and climatology categories.
     * */
    public function historicalGraphsInitialization($hits): array
    {
        $historicalClimatologyList = $this->climatologyInitialization($hits);

//        remove empty object from list
        if (count($historicalClimatologyList) > 1)
        {
            array_shift($historicalClimatologyList);
        }

//        if data exists for the  specific weather station id
        if (count($historicalClimatologyList) != 1)
        {
            $historicalClimatologyList = $this->historicalClimatologyInitialization($historicalClimatologyList);
        }

        $historicalClimaticList = $this->climaticInitialization($hits);
        $graphs = array();

//        initialize graph object with climatic and climatology data
        if (count($historicalClimaticList) != 0)
        {
            for ($i = 0; $i < count($historicalClimaticList); $i++)
            {
                $graphObj = new \stdClass();
                $graphObj->category = $historicalClimaticList[$i]->category;
                $graphObj->unit = $historicalClimaticList[$i]->unit;

//            if historical climatology is not empty and the categories are the same
                if (count($historicalClimatologyList) != 1 &&
                    $historicalClimaticList[$i]->category == $historicalClimatologyList[$i]->category)
                {
                    $graphObj->bar_chart_data =  $historicalClimatologyList[$i]->bar_chart_data;
                }
                else
                {
                    $graphObj->bar_chart_data =  array();
                }

                $graphObj->line_chart_data =  $historicalClimaticList[$i]->line_chart_data;
                $graphs [] = $graphObj;
            }
        }
        else if(count($historicalClimatologyList) != 1)
        {
            for ($i = 0; $i < count($historicalClimatologyList); $i++)
            {
                $graphObj = new \stdClass();
                $graphObj->category = $historicalClimatologyList[$i]->category;
                $graphObj->unit = $historicalClimatologyList[$i]->unit;
                $graphObj->bar_chart_data =  $historicalClimatologyList[$i]->bar_chart_data;
                $graphObj->line_chart_data =  array();
                $graphs [] = $graphObj;
            }
        }
//        there are no data
        else
        {
            $graphObj = new \stdClass();
            $graphObj->category = "";
            $graphObj->unit = "";
            $graphObj->bar_chart_data =  array();
            $graphObj->line_chart_data =  array();
            $graphs [] = $graphObj;
        }


        return $graphs;
    }


//    check if key exists in redis. If yes -> return value, else return empty string
    public function idExistsInRedis($redisKey): string
    {
        $redisClient = $this->connectToRedis();
        $weatherStation = $redisClient->get($redisKey);

        if ($weatherStation == null)
        {
            return "";
        }
        else
        {
            return $weatherStation;
        }
    }

    /**
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    public function fetchHistoricalGraphs($weatherStationId): \Illuminate\Http\JsonResponse
    {
        try
        {
            $redisClient = $this->connectToRedis();
            $client = $this->connectToElasticSearch();
        }
        catch (\Exception $e)
        {
            return response()->json(["response" => "fail", "exception" => "CONNECTION ERROR"], 500);
        }

//        initialize redis key for specific entry
        $redisKey = $weatherStationId."_forecast";
//        check if key exists
        $redisClient->del($redisKey);
        $weatherStation = $this->idExistsInRedis($redisKey);

//        if key does not exist
        if ($weatherStation == "")
        {
//            fetch data from ES
            $hits = $client->search($this->historicalQueryBuilder($weatherStationId));

            if (count($hits['hits']['hits']) == 0)
            {
                return response()->json(["response" => "ok", "exception" => "WEATHER STATION ID NOT FOUND"], 200);
            }


            $graphs = $this->historicalGraphsInitialization($hits);

//            save data in redis
            $redisResponse = $redisClient->set(($redisKey), json_encode($graphs));

            if (!$redisResponse)
            {
                return response()->json(["response" => "fail"], 500);
            }
            else
            {
                return response()->json(["response" => "ok", "historical_graphs"=>$graphs], 200);
            }
        }
        else
        {
            return response()->json(["response" => "ok", "historical_graphs" => json_decode($weatherStation)], 200);
        }
    }
}
