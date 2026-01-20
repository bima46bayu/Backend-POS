<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BankAccount extends Model {
    protected $fillable = ['bank_name','account_number','account_name','account_type','currency'];
}

