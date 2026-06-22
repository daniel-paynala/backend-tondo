<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Les routes /recu/* sont déclarées dans bootstrap/app.php (then:)
// sans aucun middleware — elles ne doivent pas passer par le groupe "web".
