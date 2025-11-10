<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\QuestionPaper;
use Illuminate\Http\Request;

class AdminPaperController extends Controller
{
    public function index(Exam $exam)
    {
        return $exam->papers()->orderBy('year', 'desc')->get();
    }

    public function store(Request $request, Exam $exam)
    {
        $request->validate([
            'year' => 'required|digits:4',
            'title' => 'nullable|string|max:255',
        ]);

        $paper = $exam->papers()->create([
            'year' => $request->year,
            'title' => $request->title ?? "{$exam->name} - {$request->year} Question Paper",
        ]);

        return response()->json($paper);
    }

    public function show(QuestionPaper $paper)
    {
        return $paper->load('exam');
    }

    public function update(Request $request, QuestionPaper $paper)
    {
        $paper->update($request->only('year', 'title'));
        return response()->json($paper);
    }

    public function destroy(QuestionPaper $paper)
    {
        $paper->delete();
        return response()->json(['message' => 'Paper deleted']);
    }
}
