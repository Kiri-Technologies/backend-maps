<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Validator;

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

    public function setLocation(Request $request)
    {
        // 1. Ambil data dari request 
        // 2. Jika masuk ke radius salah satu titik rubah arah
        // 3. Simpan data ke dalam array angkot
        // 4. Urutkan data berdasarkan jarak
        // 5. Simpan data ke dalam array jarak_antar_angkot

        // Validation
        $validator = Validator::make($request->all(), [
            'angkot_id' => 'required',
            'lat' => "required",
            'long' => "required",
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->error(),
                'data' => []
            ], 400);
        }


        $lat = floatval($request->lat);
        $long = floatval($request->long);
        $id_angkot = $request->angkot_id;

        try {
            $angkot = Http::withToken(
                $request->bearerToken()
            )->get(env('API_ENDPOINT') . 'angkot/' . $id_angkot);

            $angkot = json_decode($angkot->body(), true);
            $angkot = $angkot['data'];
            $route = $angkot['route'];
        } catch (\Exception $e) {
            //return error message
            return response()->json([
                'status' => 'failed',
                'message' => 'Angkot tidak ditemukan',
                'data' => [],
            ], 404);
        }

        // memvalidasi apakah sudah berada didekat radius lat long awal / akhir
        $lat_awal = $route['lat_titik_awal'];
        $long_awal = $route['long_titik_awal'];
        $lat_akhir = $route['lat_titik_akhir'];
        $long_akhir = $route['long_titik_akhir'];
        $radius = 0.0001 * 5;

        try {
            if (($lat_awal - $radius) < $request->lat && $request->lat < ($lat_awal + $radius) && ($long_awal - $radius) < $request->long && $request->long <  ($long_awal + $radius)) {
                $this->database->getReference('angkot/' . 'route_' . $route['id'] . '/angkot_' . $id_angkot . "/")->set([
                    'angkot_id' => $id_angkot,
                    'arah' => $route['titik_awal'],
                    "is_beroperasi" => true,
                    "is_full" => false,
                    "is_waiting_passengers" => false,
                    'lat' => $lat,
                    'long' => $long,
                    'owner_id' => $angkot['user_owner']['id'],
                ]);
            } elseif (($lat_akhir - $radius) < $request->lat && $request->lat < ($lat_akhir + $radius) && ($long_akhir - $radius) < $request->long && $request->long <  ($long_akhir + $radius)) {
                $this->database->getReference('angkot/' . 'route_' . $route['id'] . '/angkot_' . $id_angkot . "/")->set([
                    'angkot_id' => $id_angkot,
                    'arah' => $route['titik_akhir'],
                    "is_beroperasi" => true,
                    "is_full" => false,
                    "is_waiting_passengers" => false,
                    'lat' => $lat,
                    'long' => $long,
                    'owner_id' => $angkot['user_owner']['id'],
                ]);
            } else {
                $this->database->getReference('angkot/' . 'route_' . $route['id'] . '/angkot_' . $id_angkot . "/")->set([
                    'angkot_id' => $id_angkot,
                    "is_beroperasi" => true,
                    "is_full" => false,
                    "is_waiting_passengers" => false,
                    'lat' => $lat,
                    'long' => $long,
                    'owner_id' => $angkot['user_owner']['id'],
                ]);
            }
        } catch (\Exception $e) {
            //return error message
            $arr = (array) $e;
            return response()->json([
                'status' => 'failed',
                'message' => !$arr ? 'Failed to insert data to firebase' : $arr,
                'data' => [],
            ], 400);
        }


        try {
            // mengurutkan data berdasarkan jarak terdekat
            $angkot_list = $this->database->getReference('angkot/' . 'route_' . $route['id'])->getValue();
        } catch (\Exception $e) {
            //return error message
            $arr = (array) $e;
            return response()->json([
                'status' => 'failed',
                'message' => !$arr ? 'Failed to get data from firebase' : $arr,
                'data' => [],
            ], 400);
        }


        $angkot_list = array_values($angkot_list);

        foreach ($angkot_list as $key => $angkot) {
            $angkot_list[$key]['jarak'] = $this->getDistanceBetweenPoints($angkot['lat'], $angkot['long'], $route['lat_titik_akhir'], $route['long_titik_awal'])['kilometers'];
        }

        usort($angkot_list, function ($a, $b) {
            return $a['jarak'] - $b['jarak'];
        });

        foreach ($angkot_list as $key => $angkot) {
            if ($angkot_list[$key]['angkot_id'] == $id_angkot) {
                if ($key != 0) {
                    $angkot_ini = $angkot_list[$key];


                    try {
                        $angkot_didepan = $this->database->getReference('angkot/' . 'route_' . $route['id'] . "/" . "angkot_" . $angkot_list[$key - 1]['angkot_id'])->getValue();
                    } catch (\Exception $e) {
                        //return error message
                        $arr = (array) $e;
                        return response()->json([
                            'status' => 'failed',
                            'message' => !$arr ? 'Failed to get data from firebase' : $arr,
                            'data' => [],
                        ], 400);
                    }


                    // return response()->json($angkot_ini);
                    $jarakdidepan = ceil($this->getDistanceBetweenPoints($angkot_ini['lat'], $angkot_ini['long'], $angkot_didepan['lat'], $angkot_didepan['long'])['meters']);
                    $waktu_tempuh = $jarakdidepan / 7;
                    // ubah waktu_tempuh menjadi detik
                    $waktu_tempuh = ceil($waktu_tempuh / 60);

                    try {
                        $this->database->getReference('jarak_antar_angkot/angkot_' . $id_angkot)->set([
                            'angkot_id' => $id_angkot,
                            'angkot_id_didepan' => $angkot_list[$key - 1]['angkot_id'],
                            'jarak_antar_angkot_km' => $jarakdidepan / 1000,
                            'jarak_antar_angkot_waktu' => $waktu_tempuh,
                        ]);

                        return response()->json([
                            'status' => 'success',
                            'message' => 'Berhasil menambahkan data jarak antar angkot',
                        ], 200);
                    } catch (\Exception $e) {
                        //return error message
                        $arr = (array) $e;
                        return response()->json([
                            'status' => 'failed',
                            'message' => !$arr ? 'Failed to insert data to firebase' : $arr,
                            'data' => [],
                        ], 400);
                    }
                } else {
                    try {
                        $this->database->getReference('jarak_antar_angkot/angkot_' . $id_angkot)->set([
                            'angkot_id' => $id_angkot,
                            'angkot_id_didepan' => null,
                            'jarak_antar_angkot_km' => 0,
                            'jarak_antar_angkot_waktu' => 0,
                        ]);

                        return response()->json([
                            'status' => 'success',
                            'message' => 'Berhasil menambahkan data jarak antar angkot',
                        ], 200);
                    } catch (\Exception $e) {
                        //return error message
                        $arr = (array) $e;
                        return response()->json([
                            'status' => 'failed',
                            'message' => !$arr ? 'Failed to insert data to firebase' : $arr,
                            'data' => [],
                        ], 400);
                    }
                }
            }
        }
    }

    // fungsi mengukur jarak
    private function getDistanceBetweenPoints($lat1, $lon1, $lat2, $lon2)
    {
        $theta = $lon1 - $lon2;
        $miles = (sin(deg2rad($lat1)) * sin(deg2rad($lat2))) + (cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta)));
        $miles = acos($miles);
        $miles = rad2deg($miles);
        $miles = $miles * 60 * 1.1515;
        $feet = $miles * 5280;
        $yards = $feet / 3;
        $kilometers = $miles * 1.609344;
        $meters = $kilometers * 1000;
        return compact('miles', 'feet', 'yards', 'kilometers', 'meters');
    }


    public function searchAngkot(Request $request)
    {
        // 1. Get data titik_naik from backend_lumen
        // hit api /api/haltevirtual/id
        // 2. Find Angkot with route_id that send by user
        // hit firebase /angkot/route_id
        // 3. Filter data angkot yang arahnya sama dengan titik_naik
        // 4. Filter with radius: count lat and long
        // 5. Return data

        try {
            // Get titik naik from backend lumen
            $titik_naik = Http::withToken(
                $request->bearerToken()
            )->get(env('API_ENDPOINT') . 'haltevirtual/' . $request->input('titik_naik_id'))->json()['data'];

            // Get titik turun from backend lumen
            $titik_turun = Http::withToken(
                $request->bearerToken()
            )->get(env('API_ENDPOINT') . 'haltevirtual/' . $request->input('titik_turun_id'))->json()['data'];

            // Get angkot data from firebase
            $angkot = $this->database->getReference('angkot/route_' . $request->input('route_id'))->getValue();
        } catch (\Exception $e) {
            //return error message
            $arr = (array) $e;
            return response()->json([
                'status' => 'failed',
                'message' => !$arr ? 'Failed to get data from database' : $arr,
                'data' => [],
            ], 400);
        }
        // this reference to firebase
        $angkot = (array) $angkot;
        $titik_naik['lat'] = (float)$titik_naik['lat'];
        $titik_naik['long'] = (float)$titik_naik['long'];
        // filter angkot
        $angkot = array_filter($angkot, function ($angkot) use ($titik_naik) {
            return $angkot['arah'] == $titik_naik['arah'] && $angkot['is_beroperasi'] == 1 && $angkot['is_full'] == 0;
        });


        // filter radius        
        $radius_kecil = 0.0001 * 5;
        $radius_besar = 0.01;

        $angkot_radius_kecil_is_waiting_passenger = [];
        $angkot_radius_kecil_is_not_waiting_passenger = [];
        $angkot_radius_besar = [];

        // make sure penumpang is in radius_kecil before searchAngkot
        // check titik_naik['lat'] and titik_naik['long'] is in radius_kecil
        if (
            $request->input('lat_penumpang') > $titik_naik['lat'] + $radius_kecil || $request->input('lat_penumpang') < $titik_naik['lat'] - $radius_kecil
            || $request->input('long_penumpang') > $titik_naik['long'] + $radius_kecil || $request->input('long_penumpang') < $titik_naik['long'] - $radius_kecil
        ) {
            return response()->json([
                'message' => 'Anda diluar Halte Virtual',
            ], 404);
        }

        foreach ($angkot as $ak) {
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
        $angkot_is_find = null;
        if (count($angkot_radius_kecil_is_waiting_passenger) > 0) {
            // The system selects the angkot that presses the timestampt (priority) button and is not full and is_operating = 1
            // select the first angkot that press button is_waiting_passengers
            // get firebase/setpoints/prioritas
            try {
                $prioritas = $this->database->getReference('setpoints/setpoint_' . $request->input('titik_naik_id') . '/prioritas')->getValue();
            } catch (\Exception $e) {
                //return error message
                $arr = (array) $e;
                return response()->json([
                    'status' => 'failed',
                    'message' => !$arr ? 'Failed to get data from firebase' : $arr,
                    'data' => [],
                ], 400);
            }

            // convert data to array
            $prioritas = json_decode(json_encode($prioritas), true);
            // sort prioritas based on timestamp
            usort($prioritas, function ($a, $b) {
                return $a['timestamp'] <=> $b['timestamp'];
            });
            $angkot_is_find = $prioritas[0]['angkot_id'];
        } else if (count($angkot_radius_kecil_is_not_waiting_passenger) > 0) {
            // The system measures the distance of an angkot that enters a small radius from the end of the route
            // The system chooses the angkot that is closest to the end of the route (buah batu) and is not full and is_operating = 1
            // get route_id from backend_lumen
            try {
                $route = Http::withToken(
                    $request->bearerToken()
                )->get(env('API_ENDPOINT') . 'routes/' . $request->input('route_id'))->json()['data'];
            } catch (\Exception $e) {
                //return error message
                $arr = (array) $e;
                return response()->json([
                    'status' => 'failed',
                    'message' => !$arr ? 'Failed to get data from database' : $arr,
                    'data' => [],
                ], 400);
            }

            $route['lat_titik_awal'] = (float)$route['lat_titik_awal'];
            $route['long_titik_awal'] = (float)$route['long_titik_awal'];
            $route['lat_titik_akhir'] = (float)$route['lat_titik_akhir'];
            $route['long_titik_akhir'] = (float)$route['long_titik_akhir'];
            // bandingkan arah titik_naik dengan titik_awal route_id (string)
            // jika tidak sama maka bandingkan dengan titik_akhir
            if ($route['titik_awal'] == $titik_naik['arah']) {
                // ukur jarak lat titik_awal dan long titik_awal ke lat dan long angkot
                foreach ($angkot_radius_kecil_is_not_waiting_passenger as $index => $angkot) {
                    $angkot_radius_kecil_is_not_waiting_passenger[$index]['distance'] = sqrt(pow(($angkot['long'] - $route['long_titik_awal']), 2) + pow(($angkot['lat'] - $route['lat_titik_awal']), 2));
                }
                // sort angkot based on distance
                usort($angkot_radius_kecil_is_not_waiting_passenger, function ($a, $b) {
                    return $a['distance'] < $b['distance'];
                });
                $angkot_is_find = $angkot_radius_kecil_is_not_waiting_passenger[0]['angkot_id'];
            } else {
                // ukur jarak lat titik_akhir dan long titik_akhir ke lat dan long angkot
                foreach ($angkot_radius_kecil_is_not_waiting_passenger as $index => $angkot) {
                    $angkot_radius_kecil_is_not_waiting_passenger[$index]['distance'] = sqrt(pow(($angkot['long'] - $route['long_titik_akhir']), 2) + pow(($angkot['lat'] - $route['lat_titik_akhir']), 2));
                }

                // sort angkot based on distance
                usort($angkot_radius_kecil_is_not_waiting_passenger, function ($a, $b) {
                    return $a['distance'] < $b['distance'];
                });
                // dd($angkot_radius_kecil_is_not_waiting_passenger);
                $angkot_is_find = $angkot_radius_kecil_is_not_waiting_passenger[0]['angkot_id'];
            }
        } else if (count($angkot_radius_besar) > 0) {
            // The system measures the distance of an angkot that enters a small radius from the end of the route
            // The system chooses the angkot that is closest to the end of the route (buah batu) and is not full and is_operating = 1
            // get route_id from backend_lumen
            try {
                $route = Http::withToken(
                    $request->bearerToken()
                )->get(env('API_ENDPOINT') . 'routes/' . $request->input('route_id'))->json()['data'];
            } catch (\Exception $e) {
                //return error message
                $arr = (array) $e;
                return response()->json([
                    'status' => 'failed',
                    'message' => !$arr ? 'Failed to get data from database' : $arr,
                    'data' => [],
                ], 400);
            }
            // bandingkan arah titik_naik dengan titik_awal route_id (string)
            // jika tidak sama maka bandingkan dengan titik_akhir
            if ($route['titik_awal'] == $titik_naik['arah']) {
                // ukur jarak lat titik_awal dan long titik_awal ke lat dan long angkot
                foreach ($angkot_radius_besar as $index => $angkot) {
                    $angkot_radius_besar[$index]['distance'] = sqrt(pow(($angkot['long'] - $route['long_titik_awal']), 2) + pow(($angkot['lat'] - $route['lat_titik_awal']), 2));
                }
                // sort angkot based on distance
                usort($angkot_radius_besar, function ($a, $b) {
                    return $a['distance'] < $b['distance'];
                });
                $angkot_is_find = $angkot_radius_besar[0]['angkot_id'];

                $jarak_halte_virtual_ke_titik_naik = sqrt(pow(($titik_naik['long'] - $route['long_titik_awal']), 2) + pow(($titik_naik['lat'] - $route['lat_titik_awal']), 2));

                if ($jarak_halte_virtual_ke_titik_naik > $angkot_radius_besar[0]['distance']) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Angkot tidak ditemukan'
                    ], 404);
                }
            } else {
                // ukur jarak lat titik_akhir dan long titik_akhir ke lat dan long angkot
                foreach ($angkot_radius_besar as $index => $angkot) {
                    $angkot_radius_besar[$index]['distance'] = sqrt(pow(($angkot['long'] - $route['long_titik_akhir']), 2) + pow(($angkot['lat'] - $route['lat_titik_akhir']), 2));
                }
                // sort angkot based on distance
                usort($angkot_radius_besar, function ($a, $b) {
                    return $a['distance'] < $b['distance'];
                });
                $angkot_is_find = $angkot_radius_besar[0]['angkot_id'];

                $jarak_halte_virtual_ke_titik_naik = sqrt(pow(($titik_naik['long'] - $route['long_titik_akhir']), 2) + pow(($titik_naik['lat'] - $route['lat_titik_akhir']), 2));

                if ($jarak_halte_virtual_ke_titik_naik > $angkot_radius_besar[0]['distance']) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Angkot tidak ditemukan'
                    ], 404);
                }
            }

            // hitung jarak antara angkot dan halte virtual, apabila lebih jauh halte virtual maka return angkot tidak ditemukan
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Angkot tidak ditemukan'
            ], 404);
        }

        try {
            $angkot_supir = Http::withToken(
                $request->bearerToken()
            )->get(env('API_ENDPOINT') . 'angkot/' . $angkot_is_find)->json()['data'];
        } catch (\Exception $e) {
            //return error message
            $arr = (array) $e;
            return response()->json([
                'status' => 'failed',
                'message' => !$arr ? 'Failed to get data from database' : $arr,
                'data' => [],
            ], 400);
        }

        $jarak = round($this->setTwoPoints($titik_naik['lat'], $titik_naik['long'], $titik_turun['lat'], $titik_turun['long']), 1);
        $price = $this->priceRecomendation($jarak);

        try {
            $dataPerjalanan = Http::withToken(
                $request->bearerToken()
            )->post(env('API_ENDPOINT') . 'perjalanan/create', [
                'penumpang_id' => $request->input('user_id'),
                'angkot_id' => "$angkot_is_find",
                'tempat_naik_id' => $request->input('titik_naik_id'),
                'tempat_turun_id' => $request->input('titik_turun_id'),
                // 'supir_id' => $angkot_supir['supir_id'],
                'supir_id' => $angkot_supir['supir_id'] == null ? 'supir-123456' : $angkot_supir['supir_id'],
                'nama_tempat_naik' => $titik_naik['nama_lokasi'],
                'nama_tempat_turun' => $titik_turun['nama_lokasi'],
                'jarak' => "$jarak Km",
                'rekomendasi_harga' => "$price",
                'is_done' => false,
                'is_connected_with_driver' => false,
            ])->json()['data'];


            // push data penumpang ke firebase
            $data_penumpang = $this->database->getReference('penumpang_naik_turun/angkot_' . $angkot_is_find . '/naik/perjalanan_' . $dataPerjalanan['id'])->set([
                'angkot_id' => $angkot_is_find,
                'id_perjalanan' => $dataPerjalanan['id'],
                'id_titik_naik' => $titik_naik['id'],
                'id_titik_turun' => $titik_turun['id'],
                'id_user' => $request->input('user_id'),
                'titik_naik' => $titik_naik['nama_lokasi'],
                'titik_turun' => $titik_turun['nama_lokasi'],
            ]);
        } catch (\Exception $e) {
            //return error message
            $arr = (array) $e;
            return response()->json([
                'status' => 'failed',
                'message' => !$arr ? 'Failed to insert data to database' : $arr,
                'data' => [],
            ], 400);
        }


        // check data penumpang berhasil di push ke firebase
        if ($data_penumpang) {
            return response()->json([
                'status' => 'success',
                'message' => 'Perjalanan berhasil ditambahkan',
                'data' => $dataPerjalanan
            ], 200);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Perjalanan gagal ditambahkan'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Perjalanan berhasil ditambahkan',
            'data' => $dataPerjalanan,
        ], 201);
    }

    function setTwoPoints(
        $latitudeFrom,
        $longitudeFrom,
        $latitudeTo,
        $longitudeTo
    ) {
        $long1 = deg2rad($longitudeFrom);
        $long2 = deg2rad($longitudeTo);
        $lat1 = deg2rad($latitudeFrom);
        $lat2 = deg2rad($latitudeTo);

        //Haversine Formula
        $dlong = $long2 - $long1;
        $dlati = $lat2 - $lat1;

        $val = pow(sin($dlati / 2), 2) + cos($lat1) * cos($lat2) * pow(sin($dlong / 2), 2);

        $res = 2 * asin(sqrt($val));

        $radius = 3958.756;

        return ($res * $radius);
    }

    function priceRecomendation($jarak)
    {
        $price = 3000;
        if ($jarak < 5) {
            $price;
        } else if ($jarak > 5 && $jarak < 10) {
            $price = 5000;
        } else {
            $price = 7000;
        }

        return $price;
    }

    public function scanQRCode(Request $request)
    {
        // send user_id, perjalanan_id
        // - update is_connected_with_driver = true ke backend lumen
        // - delete data perjalanan di data calon penumpang naik sesuai id angkot
        // - insert data perjalanan di data penumpang turun sesuai id angkot

        // Validation
        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'perjalanan_id' => "required",
            'angkot_id' => "required",
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->error(),
                'data' => []
            ], 400);
        }


        try {
            $get_perjalanan = $this->database->getReference('penumpang_naik_turun/angkot_' . $request->input('angkot_id') . '/naik/perjalanan_' . $request->input('perjalanan_id'))->getSnapshot()->getValue();
            $set_penumpang = $this->database->getReference('penumpang_naik_turun/angkot_' . $request->input('angkot_id') . '/turun/perjalanan_' . $request->input('perjalanan_id'))->set(
                $get_perjalanan
            );
            // delete data perjalanan
            $delete_perjalanan = $this->database->getReference('penumpang_naik_turun/angkot_' . $request->input('angkot_id') . '/naik/perjalanan_' . $request->input('perjalanan_id'))->remove();
            $update_data_perjalanan = Http::withHeaders([
                'Authorization' => env('TOKEN')
            ])->post(env('API_ENDPOINT') . 'perjalanan/' . $request->input('perjalanan_id') . '/update', [
                'is_done' => false,
                'is_connected_with_driver' => true,
            ])->json()['data'];
        } catch (\Exception $e) {
            //return error message
            $arr = (array) $e;
            return response()->json([
                'status' => 'failed',
                'message' => !$arr ? 'Failed to get data from database' : $arr,
                'data' => [],
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Perjalanan berhasil di update',
            'data' => $update_data_perjalanan
        ], 200);
    }

    public function perjalananIsDone(Request $request)
    {
        // Remove perjalanan from perjalanan_naik_turun on angkot{id}/turun

        // Validation
        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'perjalanan_id' => "required",
            'angkot_id' => "required",
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->error(),
                'data' => []
            ], 400);
        }

        try {
            $get_perjalanan = $this->database->getReference('penumpang_naik_turun/angkot_' . $request->input('angkot_id') . '/turun/perjalanan_' . $request->input('perjalanan_id'))->remove();
        } catch (\Exception $e) {
            //return error message
            $arr = (array) $e;
            return response()->json([
                'status' => 'failed',
                'message' => !$arr ? 'Failed to get data from database' : $arr,
                'data' => [],
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'perjalanan selesai'
        ], 200);
    }

    public function setArahAndIsBeroperasi(Request $request)
    {

        // validate input
        $validator = Validator::make($request->all(), [
            'angkot_id' => 'required',
            'route_id' => 'required',
            'is_beroperasi' => 'boolean',
            'arah' => 'string',
        ]);

        $data = $request->all();

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 400);
        }

        if (isset($data['arah'])) {
            try {
                $angkot_arah = $this->database->getReference('angkot/route_' . $request->input('route_id') . '/angkot_' . $request->input('angkot_id') . '/arah')->set(
                    $request->input('arah')
                );
            } catch (\Exception $e) {
                //return error message
                $arr = (array) $e;
                return response()->json([
                    'status' => 'failed',
                    'message' => !$arr ? 'Failed to insert data to database' : $arr,
                    'data' => [],
                ], 400);
            }
        }

        if (isset($data['is_beroperasi'])) {
            try {
                $angkot_is_beroperasi = $this->database->getReference('angkot/route_' . $request->input('route_id') . '/angkot_' . $request->input('angkot_id') . '/is_beroperasi')->set(
                    $request->input('is_beroperasi')
                );
            } catch (\Exception $e) {
                //return error message
                $arr = (array) $e;
                return response()->json([
                    'status' => 'failed',
                    'message' => !$arr ? 'Failed to insert data to database' : $arr,
                    'data' => [],
                ], 400);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Arah dan status beroperasi berhasil di update'
        ], 200);
    }
}
