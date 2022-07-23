<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Validator;



class AngkotController extends Controller
{
    //
    protected $database;

    public function __construct()
    {
        $this->database = app('firebase.database');
    }


    public function CreateAngkot(Request $request)
    {

        //validate incoming request
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|string',
            'route_id' => 'required|string',
            'plat_nomor' => 'required|string',
            'pajak_tahunan' => 'required|date',
            'pajak_stnk' => 'required|date',
            'kir_bulanan' => 'required|date',
        ]);

        if ($validator->fails()) {
            //return failed response
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors(),
                'data' => [],
            ], 400);
        } else {
            try {
                // Create Angkot with backend lumen with request input
                $angkot_lumen = Http::withToken(
                    $request->bearerToken()
                )->post(env('API_ENDPOINT') . 'owner/angkot/create', [
                    'user_id' => $request->input('user_id'),
                    'route_id' => $request->input('route_id'),
                    'plat_nomor' => $request->input('plat_nomor'),
                    'pajak_tahunan' => $request->input('pajak_tahunan'),
                    'pajak_stnk' => $request->input('pajak_stnk'),
                    'kir_bulanan' => $request->input('kir_bulanan'),
                ])->json();
                // dd($angkot_lumen);
                //  check if success hit api from another backend services
                if ($angkot_lumen['status'] == 'failed') {
                    // return failed response
                    return response()->json([
                        'status' => 'failed',
                        'message' => $angkot_lumen['message'],
                        'data' => [],
                    ], 400);
                }
            } catch (\Exception $e) {
                //return error message
                return response()->json([
                    'status' => 'failed',
                    'message' => $e,
                    'data' => [],
                ], 409);
            }

            try {
                $route = Http::withToken(
                    $request->bearerToken()
                )->get(env('API_ENDPOINT') . 'routes/' . $angkot_lumen['data']['route_id'])->json()['data'];

                // also put the data into firebase
                $angkot = $this->database->getReference('angkot/route_' . $angkot_lumen['data']['route_id'] . '/angkot_' . $angkot_lumen['data']['id'])->set([
                    'angkot_id' => $angkot_lumen['data']['id'],
                    'is_beroperasi' => false,
                    'is_full' => false,
                    'is_waiting_passengers' => false,
                    'lat' => 0,
                    'long' => 0,
                    'owner_id' => $request->input('user_id'),
                    'arah' =>  $route['titik_awal']
                ]);

                $penumpang_naik_turun = $this->database->getReference('jarak_antar_angkot/angkot_' . $angkot_lumen['data']['id'])->set([
                    'angkot_id' => $angkot_lumen['data']['id'],
                    'jarak_antar_angkot_km' => 0,
                    'jarak_antar_angkot_waktu' => 0,
                ]);
            } catch (\Exception $e) {
                //return error message
                return response()->json([
                    'status' => 'failed',
                    'message' => $e,
                    'data' => [],
                ], 409);
            }


            // check if success push data to firebase
            if ($angkot) {
                // return success response
                return response()->json([
                    'status' => 'success',
                    'message' => 'Angkot created successfully',
                    'data' => $angkot_lumen['data'],
                ], 200);
            } else {
                // return failed response
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Angkot created failed',
                    'data' => [],
                ], 400);
            }
        }
    }

    public function updateAngkot(Request $request, $id)
    {
        //validate incoming request
        $validator = Validator::make($request->all(), [
            'route_id' => 'required|string',
            'plat_nomor' => 'required|string',
            'pajak_tahunan' => 'required|date',
            'pajak_stnk' => 'required|date',
            'kir_bulanan' => 'required|date',
        ]);

        if ($validator->fails()) {
            //return failed response
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors(),
                'data' => [],
            ], 400);
        } else {
            try {
                $get_angkot = Http::withToken(
                    $request->bearerToken()
                )->get(env('API_ENDPOINT') . 'angkot/' . $id)->json();

                if ($get_angkot['status'] == 'failed') {
                    return response()->json([
                        'status' => 'failed',
                        'message' => $get_angkot['message'],
                        'data' => [],
                    ], 400);
                }

                $angkot_lumen = Http::withToken(
                    $request->bearerToken()
                )->post(env('API_ENDPOINT') . 'owner/angkot/' . $id . '/update', [
                    'route_id' => $request->input('route_id'),
                    'plat_nomor' => $request->input('plat_nomor'),
                    'pajak_tahunan' => $request->input('pajak_tahunan'),
                    'pajak_stnk' => $request->input('pajak_stnk'),
                    'kir_bulanan' => $request->input('kir_bulanan'),
                ])->json();

                if ($angkot_lumen['status'] != 'success') {
                    return response()->json(['error' => 'Failed to update angkot'], 400);
                }
                
                $get_angkot_firebase = $this->database->getReference('angkot/route_' . $get_angkot['data']['route_id'] . '/angkot_' . $id)->getValue();

                // also put the data into firebase
                if ($get_angkot['data']['route_id'] != $request->input('route_id')) {
                    // push data into new route id firebase
                    $newRoute = Http::withToken(
                        $request->bearerToken()
                    )->get(env('API_ENDPOINT') . 'routes/' . $request->input('route_id'))->json()['data'];
                    $get_angkot_firebase['arah'] = $newRoute['titik_awal'];


                    $this->database->getReference('angkot/route_' . $request->input('route_id') . '/angkot_' . $id)->set($get_angkot_firebase);

                    $this->database->getReference('angkot/route_' . $get_angkot['data']['route_id'] . '/angkot_' . $get_angkot['data']['id'])->remove();
                }

            } catch (\Exception $e) {
                //return error message
                return response()->json([
                    'status' => 'failed',
                    'message' => $e,
                    'data' => [],
                ], 409);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Angkot updated successfully',
                'data' => $angkot_lumen['data'],
            ], 200);
        }
    }

    public function deleteAngkot(Request $request, $id)
    {
        try {

            $get_angkot = Http::withToken(
                $request->bearerToken()
            )->get(env('API_ENDPOINT') . 'angkot/' . $id)->json();

            // check angkot if exist
            // return error if not found
            if ($get_angkot['status'] == 'failed') {
                return response()->json([
                    'message' => 'Angkot Not Found!'
                ], 404);
            }

            $angkot_lumen = Http::withToken(
                $request->bearerToken()
            )->delete(env('API_ENDPOINT') . 'owner/angkot/' . $id . '/delete')->json();
            //  check if succes hit api from another backend services
            // panic error if error
            if ($angkot_lumen['status'] != 'success') {
                return response()->json(['error' => 'Failed to delete angkot'], 400);
            }
        } catch (\Exception $e) {
            //return error message
            return response()->json([
                'status' => 'failed',
                'message' => $e,
                'data' => [],
            ], 409);
        }

        // also put the data into firebase
        $angkot = $this->database->getReference('angkot/route_' . $get_angkot['data']['route_id'] . '/angkot_' . $get_angkot['data']['id'])->remove();
        $jarak_antar_angkot = $this->database->getReference('jarak_antar_angkot/angkot_' . $get_angkot['data']['id'])->remove();
        return response()->json([
            'status' => 'success',
            'message' => 'Angkot berhasil dihapus',
            'data' => [],
        ], 200);
    }
}
