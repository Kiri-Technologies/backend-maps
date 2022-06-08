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

    private function getDistanceBetweenPoints($latitude1, $longitude1, $latitude2, $longitude2,$owner ,$unit = 'miles') {
        $theta = $longitude1 - $longitude2; 
        $distance = (sin(deg2rad($latitude1)) * sin(deg2rad($latitude2))) + (cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * cos(deg2rad($theta))); 
        $distance = acos($distance); 
        $distance = rad2deg($distance); 
        $distance = $distance * 60 * 1.1515; 
        switch($unit) { 
          case 'miles': 
            break; 
          case 'kilometers' : 
            $distance = $distance * 1.609344; 
        } 
        $distance = round($distance,2)*1000;
        // kembalikan nilai distance dan owner
        return [
            'meter' => $distance,
            'owner' => $owner,
        ];
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


