<?php

namespace App\Http\Controllers;

use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\AuthenticationException;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use JetBrains\PhpStorm\ArrayShape;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\True_;
use PHPUnit\Exception;
use Illuminate\Support\Facades\Log;
use Predis\Client as PredisClient;
use function Symfony\Component\String\s;

class FetchForecastDataController
{


//    Fetch weather forecasts from their API based on input latitude and longitude
    public function weatherForecastsRetrieval($latitude, $longitude): \Illuminate\Http\Client\Response
    {
        return Http::get(env("WEATHER_FORECAST_API"), [
            'lat' => $latitude,
            'lon' => $longitude,
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

    /**
     * @throws AuthenticationException
     */
    public function connectToElasticSearch(): \Elastic\Elasticsearch\Client
    {
        return ClientBuilder::create()
            ->setHosts([env('ES_HOST_PORT')])
            ->build();
    }


//    query builder for searching weather station id
    #[ArrayShape(['index' => "mixed", 'type' => "string", 'body' => "\array[][]"])]
    public function forecastQueryBuilder($weatherStationId): array
    {

//        create query
        $query = [
            'match' => [
                'climate.weatherStationId.keyword' =>  $weatherStationId
            ]
        ];

        return [
            'index' => env('EDACAP_FORECAST_INDEX'),
            'type' => 'response',
            'body' => [
                'query' => $query
            ]
        ];
    }



//    check if specific key exists in redis
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


//   for each category initialize value/month
    public function predictionScenariosInitialization($semester, $list): array
    {

//        select correct semester
        $monthPos = 0;
        if ($semester == 7)
        {
            $monthPos = 6;
        }
        else if ($semester == 10)
        {
            $monthPos = 9;
        }

//        print_r($monthPos);

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

//        for the next three months
        for ($month = $monthPos; $month < $monthPos + 3 ; $month++)
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
                        if ($list[$i]->data[$j]['measure'] == 'prec')
                        {
                            if ($list[$i]->name == 'max')
                            {
                                $precObj->maximum = round($list[$i]->data[$j]['value'], 2);
                            }
                            if ($list[$i]->name == 'min')
                            {
                                $precObj->minimum = round($list[$i]->data[$j]['value'], 2);

                            }
                            if ($list[$i]->name == 'avg')
                            {
                                $precObj->average = round($list[$i]->data[$j]['value'], 2);

                            }
                        }
                        else if ($list[$i]->data[$j]['measure'] == 'sol_rad')
                        {
                            if ($list[$i]->name == 'max')
                            {
                                $solRadObj->maximum = round($list[$i]->data[$j]['value'], 2);
                            }
                            if ($list[$i]->name == 'min')
                            {
                                $solRadObj->minimum = round($list[$i]->data[$j]['value'], 2);

                            }
                            if ($list[$i]->name == 'avg')
                            {
                                $solRadObj->average = round($list[$i]->data[$j]['value'], 2);

                            }
                        }
                        else if ($list[$i]->data[$j]['measure'] == 't_max')
                        {
                            if ($list[$i]->name == 'max')
                            {
                                $tempMax->maximum = round($list[$i]->data[$j]['value'], 2);
                            }
                            if ($list[$i]->name == 'min')
                            {
                                $tempMax->minimum = round($list[$i]->data[$j]['value'], 2);

                            }
                            if ($list[$i]->name == 'avg')
                            {
                                $tempMax->average = round($list[$i]->data[$j]['value'], 2);

                            }
                        }
                        else if ($list[$i]->data[$j]['measure'] == 't_min')
                        {
                            if ($list[$i]->name == 'max')
                            {
                                $tempMin->maximum = round($list[$i]->data[$j]['value'], 2);
                            }
                            if ($list[$i]->name == 'min')
                            {
                                $tempMin->minimum = round($list[$i]->data[$j]['value'], 2);

                            }
                            if ($list[$i]->name == 'avg')
                            {
                                $tempMin->average = round($list[$i]->data[$j]['value'], 2);

                            }
                        }
                    }
                }
            }

            $updatedListPrec [] = $precObj;
            $updatedListMaxTemp [] = $tempMax;
            $updatedListMinTemp [] = $tempMin;
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


//        initialize relevant fields
        $innerDataPrec->items = $updatedListPrec;
        $innerDataMaxTemp->items = $updatedListMaxTemp;
        $innerDataMinTemp->items = $updatedListMinTemp;
        $innerDataSol->items = $updatedListSol;


//        add in list
        $tableData [] = $innerDataPrec;
        $tableData [] = $innerDataMaxTemp;
        $tableData [] = $innerDataMinTemp;
        $tableData [] = $innerDataSol;

        return $tableData;
    }


//    initialization of pie chart json object
    public function precipitationProbabilitiesInitialization($hits, $semester): array
    {
        $climateObj = $hits['hits']['hits'][0]['_source']['climate']['climate'];

        $probabilitiesObj [] = array();

        for ($i = 0; $i < count($climateObj[0]['data']); $i++)
        {
            if ((int)$climateObj[0]['data'][$i]['month'] == $semester)
            {
                $prob = new \stdClass();
                $prob->value = $climateObj[0]['data'][$i]['probabilities'][0]['normal'] * 100;
                $prob->category = 'Normal';
                $probabilitiesObj [] = $prob;

                $prob = new \stdClass();
                $prob->value = $climateObj[0]['data'][$i]['probabilities'][0]['lower'] * 100;
                $prob->category = 'Below';
                $probabilitiesObj [] = $prob;

                $prob = new \stdClass();
                $prob->value = $climateObj[0]['data'][$i]['probabilities'][0]['upper'] * 100;
                $prob->category = 'Above';
                $probabilitiesObj [] = $prob;

                break;
            }
        }

        if (count($probabilitiesObj) > 1)
        {
            array_shift($probabilitiesObj);
        }


        return $probabilitiesObj;
    }

//    reformat input from elastic search
    public function scenarioObjInitialization($hits): array
    {
        $scenarioObj = $hits['hits']['hits'][0]['_source']['climate']['scenario'];

        $list [] = array();
        for ($month = 5; $month < 12; $month++) {
            for ($i = 0; count($scenarioObj); $i++) {
                if ($i <  count($scenarioObj)) {
                    for ($j = 0; count($scenarioObj[$i]['monthlyData']); $j++) {
                        $monthObj = new \stdClass();
                        $monthObj->name = $scenarioObj[$i]['name'];

                        if ($j < 7) {
                            if ($scenarioObj[$i]['monthlyData'][$j]['month'] == $month) {

                                $monthObj->month = $month;
                                $monthObj->data = $scenarioObj[$i]['monthlyData'][$j]['data'];
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


//    Graphs object initialization
    public function climateForecastGraphsInitialization($weatherStationId, $esClient, $semester): \stdClass
    {
        $hits = $esClient->search($this->forecastQueryBuilder($weatherStationId));
        $graphs = new \stdClass();

        if (count($hits['hits']['hits']) != 0)
        {

            $scenarioObjList = $this->scenarioObjInitialization($hits);
            if (count($scenarioObjList) > 1)
            {
                array_shift($scenarioObjList);
                $graphs->table_data = $this->predictionScenariosInitialization($semester, $scenarioObjList);
            }
            else
            {
                $graphs->table_data = array();

            }
            $graphs->graph_data = $this->precipitationProbabilitiesInitialization($hits, $semester);

        }
        else {
            $graphs->table_data = array();
            $graphs->graph_data = array();
        }

        return $graphs;
    }


//    Initialization of climate forecast graphs based on weather station id and semester
    public function climateForecastGraphs($weatherStationId, $semester): \Illuminate\Http\JsonResponse
    {

        try
        {
            $redisClient = $this->connectToRedis();
            $esClient = $this->connectToElasticSearch();
        }
        catch (\Exception $e)
        {
            return response()->json(["response" => "fail", "exception" => "CONNECTION ERROR"], 500);
        }

        $redisKey = $weatherStationId."_".$semester;
        $redisClient->del($redisKey);
        $weatherStationPerSemester = $this->idExistsInRedis($redisKey);

        if ($weatherStationPerSemester == "")
        {

            $graphs = $this->climateForecastGraphsInitialization($weatherStationId, $esClient, $semester);

            if (count($graphs->table_data) == 0 && count($graphs->graph_data) == 0)
            {
                return response()->json(["response" => "ok", "exception"=>"WEATHER STATION ID NOT FOUND"], 200);
            }


            $redisResponse = $redisClient->set(($redisKey), json_encode($graphs));

            if (!$redisResponse)
            {
                return response()->json(["response" => "fail"], 500);
            }
            else
            {
                return response()->json(["response" => "ok", "forecast_graphs"=>$graphs], 200);
            }
        }
        else
        {
            return response()->json(["response" => "ok", "forecast_graphs" => json_decode($weatherStationPerSemester)], 200);
        }
    }


//    Initialization of chart data object
    public function chartDataPopulation($numericKeys, $weatherForecastBody): array
    {
        $chartDataArray = array();


        foreach ($numericKeys as $key) {

//            fetch cluster of key
            $cluster = $this->mapKeyToCluster($key);
//            check if cluster exists in array
            $clusterPos = $this->clusterExists($chartDataArray, $cluster);

            $dataObj = new \stdClass();
            $dataObj->property = $key;
            $values = array();

            $day = 1;
            foreach ($weatherForecastBody->data as $data) {
                $dayValuePair = new \stdClass();
                $dayValuePair->day = $day;
                $dayValuePair->value = $data->$key;
                $values [] = $dayValuePair;

                $day++;
            }

            $dataObj->data = $values;

//            if cluster does not exist, create new object, else update the old array
            if ($clusterPos == -1)
            {
                $clusterOcj = new \stdClass();
                $clusterOcj->cluster = $cluster;
                $clusterOcj->variables = array();
                $clusterOcj->variables [] = $dataObj;
                $chartDataArray [] = $clusterOcj;
            }
            else
            {
                $chartDataArray[$clusterPos]->variables[] = $dataObj;
            }

//            old version of code
//            $dataObj = new \stdClass();
//            $dataObj->property = $key;
//            $values = array();
//
//            $day = 1;
//            foreach ($weatherForecastBody->data as $data) {
//                $dayValuePair = new \stdClass();
//                $dayValuePair->day = $day;
//                $dayValuePair->value = $data->$key;
//                $values [] = $dayValuePair;
//
//                $day++;
//            }
//
//            $dataObj->data = $values;
//            $chartDataArray [] = $dataObj;
        }

        return $chartDataArray;
    }


    public function dataTransformation($weatherForecastBody): \stdClass
    {
//        list of timestamps based on their model
        $timestampsList = ["moonrise_ts", "sunset_ts", "ts", "sunrise_ts", "moonset_ts"];
        $numericKeys = array();
        $nonNumericKeys = array();
        $weatherForecasts = new \stdClass();

        $properties = array_keys(get_object_vars($weatherForecastBody->data[0]));

//        keep keys with numeric snd non numeric values for populating chart and table data
        foreach ($properties as $prop) {
            $key = $weatherForecastBody->data[0]->$prop;
            if (is_numeric($key) &&
                !in_array($prop, $timestampsList)) {
                $numericKeys [] = $prop;
            }
            else {
                $nonNumericKeys [] = $prop;
            }
        }

        $weatherForecasts->chart_data = $this->chartDataPopulation($numericKeys, $weatherForecastBody);
        $weatherForecasts->table_data = $this->tableDataPopulation(array_merge($numericKeys, $nonNumericKeys), $weatherForecastBody);

        return $weatherForecasts;
    }


    private function clusterExists($chartDataArray, $cluster): int
    {
        $pos = 0;
        foreach ($chartDataArray as $data)
        {
            if (strcmp($data->cluster, $cluster) == 0)
            {
                return $pos ;
            }
            $pos++;
        }
        return -1;
    }


//    function for mapping cluster to key
    private function mapKeyToCluster($key): string
    {
        return match ($key) {
            "valid_date", "datetime" => "Date",
            "moonrise_ts", "sunset_ts", "moonset_ts" => "Timestamp",
            "high_temp", "app_min_temp", "app_max_temp", "low_temp", "max_temp", "temp", "min_temp" => "Temperature",
            "clouds", "clouds_hi", "clouds_mid", "clouds_low" => "Cloud Cover",
            "wind_dir", "wind_cdir", "wind_gust_spd", "wind_cdir_full", "wind_spd" => "Wind",
            "moon_phase_lunation", "moon_phase" => "Lunar phase",
            default => "N/A",
        };

    }


//    private function clusteringTableData($tableDataArray, $key, $value)
//    {
//        $cluster = $this->mapKeyToCluster($key);
//        $clusterPos = $this->clusterExists($tableDataArray, $cluster);
//
////        if cluster does not exist in table data
//        if ($clusterPos == -1) {
//            $tableDataObj = new \stdClass();
//            $tableDataObj->cluster = $cluster;
//            $temp = new \stdClass();
//
////            if key contains equals to weather -> keep only specific field.
//            if (strcmp($key, "weather") == 0) {
//                $temp->{$key} = $value->description;
//            }
//            else{
//                $temp->{$key} = $value;
//            }
//
//
//            $tableDataObj->variables = $temp;
//
//            $tableDataArray [] = $tableDataObj;
//        }
//        else
//        {
////            convert std class to array, in order to add new key value pair
//            $objArray = json_decode(json_encode($tableDataArray[$clusterPos]->variables), true);
//
//
////            if key contains equals to weather -> keep only specific field.
//            if (strcmp($key, "weather") == 0)
//            {
//                $objArray[$key] = $value->description;
//            }
//            else
//            {
//                $objArray[$key] = $value;
//            }
//
//            $tableDataArray[$clusterPos]->variables = ($objArray);
//        }
//
//        return $tableDataArray;
//    }


    private function tableDataPopulation($keys, $weatherForecastBody): array
    {

        $tableDataArray = array();

        foreach ($weatherForecastBody->data as $data) {
            $dataObj = new \stdClass();
//            $keyTableData = array();
            foreach ($keys as $key) {

//                keep only specific field of weather object
                if (strcmp($key, "weather") == 0) {
                    $dataObj->{$key} = $data->$key->description;
                }
                else {
                    if ($data->$key != null){
                        $dataObj->{$key} = $data->$key;
                    }
                    else {
                        $dataObj->{$key} = "";
                    }
                }
//                $keyTableData = $this->clusteringTableData($keyTableData, $key, $data->$key);
            }
            $tableDataArray [] = $dataObj;
        }

        return $tableDataArray;
    }

    public function finalDataTransformation($transformedData, $latitude, $longitude): array
    {
        Log::info("This isn't even my final form", [$transformedData]);
        $finalForm = array(
            "type" => "Feature",
            "geometry" => array(
                "type" => "Point",
                "coordinates" => [$latitude, $longitude, 25]                                //25 is a random value for now
            )
        );
        $meta = array(
            "units" => array(
                "app_max_temp" => '',
                "app_min_temp" => '',
                "clouds" => '',
                "clouds_hi" => '',
                "clouds_low" => '',
                "clouds_mid" => '',
                "dewpt" => '',
                "high_temp" => '',
                "low_temp" => '',
                "max_temp" => '',
                "min_temp" => '',
                "moon_phase" => '',
                "moon_phase_lunation" => '',
                "ozone" => '',
                "pop" => '',
                "precip" => '',
                "rh" => '',
                "slp" => '',
                "snow" => '',
                "snow_depth" => '',
                "temp" => '',
                "uv" => '',
                "vis" => '',
                "wind_dir" => '',
                "wind_gust_spd" => '',
                "wind_spd" => '',
                "max_dhi" => '',
                "moonrise_ts" => '',
                "moonset_ts" => '',
                "sunrise_ts" => '',
                "sunset_ts" => '',
                "ts" => '',
                "valid_date" => '',
                "wind_cdir" => '',
                "wind_cdir_full" => ''
            )
        );
        Log::info("These are the days boioioi", [$transformedData->table_data]);
        $timeseries = array();
        foreach ($transformedData->table_data as $singleData)
        {
            $singleSeriers = (array)$singleData;
            unset($singleSeriers["datetime"]);
            unset($singleSeriers["weather"]);
            $singleDay = array(
                "time" => $singleData->datetime,
                "data" => array(
                    "instant" => array(
                        "details" => $singleSeriers,
                        "symbol_code" => $singleData->weather
                    )
                )
            );
            array_push($timeseries, $singleDay);
        }

        $properties = ["meta" => $meta, "timeseries" => $timeseries];
        $finalForm = array_merge($finalForm, ["properties" => $properties]);

        return $finalForm;
    }



//    function for fetching weather forecasts based on requested lat and lon.
    public function fetchWeatherForecasts($latitude, $longitude): \Illuminate\Http\JsonResponse
    {
        $weatherForecastObj = $this->weatherForecastsRetrieval($latitude, $longitude);
        $weatherForecastBody = json_decode($weatherForecastObj->body());
       Log::info('Weather forecast body returned from the API', [$weatherForecastBody]);


        try{
            $weatherForecastTransformed = $this->dataTransformation($weatherForecastBody);
            //return response()->json(["response" => "ok", "weather_forecasts" => $this->dataTransformation($weatherForecastBody)], 200);
        }
        catch (\Exception $e) {
            return response()->json(["response" => "fail", "exception" => $e->getMessage()], 500);
        }

        $finalForm = $this->finalDataTransformation($weatherForecastTransformed, $latitude, $longitude);
        $weatherForecastTransformed = array_merge((array)$weatherForecastTransformed, ["daily_data" => $finalForm]);

        return response()->json(["response" => "ok", "weather_forecasts" => $weatherForecastTransformed], 200);
    }
}
