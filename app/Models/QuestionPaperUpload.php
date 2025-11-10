<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionPaperUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'question_paper_id',
        'file_path',
        'status',
        'parsed_data',
    ];

    protected $casts = [
        'parsed_data' => 'array', // stores parsed data as JSON
    ];

    public function paper()
    {
        return $this->belongsTo(QuestionPaper::class, 'question_paper_id');
    }
}
