<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesStoreAccess;

abstract class Controller
{
    use AuthorizesStoreAccess;
}
