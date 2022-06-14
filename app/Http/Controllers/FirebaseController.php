<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class FirebaseController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = app('firebase.database');
    }

    public function index()
    {
        $data = $this->database->getReference('coba/1')->getValue();
        // dd($data);
        return response()->json($data);
    }

    private function getDistanceBetweenPoints($lat1, $lon1, $lat2, $lon2) {
        $theta = $lon1 - $lon2;
        $miles = (sin(deg2rad($lat1)) * sin(deg2rad($lat2))) + (cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta)));
        $miles = acos($miles);
        $miles = rad2deg($miles);
        $miles = $miles * 60 * 1.1515;
        $feet = $miles * 5280;
        $yards = $feet / 3;
        $kilometers = $miles * 1.609344;
        $meters = $kilometers * 1000;
        return compact('miles','feet','yards','kilometers','meters'); 
    }

    public function tarikAngkot(Request $request)
    {
        // 1. Ambil data dari request kemudiann simpan ke firebase

        $data = $request->all();
        $id = $data['angkot_id'];
        $angkot = Http::withToken(
            $request->bearerToken()
        )->get(env('API_ENDPOINT') . 'angkot/'.$id);
        $angkot = json_decode($angkot->body(), true);
        $angkot = $angkot['data'];
        $route = $angkot['route'];
        // return $angkot;
        // memvalidasi apakah sudah berada didekat radius lat long awal / akhir
        $lat_awal = $route['lat_titik_awal'];
        $long_awal = $route['long_titik_awal'];
        $lat_akhir = $route['lat_titik_akhir'];
        $long_akhir = $route['long_titik_akhir'];
        $titik_awal = $route['titik_awal'];
        $titik_akhir = $route['titik_akhir'];

        $radius_kecil = 0.01;
        
        $hasil_titik_awal = round($this->getDistanceBetweenPoints($lat_awal, $long_awal, $data['lat'], $data['long'])['meters']);
        $hasil_titik_akhir = round($this->getDistanceBetweenPoints($lat_akhir, $long_akhir, $data['lat'], $data['long'])['meters']);

        $this->database->getReference('angkot/'.'route_'.$route['id'].'/angkot_'.$id."/")->set([
            'angkot_id' => $id,
            'arah' => $route['titik_akhir'],
            "is_beroperasi" => true,
            "is_full" => false,
            "is_waiting_passengers" => false,
            'lat' => floatval($data['lat']),
            'long' => floatval($data['long']),
            'owner_id' => $angkot['user_owner']['id'],
        ]);

        // mengurutkan data berdasarkan jarak terdekat
        $angkot_list = $this->database->getReference('angkot/'.'route_'.$route['id'])->getValue();
        
        $angkot_list = array_values($angkot_list);

        foreach($angkot_list as $key => $angkot) {
            $angkot_list[$key]['jarak'] = $this->getDistanceBetweenPoints($angkot['lat'], $angkot['long'], $route['lat_titik_akhir'] ,$route['long_titik_awal'])['kilometers'];
        }
        
        
        usort($angkot_list, function($a, $b) {
            return $a['jarak'] - $b['jarak'];
        });
        
        // return $angkot_list;

        foreach($angkot_list as $key => $angkot) {
            if($angkot_list[$key]['angkot_id'] == $id) {
                if($key != 0){
                    $angkot_ini = $angkot_list[$key];
                    $angkot_didepan = $this->database->getReference('angkot/'.'route_'.$route['id']."/"."angkot_".$angkot_list[$key-1]['angkot_id'])->getValue();
                    // return response()->json($angkot_ini);
                    $jarakdidepan = ceil($this->getDistanceBetweenPoints($angkot_ini['lat'] ,$angkot_ini['long'], $angkot_didepan['lat'], $angkot_didepan['long'])['kilometers']);
                    $waktuTempuh = $jarakdidepan / 40 * 60;
                    $this->database->getReference('jarak_antar_angkot/angkot_'.$id)->set([
                        'angkot_id' => $id,
                        'angkot_id_didepan' => $angkot_list[$key-1]['angkot_id'],
                        'jarak_antar_angkot_km' => $jarakdidepan." km",
                        'jarak_antar_angkot_waktu' => $waktuTempuh." min",
                    ]);
                }
                
            }
        }
    }

}
