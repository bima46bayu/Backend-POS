<?php

/*
|--------------------------------------------------------------------------
| Member app (POS-BSG-Customer)
|--------------------------------------------------------------------------
*/
return [

    /*
    | Store a self-registered member is attached to. `members.store_location_id`
    | is required, but membership is company-wide, so this is only the
    | "registered at" store. Null falls back to the first root store.
    */
    'default_store_id' => env('MEMBER_APP_DEFAULT_STORE_ID'),

];
