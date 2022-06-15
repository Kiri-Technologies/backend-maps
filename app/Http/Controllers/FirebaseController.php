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

    public function tarikAngkot(Request $request)
    {
        // 1. Ambil data dari request 
        // 2. Jika masuk ke radius salah satu titik rubah arah
        // 3. Simpan data ke dalam array angkot
        // 4. Urutkan data berdasarkan jarak
        // 5. Simpan data ke dalam array jarak_antar_angkot

        $data = $request->all();
        $id = $data['angkot_id'];
        $angkot = Http::withToken(
            $request->bearerToken()
        )->get(env('API_ENDPOINT') . 'angkot/'.$id);
        $angkot = json_decode($angkot->body(), true);
        $angkot = $angkot['data'];
        $route = $angkot['route'];

        // memvalidasi apakah sudah berada didekat radius lat long awal / akhir
        $lat_awal = $route['lat_titik_awal'];
        $long_awal = $route['long_titik_awal'];
        $lat_akhir = $route['lat_titik_akhir'];
        $long_akhir = $route['long_titik_akhir'];
        $radius = 0.0001*5;

        if(($lat_awal-$radius) < $request->lat && $request->lat < ($lat_awal+$radius) && ($long_awal-$radius) < $request->long && $request->long <  ($long_awal+$radius))
        {
            $this->database->getReference('angkot/'.'route_'.$route['id'].'/angkot_'.$id."/")->set([
                'angkot_id' => $id,
                'arah' => $route['titik_awal'],
                "is_beroperasi" => true,
                "is_full" => false,
                "is_waiting_passengers" => false,
                'lat' => floatval($data['lat']),
                'long' => floatval($data['long']),
                'owner_id' => $angkot['user_owner']['id'],
            ]);
        }elseif(($lat_akhir-$radius) < $request->lat && $request->lat < ($lat_akhir+$radius) && ($long_akhir-$radius) < $request->long && $request->long <  ($long_akhir+$radius))
        {
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
        }else{
            $this->database->getReference('angkot/'.'route_'.$route['id'].'/angkot_'.$id."/")->set([
                'angkot_id' => $id,
                "is_beroperasi" => true,
                "is_full" => false,
                "is_waiting_passengers" => false,
                'lat' => floatval($data['lat']),
                'long' => floatval($data['long']),
                'owner_id' => $angkot['user_owner']['id'],
            ]);
        }

        // mengurutkan data berdasarkan jarak terdekat
        $angkot_list = $this->database->getReference('angkot/'.'route_'.$route['id'])->getValue();
  
        $angkot_list = array_values($angkot_list);

        foreach($angkot_list as $key => $angkot) {
            $angkot_list[$key]['jarak'] = $this->getDistanceBetweenPoints($angkot['lat'], $angkot['long'], $route['lat_titik_akhir'] ,$route['long_titik_awal'])['kilometers'];
        }
        
        
        usort($angkot_list, function($a, $b) {
            return $a['jarak'] - $b['jarak'];
        });
        
        foreach($angkot_list as $key => $angkot) {
            if($angkot_list[$key]['angkot_id'] == $id) {
                if($key != 0){
                    $angkot_ini = $angkot_list[$key];
                    $angkot_didepan = $this->database->getReference('angkot/'.'route_'.$route['id']."/"."angkot_".$angkot_list[$key-1]['angkot_id'])->getValue();
                    // return response()->json($angkot_ini);
                    $jarakdidepan = ceil($this->getDistanceBetweenPoints($angkot_ini['lat'] ,$angkot_ini['long'], $angkot_didepan['lat'], $angkot_didepan['long'])['meters']);
                    if($jarakdidepan > 1000){
                        $jarakdidepan = ($jarakdidepan/1000) .'km';
                    }else{
                        $jarakdidepan = $jarakdidepan.'m';
                    }
                    $waktuTempuh = $jarakdidepan / 40 * 60;
                    return $jarakdidepan;
                    $this->database->getReference('jarak_antar_angkot/angkot_'.$id)->set([
                        'angkot_id' => $id,
                        'angkot_id_didepan' => $angkot_list[$key-1]['angkot_id'],
                        'jarak_antar_angkot_km' => $jarakdidepan." km",
                        'jarak_antar_angkot_waktu' => $waktuTempuh." min",
                    ]);
                }else{
                    $this->database->getReference('jarak_antar_angkot/angkot_'.$id)->set([
                        'angkot_id' => $id,
                        'angkot_id_didepan' => "null",
                        'jarak_antar_angkot_km' => "null",
                        'jarak_antar_angkot_waktu' => "null",
                    ]);
                }    
            }
        }
    }

    // fungsi mengukur jarak
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

}
