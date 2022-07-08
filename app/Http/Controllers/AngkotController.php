<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AngkotController extends Controller
{
    //
    protected $database;

    public function __construct()
    {
        $this->database = app('firebase.database');
    }


    public function CreateAngkot(Request $request) {
        $angkot = $this->database->getReference('angkot/route_' . $request->input('route_id') . 'angkot_' . $request->input('angkot_id'))->set([
            'angkot_id' => $request->input('angkot_id'),
            'is_beroperasi' => $request->input('is_beroperasi'),
            'is_full' => false,
            'is_waiting_passengers' => false,
            'lat' => 0,
            'long' => 0,
            'owner_id' => $request->input('owner_id'),
        ]);
    }
}
