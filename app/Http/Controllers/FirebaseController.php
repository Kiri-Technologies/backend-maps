<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FirebaseController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = app('firebase.database');
    }

    public function index()
    {
        $data = $this->database->getReference('setpoints/setpoint_2/prioritas')->getValue();
        // convert data to array
        $data = json_decode(json_encode($data), true);
        
        // sort data by timestamp and show angkot id
        usort($data, function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });
        return response()->json($data);
    }

    public function searchAngkot(Request $request) {
        // 1. Get data titik_naik from backend_lumen
        // hit api /api/haltevirtual/id
        // 2. Find Angkot with route_id that send by user
        // hit firebase /angkot/route_id
        // 3. Filter data angkot yang arahnya sama dengan titik_naik
        // 4. Filter with radius: count lat and long
        // 5. Return data

        $titik_naik = Http::withHeaders([
            'Authorization' => env('TOKEN')
        ])->get(env('API_ENDPOINT') . 'haltevirtual/' . $request->input('titik_naik_id'))->json()['data'];
        $angkot = $this->database->getReference('angkot/route_' . $request->input('route_id'))->getValue();  // this reference to firebase
        $angkot = (array) $angkot;     
        $titik_naik['lat'] = (float)$titik_naik['lat'];
        $titik_naik['long'] = (float)$titik_naik['long'];
        // filter angkot
        $angkot = array_filter($angkot, function ($angkot) use ($titik_naik) {
            return $angkot['arah'] == $titik_naik['arah'] && $angkot['is_beroperasi'] == 1 && $angkot['is_full'] == 0;
        });
        

        // filter radius        
        $radius_kecil = 0.0001*5;
        $radius_besar = 0.01;

        $angkot_radius_kecil_is_waiting_passenger = [];
        $angkot_radius_kecil_is_not_waiting_passenger = [];
        $angkot_radius_besar = [];
        foreach($angkot as $ak) {
            // small radius
            $lat_angkot_small = $ak['lat'] < $titik_naik['lat'] + $radius_kecil && $ak['lat'] > $titik_naik['lat'] - $radius_kecil; 
            $long_angkot_small = $ak['long'] < $titik_naik['long'] + $radius_kecil && $ak['long'] > $titik_naik['long'] - $radius_kecil;
            // big radius
            $lat_angkot_big = $ak['lat'] < $titik_naik['lat'] + $radius_besar && $ak['lat'] > $titik_naik['lat'] - $radius_besar;
            $long_angkot_big = $ak['long'] < $titik_naik['long'] + $radius_besar && $ak['long'] > $titik_naik['long'] - $radius_besar;

            if ($lat_angkot_small && $long_angkot_small) {
                    if ($ak['is_waiting_passengers'] == 1) {
                        // The system selects the angkot that presses the timestampt (priority) button and is not full and is_operating = 1
                        // select the first angkot that press button is_waiting_passengers
                        array_push($angkot_radius_kecil_is_waiting_passenger, $ak);
                    } else {
                        // The system measures the distance of an angkot that enters a small radius from the end of the route
                        // The system chooses the angkot that is closest to the end of the route (buah batu) and is not full and is_operating = 1
                        array_push($angkot_radius_kecil_is_not_waiting_passenger, $ak);
                    }
            } else if ($lat_angkot_big && $long_angkot_big) {
                    // The system measures the distance of angkot that enter within a large radius of the end of the route (buah batu)
                    // The system chooses the angkot that is the farthest from the end of the route (fruit stone) and is not full and is_operating = 1
                    array_push($angkot_radius_besar, $ak);
            }
        }
        
        // select angkot
        if (count($angkot_radius_kecil_is_waiting_passenger) > 0) {
            // The system selects the angkot that presses the timestampt (priority) button and is not full and is_operating = 1
            // select the first angkot that press button is_waiting_passengers
            // get firebase/setpoints/prioritas
            $prioritas = $this->database->getReference('setpoints/setpoint_' . $request->input('titik_naik_id'). '/prioritas')->getValue();
            // convert data to array
            $prioritas = json_decode(json_encode($prioritas), true);
            // sort prioritas based on timestamp
            usort($prioritas, function($a, $b) {
                return $a['timestamp'] <=> $b['timestamp'];
            });
            return response()->json($prioritas[0]['angkot_id']);

        } else if (count($angkot_radius_kecil_is_not_waiting_passenger) > 0) {
            // The system measures the distance of an angkot that enters a small radius from the end of the route
            // The system chooses the angkot that is closest to the end of the route (buah batu) and is not full and is_operating = 1
            // get route_id from backend_lumen
            $route = Http::withHeaders([
                'Authorization' => env('TOKEN')
            ])->get(env('API_ENDPOINT') . 'routes/' . $request->input('route_id'))->json()['data'];
            $route['lat_titik_awal'] = (float)$route['lat_titik_awal'];
            $route['long_titik_awal'] = (float)$route['long_titik_awal'];
            $route['lat_titik_akhir'] = (float)$route['lat_titik_akhir'];
            $route['long_titik_akhir'] = (float)$route['long_titik_akhir'];
            // bandingkan arah titik_naik dengan titik_awal route_id (string)
            // jika tidak sama maka bandingkan dengan titik_akhir
            if ($route['titik_awal'] == $titik_naik['arah']) {
                // ukur jarak lat titik_awal dan long titik_awal ke lat dan long angkot
                foreach($angkot_radius_kecil_is_not_waiting_passenger as $index => $angkot) {
                    $angkot_radius_kecil_is_not_waiting_passenger[$index]['distance'] = sqrt(pow(($angkot['long'] - $route['long_titik_awal']), 2) + pow(($angkot['lat'] - $route['lat_titik_awal']), 2));
                }
                // sort angkot based on distance
                usort($angkot_radius_kecil_is_not_waiting_passenger, function($a, $b) {
                    return $a['distance'] < $b['distance'];
                });
                return response()->json($angkot_radius_kecil_is_not_waiting_passenger[0]['angkot_id']);

            } else {
                // ukur jarak lat titik_akhir dan long titik_akhir ke lat dan long angkot
                foreach($angkot_radius_kecil_is_not_waiting_passenger as $index => $angkot) {
                    $angkot_radius_kecil_is_not_waiting_passenger[$index]['distance'] = sqrt(pow(($angkot['long'] - $route['long_titik_akhir']), 2) + pow(($angkot['lat'] - $route['lat_titik_akhir']), 2));
                }

                // sort angkot based on distance
                usort($angkot_radius_kecil_is_not_waiting_passenger, function($a, $b) {
                    return $a['distance'] < $b['distance'];
                });
                // dd($angkot_radius_kecil_is_not_waiting_passenger);
                return response()->json($angkot_radius_kecil_is_not_waiting_passenger[0]['angkot_id']);
                
            }
        } else if (count($angkot_radius_besar) > 0) {
            // The system measures the distance of an angkot that enters a small radius from the end of the route
            // The system chooses the angkot that is closest to the end of the route (buah batu) and is not full and is_operating = 1
            // get route_id from backend_lumen
            $route = Http::withHeaders([
                'Authorization' => env('TOKEN')
            ])->get(env('API_ENDPOINT') . 'routes/' . $request->input('route_id'))->json()['data'];
            // bandingkan arah titik_naik dengan titik_awal route_id (string)
            // jika tidak sama maka bandingkan dengan titik_akhir
            if ($route['titik_awal'] == $titik_naik['arah']) {
                // ukur jarak lat titik_awal dan long titik_awal ke lat dan long angkot
                foreach($angkot_radius_besar as $index => $angkot) {
                    $angkot_radius_besar[$index]['distance'] = sqrt(pow(($angkot['long'] - $route['long_titik_awal']), 2) + pow(($angkot['lat'] - $route['lat_titik_awal']), 2));
                }
                // sort angkot based on distance
                usort($angkot_radius_besar, function($a, $b) {
                    return $a['distance'] < $b['distance'];
                });
                return response()->json($angkot_radius_besar[0]['angkot_id']);

            } else {
                // ukur jarak lat titik_akhir dan long titik_akhir ke lat dan long angkot
                foreach($angkot_radius_besar as $index => $angkot) {
                    $angkot_radius_besar[$index]['distance'] = sqrt(pow(($angkot['long'] - $route['long_titik_akhir']), 2) + pow(($angkot['lat'] - $route['lat_titik_akhir']), 2));
                }
                // sort angkot based on distance
                usort($angkot_radius_besar, function($a, $b) {
                    return $a['distance'] < $b['distance'];
                });
                return response()->json($angkot_radius_besar[0]['angkot_id']);
            }

        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Angkot tidak ditemukan'
            ],404);
        }
    }
    
}
