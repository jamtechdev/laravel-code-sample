<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CrewQuestion extends Model
{
    use HasFactory;

    protected $fillable =[
        'uid','ordering','question','options','tag','is_active',
    ];

    public function crewQuestionAnswer()
    {
        return $this->hasMany(CrewQuestionAnswer::class, 'question_id', 'id');
    }

    
}
