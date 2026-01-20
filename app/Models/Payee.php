<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payee extends Model {
    protected $fillable = ['payee','bank_name','account_number','account_name'];
}

