<?php

use Illuminate\Support\Facades\Route;

// Serve the React SPA shell for all web routes.
// The frontend router (if added later) handles sub-paths client-side.
Route::get('/', function () {
    return view('app');
});
