<?php

use App\Http\Controllers\AsynchronousLayersInitialization;
use App\Http\Controllers\CropDataController;
use App\Http\Controllers\ElasticSearchConnectorController;
use App\Http\Controllers\FetchForecastDataController;
use App\Http\Controllers\FetchGeoServerData;
use App\Http\Controllers\FetchHistoricalDataController;
use App\Http\Controllers\FetchLayersController;
use App\Http\Controllers\GadmSearchController;
use App\Http\Controllers\KebeleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

//Fluff
Route::get('/kalhmera', function () {
    //return view('welcome');
    return "az023...";
});

Route::get('/data/kebele', [KebeleController::class, 'processKebeleGeojson']);
Route::post('/geoJsons/kebele', [KebeleController::class, 'fetchKebeleGeojson']);

Route::prefix('crops')->group(function () {
    Route::get('info', [CropDataController::class, 'getCropsInfo']);
    Route::get('yieldForecast/fetch/{weatherStationID}/ranges/{cropID}', [CropDataController::class, 'getYieldRanges']);
    Route::get('yieldForecast/fetch/{weatherStationID}/{soilID}/{cultivarID}', [CropDataController::class, 'getYieldForecast']);
    Route::post('yieldForecast/offlinePopulation', [CropDataController::class, 'populateYieldForecast']);
});

Route::post('/data/weatherStations', [ElasticSearchConnectorController::class, 'searchWeatherStations']);

Route::get('/data/{isoCode2}/{resolutionAnalysis}/{adminLevel}', [ElasticSearchConnectorController::class, 'fetchAdminLevelData']);

Route::get('/historical/climatology/{weatherStationId}', [FetchHistoricalDataController::class, 'fetchHistoricalGraphs']);

Route::get('/forecast/climate/{weatherStationId}/{semester}', [FetchForecastDataController::class, 'climateForecastGraphs']);

Route::get('/layers/data', [FetchLayersController::class, 'fetchGeoserverDetails']);

Route::post('/geoJsonByCoordinates', [GadmSearchController::class, 'fetchGeoJson']);

Route::post('/statisticsCalculation', [FetchGeoServerData::class, 'layersStatisticsCalculation']);

Route::post('/subSeasonalStatisticsCalculation', [FetchGeoServerData::class, 'subSeasonalLayersStatisticsCalculation']);

Route::post('/layersInitialization', [AsynchronousLayersInitialization::class, 's3LayersInitialization']);

Route::get('/forecast/weather/{lat}/{lon}', [FetchForecastDataController::class, 'fetchWeatherForecasts']);

Route::get('/autocomplete/{adminLevelName}', [ElasticSearchConnectorController::class, 'fetchAutocompletedAdminLevelNames']);

Route::get('/geoJsons/{adminLevelName}', [ElasticSearchConnectorController::class, 'fetchGeoJsonBasedOnAdminLevelName']);
