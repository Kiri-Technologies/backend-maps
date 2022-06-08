<?php

namespace App\Http\Controllers;

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

    public function masukin(Request $request)
    {

        $a = $this->database->getReference('coba/1')->set([
            'nama' => $request->nama,
        ]);

        return response()->json($a);
    }
}
