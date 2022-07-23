<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Validator;

class SetpointController extends Controller
{
    //

    public function __construct()
    {
        $this->database = app('firebase.database');
    }

    public function createSetpoint(Request $request)
    {
        //validate incoming request
        $validator = Validator::make($request->all(), [
            'route_id' => 'required',
            'nama_lokasi' => 'required|string',
            'lat' => 'required',
            'long' => 'required',
            'arah' => 'required|string',
        ]);

        if ($validator->fails()) {
            //return failed response
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors(),
                'data' => [],
            ], 400);
        }

        // post halte virtual
        $halte_virtual = Http::withToken(
            $request->bearerToken()
        )->post(env('API_ENDPOINT') . 'admin/haltevirtual/create', [
            'route_id' => $request->input('route_id'),
            'nama_lokasi' => $request->input('nama_lokasi'),
            'lat' => $request->input('lat'),
            'long' => $request->input('long'),
            'arah' => $request->input('arah')
        ])->json();

        // check if failed hit api from another backend services
        if ($halte_virtual['status'] == 'failed') {
            // return failed response
            return response()->json([
                'status' => 'failed',
                'message' => $halte_virtual['message'],
                'data' => [],
            ], 400);
        }

        // push data to firebase
        try {
            $setpoints = $this->database->getReference('setpoints/setpoint_' . $halte_virtual['data']['id'])->set([
                'arah' => $halte_virtual['data']['arah'],
                'lat' => (float) $halte_virtual['data']['lat'],
                'long' => (float) $halte_virtual['data']['long'],
                'nama_lokasi' => $halte_virtual['data']['nama_lokasi'],
                'route_id' => (int) $halte_virtual['data']['route_id'],
                'setpoint_id' => (int) $halte_virtual['data']['id'],
            ]);
        } catch (\Exception $e) {
            //return error message
            return response()->json([
                'status' => 'failed',
                'message' => $e,
                'data' => [],
            ], 409);
        }

        // return success response
        return response()->json([
            'status' => 'success',
            'message' => 'setpoint added successfully!',
            'data' => $halte_virtual['data'],
        ], 200);
    }

    public function updateSetpoint(Request $request, $id)
    {
        //validate incoming request
        $validator = Validator::make($request->all(), [
            'route_id' => 'required',
            'nama_lokasi' => 'required|string',
            'lat' => 'required',
            'long' => 'required',
            'arah' => 'required|string',
        ]);

        if ($validator->fails()) {
            //return failed response
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors(),
                'data' => [],
            ], 400);
        }

        $halte_virtual = Http::withToken(
            $request->bearerToken()
        )->post(env('API_ENDPOINT') . 'admin/haltevirtual/' . $id . '/update', [
            'route_id' => $request->input('route_id'),
            'nama_lokasi' => $request->input('nama_lokasi'),
            'lat' => $request->input('lat'),
            'long' => $request->input('long'),
            'arah' => $request->input('arah')
        ])->json();


        // check if failed hit api from another backend services
        if ($halte_virtual['status'] == 'failed') {
            // return failed response
            return response()->json([
                'status' => 'failed',
                'message' => $halte_virtual['message'],
                'data' => [],
            ], 400);
        }

        try {
            // update to firebase
            $setpoints = $this->database->getReference('setpoints/setpoint_' . $halte_virtual['data']['id'])->set([
                'arah' => $request->input('arah'),
                'lat' => (float) $request->input('lat'),
                'long' => (float) $request->input('long'),
                'nama_lokasi' => $request->input('nama_lokasi'),
                'route_id' => (int) $request->input('route_id'),
                'setpoint_id' => (int) $halte_virtual['data']['id'],
            ]);
        } catch (\Exception $e) {
            //return error message
            return response()->json([
                'status' => 'failed',
                'message' => $e,
                'data' => [],
            ], 409);
        }

        // return success response
        return response()->json([
            'status' => 'success',
            'message' => 'setpoint updated successfully!',
            'data' => $halte_virtual['data'],
        ], 200);
    }

    public function deleteSetpoint(Request $request, $id)
    {

        // get halte virtual first from backend lumen database
        $get_halte_virtual = Http::withToken(
            $request->bearerToken()
        )->get(env('API_ENDPOINT') . 'haltevirtual/' . $id)->json();

        // check if failed hit api from another backend services
        if ($get_halte_virtual['status'] == 'failed') {
            // return failed response
            return response()->json([
                'status' => 'failed',
                'message' => $get_halte_virtual['message'],
                'data' => [],
            ], 400);
        }

        $halte_virtual = Http::withToken(
            $request->bearerToken()
        )->delete(env('API_ENDPOINT') . 'admin/haltevirtual/' . $id . '/delete',)->json();

        // check if failed hit api from another backend services
        if ($halte_virtual['status'] == 'failed') {
            // return failed response
            return response()->json([
                'status' => 'failed',
                'message' => $halte_virtual['message'],
                'data' => [],
            ], 400);
        }

        try {
            // delete from firebase
            $setpoints = $this->database->getReference('setpoints/setpoint_' . $get_halte_virtual['data']['id'])->remove();
        } catch (\Exception $e) {
            //return error message
            return response()->json([
                'status' => 'failed',
                'message' => $e,
                'data' => [],
            ], 409);
        }

        // return success response
        return response()->json([
            'status' => 'success',
            'message' => 'setpoint deleted successfully!',
            'data' => [],
        ], 200);
    }
}
