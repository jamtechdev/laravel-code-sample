<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CrewQuestionAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'uid','crew_user_id', 'crew_question_id', 'crew_answer'
    ];

    public function user()
    {
        return $this->belongsTo(User::class,'crew_user_id','id');
    }
    public function crewQuestion()
    {
        return $this->belongsTo(CrewQuestion::class,'crew_question_id','id');
    }
}
