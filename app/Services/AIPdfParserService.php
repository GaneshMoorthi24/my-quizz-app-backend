<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\PdfToImage\Pdf as PdfToImage;
use Illuminate\Support\Facades\Storage;

class AIPdfParserService
{
    private $apiKey;
    private $apiUrl;
    private $model;
    
    public function __construct()
    {
        // Support multiple AI providers
        $provider = config('services.ai.provider', 'openai'); // openai, anthropic, google
        
        if ($provider === 'openai') {
            $this->apiKey = config('services.openai.api_key');
            if (empty($this->apiKey)) {
                throw new \Exception("OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.");
            }
            $this->apiUrl = 'https://api.openai.com/v1/chat/completions';
            $this->model = config('services.openai.vision_model', 'gpt-4o'); // gpt-4o, gpt-4-turbo, gpt-4-vision-preview
        } elseif ($provider === 'anthropic') {
            $this->apiKey = config('services.anthropic.api_key');
            if (empty($this->apiKey)) {
                throw new \Exception("Anthropic API key is not configured. Please set ANTHROPIC_API_KEY in your .env file.");
            }
            $this->apiUrl = 'https://api.anthropic.com/v1/messages';
            $this->model = config('services.anthropic.model', 'claude-3-5-sonnet-20241022');
        } elseif ($provider === 'google') {
            $this->apiKey = config('services.google.api_key');
            if (empty($this->apiKey)) {
                throw new \Exception("Google AI API key is not configured. Please set GOOGLE_AI_API_KEY in your .env file.");
            }
            $this->apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro-vision:generateContent';
            $this->model = 'gemini-pro-vision';
        } else {
            throw new \Exception("Invalid AI provider: {$provider}. Supported providers: openai, anthropic, google");
        }
    }
    
    /**
     * Parse PDF using AI Vision API
     * @param string $pdfPath Full path to PDF file
     * @param string $language Language to extract ('english' or 'tamil')
     * @return array Extracted questions
     */
    public function parsePdf($pdfPath, $language = 'english')
    {
        Log::info("Starting AI PDF parsing", [
            'pdf_path' => $pdfPath,
            'language' => $language,
            'provider' => config('services.ai.provider', 'openai')
        ]);
        
        try {
            // Check if ImageMagick is available
            if (!extension_loaded('imagick')) {
                throw new \Exception("ImageMagick PHP extension is not installed. Please install ImageMagick to use AI PDF parsing. See IMAGEMAGICK_INSTALLATION.md for instructions.");
            }
            
            // Convert PDF to images
            $pdf = new PdfToImage($pdfPath);
            $pageCount = $pdf->getNumberOfPages();
            
            Log::info("PDF converted to images", ['page_count' => $pageCount]);
            
            // Create temp directory for images
            $tempDir = storage_path('app/temp');
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            $allQuestions = [];
            
            // Process pages in batches (to avoid token limits)
            $pagesPerBatch = 2; // Process 2 pages at a time
            
            for ($i = 1; $i <= $pageCount; $i += $pagesPerBatch) {
                $endPage = min($i + $pagesPerBatch - 1, $pageCount);
                
                Log::info("Processing pages {$i} to {$endPage}");
                
                $images = [];
                $imagePaths = [];
                
                // Convert pages to images
                for ($page = $i; $page <= $endPage; $page++) {
                    $imagePath = $tempDir . "/page_{$page}.jpg";
                    $pdf->setPage($page)->saveImage($imagePath);
                    
                    if (file_exists($imagePath)) {
                        $imagePaths[] = $imagePath;
                        $images[] = [
                            'path' => $imagePath,
                            'base64' => base64_encode(file_get_contents($imagePath))
                        ];
                    }
                }
                
                if (empty($images)) {
                    continue;
                }
                
                // Extract questions from this batch using AI
                $questions = $this->extractQuestionsFromImages($images, $language, $i);
                
                if (!empty($questions)) {
                    $allQuestions = array_merge($allQuestions, $questions);
                }
                
                // Clean up image files
                foreach ($imagePaths as $imgPath) {
                    if (file_exists($imgPath)) {
                        unlink($imgPath);
                    }
                }
            }
            
            Log::info("AI PDF parsing completed", [
                'total_questions' => count($allQuestions)
            ]);
            
            return $allQuestions;
            
        } catch (\Exception $e) {
            Log::error("AI PDF parsing error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    /**
     * Extract questions from images using AI Vision
     * @param array $images Array of image data
     * @param string $language Language to extract
     * @param int $startQuestionNumber Starting question number
     * @return array Extracted questions
     */
    private function extractQuestionsFromImages($images, $language, $startQuestionNumber = 1)
    {
        $provider = config('services.ai.provider', 'openai');
        
        if ($provider === 'openai') {
            return $this->extractWithOpenAI($images, $language, $startQuestionNumber);
        } elseif ($provider === 'anthropic') {
            return $this->extractWithAnthropic($images, $language, $startQuestionNumber);
        } elseif ($provider === 'google') {
            return $this->extractWithGoogle($images, $language, $startQuestionNumber);
        }
        
        throw new \Exception("Unsupported AI provider: {$provider}");
    }
    
    /**
     * Extract questions using OpenAI GPT-4 Vision
     */
    private function extractWithOpenAI($images, $language, $startQuestionNumber)
    {
        $languageName = $language === 'tamil' ? 'Tamil' : 'English';
        
        $prompt = $this->buildExtractionPrompt($language);
        
        $messages = [
            [
                'role' => 'system',
                'content' => "You are an expert at extracting questions from exam papers. You must return ONLY valid JSON in the exact format specified. Do not include any explanation, markdown, or additional text."
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $prompt
                    ]
                ]
            ]
        ];
        
        // Add images to the message
        foreach ($images as $image) {
            $messages[1]['content'][] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:image/jpeg;base64,' . $image['base64']
                ]
            ];
        }
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post($this->apiUrl, [
                'model' => $this->model,
                'messages' => $messages,
                'max_tokens' => 4000,
                'temperature' => 0.1, // Low temperature for consistent extraction
                'response_format' => ['type' => 'json_object'], // Request JSON response
            ]);
            
