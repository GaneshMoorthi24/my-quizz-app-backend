<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Spatie\PdfToText\Pdf; // example

class QuestionParserController extends Controller
{
    public function parseQuestions(Request $request)
    {
        $text = $request->input('text'); // PDF extracted text

        if (!$text) {
            return response()->json(['error' => 'No text provided'], 400);
        }

        // Send the extracted text to GPT API for structured parsing
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-5',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an AI that extracts quiz questions from text in English and Tamil. Format output as JSON with fields: question, options, correct_answer, language.'
                ],
                [
                    'role' => 'user',
                    'content' => $text
                ]
            ],
            'temperature' => 0.2,
        ]);

        $parsed = $response->json();

        return response()->json([
            'parsed_questions' => $parsed['choices'][0]['message']['content'] ?? ''
        ]);
    }

    public function uploadPdf(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:pdf']);
        $path = $request->file('file')->store('pdfs');
        $fullPath = storage_path('app/'.$path);

        // extract text (synchronous example)
        $text = Pdf::getText($fullPath);

        // Optionally dispatch a job for parsing
        $parsed = $this->parseWithAI($text, basename($path));

        return response()->json(['parsed_questions' => $parsed]);
    }

}
