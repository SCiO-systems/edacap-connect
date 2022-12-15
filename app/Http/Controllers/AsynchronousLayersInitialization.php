<?php

namespace App\Http\Controllers;

use Aws\S3\S3Client;
use Gaufrette\Adapter\AwsS3 as AwsS3Adapter;
use Gaufrette\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;


class AsynchronousLayersInitialization
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


    /*
     * For the requested list of layers, fetch tif files from geoserver (getCoverage api call) and upload them
     * on s3 bucket 'geotiff-in-eu'.
     * */
    public function s3LayersInitialization(Request $request): \Illuminate\Http\JsonResponse
    {

        try {
            $layers = $request->input("layers");

            if (sizeof($layers) == 0) {
                return response()->json(["response" => ('BAD REQUEST')], 400);
            }
        }
        catch (\Exception $e)
        {
            return response()->json(["response" => ('BAD REQUEST')], 400);
        }

        $s3client = $this->connectToS3();
        $adapter = new AwsS3Adapter($s3client, 'geotiff-in-eu');
        $filesystem = new Filesystem($adapter);

        foreach ($layers as $layer) {
            $geotiff = Http::get("https://geo.aclimate.org/geoserver/aclimate_et/ows?SERVICE=WCS&REQUEST=GetCoverage".
                "&VERSION=2.0.1&CoverageId=" . env("GEOSERVER_ACLIMATE_ETH") . $layer);

//                save tiff file to s3 bucket
            $fileName = $layer.".tif";
            $filesystem->write($fileName, $geotiff);
        }

        return response()->json(["response" => "Successful initialization of layers."], 200);

    }

}