            if (!$response->successful()) {
                Log::error("OpenAI API error", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new \Exception("OpenAI API request failed: " . $response->body());
            }
            
            $responseData = $response->json();
            
            // Check for errors in response
            if (isset($responseData['error'])) {
                Log::error("OpenAI API returned error", [
                    'error' => $responseData['error']
                ]);
                throw new \Exception("OpenAI API error: " . ($responseData['error']['message'] ?? 'Unknown error'));
            }
            
            $content = $responseData['choices'][0]['message']['content'] ?? '';
            
            if (empty($content)) {
                Log::error("OpenAI returned empty content", [
                    'response' => $responseData
                ]);
                return [];
            }
            
            // Extract JSON from response (handle markdown code blocks)
            $content = preg_replace('/```json\s*/', '', $content);
            $content = preg_replace('/```\s*$/', '', $content);
            $content = trim($content);
            
            $questions = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Failed to parse AI response as JSON", [
                    'error' => json_last_error_msg(),
                    'content_preview' => substr($content, 0, 500),
                    'full_content_length' => strlen($content)
                ]);
                return [];
            }
            
            // Ensure questions array
            if (!isset($questions['questions']) || !is_array($questions['questions'])) {
                // Try direct array
                if (is_array($questions) && isset($questions[0])) {
                    $questions = ['questions' => $questions];
                } else {
                    Log::warning("Questions not found in expected format", [
                        'response_keys' => array_keys($questions ?? []),
                        'response_preview' => substr(json_encode($questions), 0, 500)
                    ]);
                    return [];
                }
            }
            
            // Add question numbers
            foreach ($questions['questions'] as $index => &$question) {
                $question['question_number'] = $startQuestionNumber + $index;
            }
            
            return $questions['questions'];
            
        } catch (\Exception $e) {
            Log::error("OpenAI extraction error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Extract questions using Anthropic Claude
     */
    private function extractWithAnthropic($images, $language, $startQuestionNumber)
    {
        $prompt = $this->buildExtractionPrompt($language);
        
        $content = [
            [
                'type' => 'text',
                'text' => $prompt
            ]
        ];
        
        // Add images
        foreach ($images as $image) {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/jpeg',
                    'data' => $image['base64']
                ]
            ];
        }
        
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(120)->post($this->apiUrl, [
                'model' => $this->model,
                'max_tokens' => 4096,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $content
                    ]
                ]
            ]);
            
            if (!$response->successful()) {
                throw new \Exception("Anthropic API request failed: " . $response->body());
            }
            
            $responseData = $response->json();
            $content = $responseData['content'][0]['text'] ?? '';
            
            // Extract JSON
            $content = preg_replace('/```json\s*/', '', $content);
            $content = preg_replace('/```\s*$/', '', $content);
            $content = trim($content);
            
            $questions = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            
            if (!isset($questions['questions']) || !is_array($questions['questions'])) {
                if (is_array($questions) && isset($questions[0])) {
                    $questions = ['questions' => $questions];
                } else {
                    return [];
                }
            }
            
            foreach ($questions['questions'] as $index => &$question) {
                $question['question_number'] = $startQuestionNumber + $index;
            }
            
            return $questions['questions'];
            
        } catch (\Exception $e) {
            Log::error("Anthropic extraction error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Extract questions using Google Gemini
     */
    private function extractWithGoogle($images, $language, $startQuestionNumber)
    {
        $prompt = $this->buildExtractionPrompt($language);
        
        $parts = [
            ['text' => $prompt]
        ];
        
        // Add images
        foreach ($images as $image) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data' => $image['base64']
                ]
            ];
        }
        
        try {
            $response = Http::timeout(120)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => $parts
                    ]
                ]
            ]);
            
            if (!$response->successful()) {
                throw new \Exception("Google API request failed: " . $response->body());
            }
            
            $responseData = $response->json();
            $content = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
            
            // Extract JSON
            $content = preg_replace('/```json\s*/', '', $content);
            $content = preg_replace('/```\s*$/', '', $content);
            $content = trim($content);
            
            $questions = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            
            if (!isset($questions['questions']) || !is_array($questions['questions'])) {
                if (is_array($questions) && isset($questions[0])) {
                    $questions = ['questions' => $questions];
                } else {
                    return [];
                }
            }
            
            foreach ($questions['questions'] as $index => &$question) {
                $question['question_number'] = $startQuestionNumber + $index;
            }
            
            return $questions['questions'];
            
        } catch (\Exception $e) {
            Log::error("Google extraction error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Build extraction prompt for AI
     */
    private function buildExtractionPrompt($language)
    {
        $languageName = $language === 'tamil' ? 'Tamil' : 'English';
        $languageInstruction = $language === 'tamil' 
            ? "Extract ONLY Tamil text. Remove all English text completely."
            : "Extract ONLY English text. Remove all Tamil text completely.";
        
        return "Extract all multiple-choice questions from these PDF pages.

IMPORTANT INSTRUCTIONS:
1. Extract questions in {$languageName} language ONLY. {$languageInstruction}
2. Each question should have:
   - question_text: The question text (in {$languageName} only)
   - options: Object with A, B, C, D as keys (in {$languageName} only)
   - correct_answer: Leave empty string
   - explanation: Leave empty string
   - question_number: Sequential number starting from 1

3. Questions may be numbered (1., 2., etc.) or unnumbered
4. Options may be labeled as (A), (B), (C), (D) or A), B), C), D) or A., B., C., D.
5. Some questions may have bilingual text - extract ONLY {$languageName} text
6. Handle questions that span multiple lines
7. Handle options that are on the same line or separate lines

OUTPUT FORMAT - You MUST return valid JSON in this exact structure:
{
  \"questions\": [
    {
      \"question_text\": \"Question text in {$languageName} only\",
      \"options\": {
        \"A\": \"Option A text in {$languageName} only\",
        \"B\": \"Option B text in {$languageName} only\",
        \"C\": \"Option C text in {$languageName} only\",
        \"D\": \"Option D text in {$languageName} only\"
      },
      \"correct_answer\": \"\",
      \"explanation\": \"\",
      \"question_number\": 1
    }
  ]
}

CRITICAL: Return ONLY the JSON object above. Do NOT include markdown code blocks, explanations, or any other text. Start with { and end with }.";
    }
}

