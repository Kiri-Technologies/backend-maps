<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class JarakController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = app('firebase.database');
    }

    public function updatePosisition(Request $request)
    {
        $response = Http::withToken(
            $request->bearerToken()
        )->get(
            env('API_ENDPOINT') . 'angkot'. '/'. $request->angkot_id,
        );

        $arah = Http::withToken(
            $request->bearerToken()
        )->get(
            env('API_ENDPOINT') . 'routes'. '/'. $response['data']['route_id'],
        );

        $angkot = json_decode($response->body(), true);
        $arah = json_decode($arah, true);
        $user_id = $angkot['data']['user_id'];

        if($angkot['data']['is_beroperasi'] == true) {
            $is_beroperasi = true;
        } else {
            $is_beroperasi = false;
        }

        $is_full = 'false';
        $is_waiting_passenggers = 'true';

        $this->database->getReference('angkot/1/'. $request->angkot_id)->set([
            'arah' => $arah['data']['titik_akhir'],
            'is_beroperasi' => $is_beroperasi,
            'is_full' => $is_full,
            'is_waiting_passenggers' => $is_waiting_passenggers,
            'lat' => $request->lat,
            'long' => $request->long,
            'owner_id' => $user_id,
        ]);

        return response()->json([
            'message' => 'Success'
        ]);
    }

    public function oneWays(Request $request){
        $data = $this->database->getReference('angkot/1')->getValue();

        $arah = [];
        for($i = 1; $i < count($data); $i++) {
            if($data[$i]['is_beroperasi'] == true && $data[$i]['arah'] == $request->arah) {
                $arah[] = $data[$i];
            }
        }

        // urutkan berdasarkan jarak
        $urutan = collect($arah)->sortBy('lat')->values();
        // usort($arah, function($a, $b) {
        //     return $a['lat'] - $b['lat'];
        // });
        return response()->json($urutan);

    }

    function getDistanceBetweenPoints($lat1, $lon1, $lat2, $lon2, $owner) {
        $theta = $lon1 - $lon2;
        $miles = (sin(deg2rad($lat1)) * sin(deg2rad($lat2))) + (cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta)));
        $miles = acos($miles);
        $miles = rad2deg($miles);
        $miles = $miles * 60 * 1.1515;
        $feet  = $miles * 5280;
        $yards = $feet / 3;
        $kilometers = $miles * 1.609344;
        $meters = $kilometers * 1000;
        $owner = $owner;
        return compact('miles','feet','yards','kilometers','meters', 'owner'); 
    }

    public function getDistance(Request $request)
    {
        $data = $this->database->getReference('angkot/1')->getValue();
        $arah = [];
        for($i = 1; $i < count($data); $i++) {
            if($data[$i]['is_beroperasi'] == true && $data[$i]['arah'] == $request->arah) {
                $arah[] = $data[$i];
            }
        }
        $urutan = collect($arah)->sortBy('lat')->values();
        $distance = [];
        for($i = 0; $i < count($urutan); $i++) {
            $distance[] = $this->getDistanceBetweenPoints($request->lat, $request->long, $urutan[$i]['lat'], $urutan[$i]['long'], $urutan[$i]['owner_id']);
        }
        return response()->json($distance);
    }

}


