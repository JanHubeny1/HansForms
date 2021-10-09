<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Form extends Model
{
    use HasFactory;

    protected $fillable = [
      'slug',
      'name',
      'description',
      'end_time',
      'private_token',
      'user_id'
    ];

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function formElements() {
        return $this->hasMany(FormElement::class);
    }

    public function formCompletions() {
        return $this->hasMany(FormCompletion::class);
    }
}
