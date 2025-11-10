<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionPaper;
use Illuminate\Http\Request;

class AdminQuestionController extends Controller
{
    public function index($paperId)
    {
        $paper = QuestionPaper::with('questions')->findOrFail($paperId);

        return response()->json([
            'paper' => $paper,
            'questions' => $paper->questions,
        ]);
    }

    public function store(Request $request, QuestionPaper $paper)
    {
        $request->validate([
            'question_text' => 'required|string',
            'options' => 'required|array|min:2',
        ]);

        $question = $paper->questions()->create([
            'question_text' => $request->question_text,
            'type' => 'mcq',
        ]);

        foreach ($request->options as $opt) {
            $question->options()->create([
                'option_text' => $opt['text'],
                'is_correct' => $opt['is_correct'] ?? false,
            ]);
        }

        return response()->json($question->load('options'));
    }

    public function show(Question $question)
    {
        return $question->load('options');
    }

    public function update(Request $request, Question $question)
    {
        $question->update($request->only('question_text'));
        if ($request->has('options')) {
            $question->options()->delete();
            foreach ($request->options as $opt) {
                $question->options()->create([
                    'option_text' => $opt['text'],
                    'is_correct' => $opt['is_correct'] ?? false,
                ]);
            }
        }
        return response()->json($question->load('options'));
    }

    public function destroy(Question $question)
    {
        $question->delete();
        return response()->json(['message' => 'Question deleted']);
    }
}
