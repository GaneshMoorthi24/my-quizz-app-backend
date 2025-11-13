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
        $paper = QuestionPaper::with(['questions.options'])->findOrFail($paperId);

        // Format questions to match frontend expectations
        $formattedQuestions = $paper->questions->map(function ($question) {
            return $this->formatQuestion($question);
        });

        return response()->json($formattedQuestions);
    }

    public function store(Request $request, QuestionPaper $paper)
    {
        $request->validate([
            'question_text' => 'required|string',
            'options' => 'required|array|min:2',
            'correct_answer' => 'required|string|in:A,B,C,D',
        ]);

        $question = $paper->questions()->create([
            'question_text' => $request->question_text,
            'type' => 'mcq',
        ]);

        // Handle options as object {A: "...", B: "...", C: "...", D: "..."}
        $options = $request->options;
        $correctAnswer = $request->correct_answer;

        // If options is an object (frontend format), convert it
        if (isset($options['A']) || isset($options['B']) || isset($options['C']) || isset($options['D'])) {
            // Frontend format: {A: "...", B: "...", C: "...", D: "..."}
            foreach (['A', 'B', 'C', 'D'] as $key) {
                if (isset($options[$key]) && !empty($options[$key])) {
                    $question->options()->create([
                        'option_text' => $options[$key],
                        'is_correct' => ($key === $correctAnswer),
                    ]);
                }
            }
        } else {
            // Backend format: [{text: "...", is_correct: true/false}, ...]
            foreach ($options as $opt) {
                $question->options()->create([
                    'option_text' => $opt['text'] ?? $opt,
                    'is_correct' => $opt['is_correct'] ?? false,
                ]);
            }
        }

        return response()->json($this->formatQuestion($question->load('options')));
    }

    public function show(Question $question)
    {
        $question->load('options');
        return response()->json($this->formatQuestion($question));
    }

    /**
     * Format question to match frontend expectations
     */
    private function formatQuestion($question)
    {
        $options = [];
        $correctAnswer = null;
        
        // Sort options by ID to maintain consistent order
        $sortedOptions = $question->options->sortBy('id');
        
        foreach ($sortedOptions as $index => $option) {
            $optionKey = chr(65 + $index); // A=65, B=66, etc.
            $options[$optionKey] = $option->option_text;
            
            if ($option->is_correct) {
                $correctAnswer = $optionKey;
            }
        }

        return [
            'id' => $question->id,
            'question_text' => $question->question_text,
            'type' => $question->type,
            'marks' => $question->marks,
            'options' => $options,
            'correct_answer' => $correctAnswer,
            'explanation' => $question->explanation ?? null,
        ];
    }

    public function update(Request $request, Question $question)
    {
        $request->validate([
            'question_text' => 'sometimes|required|string',
            'options' => 'sometimes|required|array|min:2',
            'correct_answer' => 'sometimes|required|string|in:A,B,C,D',
        ]);

        $question->update($request->only('question_text'));

        if ($request->has('options')) {
            $question->options()->delete();
            
            $options = $request->options;
            $correctAnswer = $request->correct_answer ?? null;

            // Handle options as object {A: "...", B: "...", C: "...", D: "..."}
            if (isset($options['A']) || isset($options['B']) || isset($options['C']) || isset($options['D'])) {
                // Frontend format: {A: "...", B: "...", C: "...", D: "..."}
                foreach (['A', 'B', 'C', 'D'] as $key) {
                    if (isset($options[$key]) && !empty($options[$key])) {
                        $question->options()->create([
                            'option_text' => $options[$key],
                            'is_correct' => ($key === $correctAnswer),
                        ]);
                    }
                }
            } else {
                // Backend format: [{text: "...", is_correct: true/false}, ...]
                foreach ($options as $opt) {
                    $question->options()->create([
                        'option_text' => $opt['text'] ?? $opt,
                        'is_correct' => $opt['is_correct'] ?? false,
                    ]);
                }
            }
        }

        return response()->json($this->formatQuestion($question->load('options')));
    }

    public function destroy(Question $question)
    {
        $question->delete();
        return response()->json(['message' => 'Question deleted']);
    }
}
