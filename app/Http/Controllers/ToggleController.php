<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;


class ToggleController extends Controller
{
 
    protected $database;

    public function __construct()
    {
        $this->database = app('firebase.database');
    }

    public function toggleStop(Request $request)
    {
        // ==============================================================================================================================
        // 1. supir kirim data angkot_id,  is_waiting_for_passenger (boolean), lat, long 
        // 2. get data halte virtual dari backend lumen yang arahnya sama kaya angkot
        // 3. validasi apakah lat & long supir masuk di radius halte virtual tertentu (50 M)
        // 4. kalau dia masuk radius halte virtual update data is_waiting_passenger ke firebase sesuai id angkot, terus return success
        // 5. kalau gamasuk radius halte virtual, return gagal mengubah ngetem karena gak lagi di deket halte virtual
        // ==============================================================================================================================

        // 1. supir kirim data angkot_id, is_waiting_for_passenger (boolean), lat, long
        // ==============================================================================================================================

        // validasi request
        $validator = \Validator::make($request->all(), [
            'angkot_id' => 'required|integer',
            'is_waiting_for_passengers' => 'required|boolean',
            'lat' => 'required|numeric',
            'long' => 'required|numeric',
            'route_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 400);
        }


        $angkot_id = $request->angkot_id;
        $is_waiting_for_passenger = $request->is_waiting_for_passengers;
        $lat = $request->lat;
        $long = $request->long;
        $routeIdAngkot = $request->route_id;

        try {
            // 2. get data halte virtual dari backend lumen yang arahnya sama kaya angkot
            // ==============================================================================================================================
            // $routeIdAngkot = Http::withHeaders([
            //     'Authorization' => 'Bearer '.env('TOKEN'),
            //     ])->get(env('API_ENDPOINT') . 'angkot/1')->json()['data']['route_id'];

            $halteVirtual = Http::withToken(
                $request->bearerToken()
            )->get(env('API_ENDPOINT') . 'haltevirtual?route_id='.$routeIdAngkot)->json()['data'];

        }

        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $arahAngkot = $this->database->getReference('/angkot/route_'.$routeIdAngkot.'/angkot_'.$angkot_id)->getValue()['arah'];
        
            // 3. validasi apakah lat & long supir masuk di radius halte virtual tertentu (50 M)
            // ==============================================================================================================================
            foreach ($halteVirtual as $value) {
                if($value['arah'] == $arahAngkot){
                    $lathalte = $value['lat'];
                    $longhalte = $value['long'];
                    
                    // jarak 50 M
                    $radius = 0.0001 * 5;
    
                    // 4. kalau dia masuk radius halte virtual update data is_waiting_passenger ke firebase sesuai id angkot, terus return success
                    // ==============================================================================================================================
                    if (($lathalte - $radius) < $lat && $lat < ($lathalte + $radius) && ($longhalte - $radius) < $long && $long <  ($longhalte + $radius)) {
                        
                        // update data is_waiting_passenger ke firebase sesuai id angkot
                        $this->database->getReference('angkot/' . 'route_' . $routeIdAngkot . '/angkot_' . $angkot_id . "/")->update([
                            'is_waiting_passengers' => $is_waiting_for_passenger,
                        ]);
    
                        return response()->json([
                            'status' => 'success',
                            'message' => 'Berhasil mengubah status is_waiting_passenger',
                        ]);
                    }
                }
            }
    
            // 5. kalau gamasuk radius halte virtual, return gagal mengubah ngetem karena gak lagi di deket halte virtual
            // ==============================================================================================================================
            return response()->json([
                'status' => 'failed',
                'message' => 'Gagal mengubah status is_waiting_passenger',
            ]);
        }

        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function toggleFull(Request $request)
    {
        // ======================================================
        // 1. supir kirim data angkot_id, is_full (boolean)
        // 2. update data is_full sesuai angkot_id ke firebase 
        // ======================================================

        // 1. supir kirim data angkot_id, is_full (boolean)
        // ======================================================

        // validasi request
        $validator = \Validator::make($request->all(), [
            'angkot_id' => 'required|integer',
            'is_full' => 'required|boolean',
            'route_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 400);
        }

        $angkot_id = $request->angkot_id;
        $is_full = $request->is_full;
        $route_id = $request->route_id;

        // 2. update data is_full sesuai angkot_id ke firebase
        // ======================================================
        try {
            $this->database->getReference('angkot/' . 'route_' . $route_id . '/angkot_' . $angkot_id . "/")->update([
                'is_full' => $is_full,
            ]);
            return response()->json([
                'status' => 'success',
            ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
            ]);
        }
    }

}
