<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    use HasFactory;

    protected $fillable = ['question_paper_id', 'question_text', 'type', 'marks'];

    public function paper()
    {
        return $this->belongsTo(QuestionPaper::class, 'question_paper_id');
    }

    public function options()
    {
        return $this->hasMany(QuestionOption::class);
    }



}
