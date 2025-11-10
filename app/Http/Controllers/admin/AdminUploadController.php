<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\QuestionPaper;
use App\Models\Exam;
use Illuminate\Support\Facades\Storage;

class AdminUploadController extends Controller
{
public function upload(Request $request, $paperId)
{
    $request->validate([
        'file' => 'required|mimes:pdf|max:10240',
    ]);

    $paper = QuestionPaper::findOrFail($paperId);

    // Store PDF
    $path = $request->file('file')->store('question_papers', 'public');

    $paper->update(['file_path' => $path]);

    return response()->json([
        'message' => 'PDF uploaded successfully.',
        'paper' => $paper,
    ]);
}
}
