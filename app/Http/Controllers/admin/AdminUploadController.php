<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\QuestionPaper;
use App\Models\QuestionPaperUpload;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Support\Facades\Storage;
use App\Services\AIPdfParserService;

use Spatie\PdfToImage\Pdf as PdfToImage;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;



class AdminUploadController extends Controller
{
 // Upload PDF and create upload record
 public function upload(Request $request, $paperId)
 {
     $request->validate([
         'file' => 'required|mimes:pdf|max:15360', // 15MB
     ]);

     $paper = QuestionPaper::findOrFail($paperId);

     $path = $request->file('file')->store('paper_uploads', 'public');

     $upload = QuestionPaperUpload::create([
         'question_paper_id' => $paper->id,
         'file_path' => $path,
         'status' => 'uploaded',
     ]);

     return response()->json([
         'message' => 'File uploaded successfully',
         'upload_id' => $upload->id,
         'file_path' => $path,
     ]);
 }

   // Parse PDF (OCR + LLM)
   public function parse($paperId, $uploadId)
   {
       $upload = QuestionPaperUpload::findOrFail($uploadId);
       $upload->status = 'parsing';
       $upload->save();

       $pdfPath = storage_path('app/public/' . $upload->file_path);
       if (!file_exists($pdfPath)) {
           $upload->status = 'failed';
           $upload->save();
           return response()->json(['error' => 'Uploaded file not found'], 404);
       }

       try {
           // 1) Convert PDF pages to images
           $pdf = new PdfToImage($pdfPath);
           $pageCount = null;
           
           try {
               $pageCount = $pdf->getNumberOfPages();
           } catch (\Exception $e) {
               // Check if it's a Ghostscript error
               if (strpos($e->getMessage(), 'gswin64c') !== false || 
                   strpos($e->getMessage(), 'ghostscript') !== false ||
                   strpos($e->getMessage(), 'FailedToExecuteCommand') !== false) {
                   Log::error('Ghostscript not found', [
                       'error' => $e->getMessage(),
                       'upload_id' => $uploadId
                   ]);
                   
                   // Try fallback to text extraction
                   try {
                       $text = \Spatie\PdfToText\Pdf::getText($pdfPath);
                       if (strlen(trim($text)) > 50) {
                           $chunks = $this->chunkText($text, 2500);
                           $allTexts = [];
                           foreach ($chunks as $idx => $chunk) {
                               $allTexts[] = ['page' => $idx+1, 'text' => $chunk];
                           }
                           
                           // Skip to LLM processing
                           goto processWithLLM;
                       } else {
                           throw new \Exception('PDF text extraction returned insufficient text.');
                       }
                   } catch (\Throwable $fallbackError) {
                       $upload->status = 'failed';
                       $upload->save();
                       return response()->json([
                           'error' => 'Ghostscript is required for PDF processing. Ghostscript converts PDF pages to images.',
                           'detail' => $e->getMessage(),
                           'solution' => [
                               '1. Download Ghostscript from https://www.ghostscript.com/download/gsdnld.html',
                               '2. Install Ghostscript (Windows 64-bit version)',
                               '3. During installation, check "Add Ghostscript to the system PATH"',
                               '4. Restart Apache/XAMPP',
                               '5. Verify with: gswin64c --version (in command prompt)'
                           ],
                           'install_guide' => 'See GHOSTSCRIPT_INSTALL.md for detailed installation instructions.'
                       ], 500);
                   }
               } else {
                   // Re-throw if it's a different error
                   throw $e;
               }
           }
           
           // Only process pages if we have a valid page count (not using text extraction fallback)
           if ($pageCount !== null) {
               $allTexts = [];
               for ($i = 1; $i <= $pageCount; $i++) {
               $tmpImage = storage_path("app/temp/{$uploadId}_page_{$i}.jpg");
               @mkdir(dirname($tmpImage), 0755, true);
               $pdf->setPage($i)->saveImage($tmpImage);

               // 2) Run Tesseract OCR
               try {
                   $ocrText = (new TesseractOCR($tmpImage))
                       ->lang('eng') // add other langs if needed
                       ->run();
               } catch (\Exception $ocrError) {
                   // Check if it's a Tesseract not found error
                   if (strpos($ocrError->getMessage(), 'tesseract') !== false || 
                       strpos($ocrError->getMessage(), 'not found') !== false ||
                       strpos($ocrError->getMessage(), 'command') !== false) {
                       Log::error('Tesseract OCR not found', [
                           'error' => $ocrError->getMessage(),
                           'upload_id' => $uploadId,
                           'page' => $i
                       ]);
                       
                       // Skip OCR for this page, continue with next pages
                       // If all pages fail, fallback to text extraction will be used
                       $ocrText = '';
                   } else {
                       // Re-throw if it's a different error
                       throw $ocrError;
                   }
               }

               // cleanup basic noise
               $ocrText = preg_replace("/\x{FFFD}/u", " ", $ocrText);
               $ocrText = preg_replace('/\s+/', ' ', $ocrText);
               $ocrText = trim($ocrText);

               if (strlen($ocrText) > 20) {
                   $allTexts[] = ['page' => $i, 'text' => $ocrText];
               }

               @unlink($tmpImage);
           }

               // If no OCR results (maybe PDF has selectable text) try fast text extraction as fallback:
               if (count($allTexts) === 0) {
                   try {
                       // use spatie/pdf-to-text optionally (if installed)
                       $text = \Spatie\PdfToText\Pdf::getText($pdfPath);
                       if (strlen(trim($text)) > 50) {
                           // chunk long text into reasonable parts (~2000-3000 chars)
                           $chunks = $this->chunkText($text, 2500);
                           foreach ($chunks as $idx => $chunk) {
                               $allTexts[] = ['page' => $idx+1, 'text' => $chunk];
                           }
                       } else {
                           // No OCR results and text extraction returned insufficient text
                           // This likely means Tesseract is not installed
                           $upload->status = 'failed';
                           $upload->save();
                           return response()->json([
                               'error' => 'Tesseract OCR is required for PDF processing. Tesseract extracts text from PDF images.',
                               'detail' => 'No text could be extracted from the PDF. Please install Tesseract OCR.',
                               'solution' => [
                                   '1. Download Tesseract OCR from https://github.com/UB-Mannheim/tesseract/wiki',
                                   '2. Install Tesseract (Windows 64-bit version)',
                                   '3. During installation, check "Add to PATH"',
                                   '4. Restart Apache/XAMPP',
                                   '5. Verify with: tesseract --version (in command prompt)'
                               ],
                               'install_guide' => 'See TESSERACT_INSTALL.md for detailed installation instructions.'
                           ], 500);
                       }
                   } catch (\Throwable $e) {
                       // Text extraction also failed - provide helpful error
                       $upload->status = 'failed';
                       $upload->save();
                       return response()->json([
                           'error' => 'Tesseract OCR is required for PDF processing.',
                           'detail' => $e->getMessage(),
                           'solution' => [
                               '1. Download Tesseract OCR from https://github.com/UB-Mannheim/tesseract/wiki',
                               '2. Install Tesseract (Windows 64-bit version)',
                               '3. During installation, check "Add to PATH"',
                               '4. Restart Apache/XAMPP',
                               '5. Verify with: tesseract --version (in command prompt)'
                           ],
                           'install_guide' => 'See TESSERACT_INSTALL.md for detailed installation instructions.'
                       ], 500);
                   }
               }
           } // End of if ($pageCount !== null)

           // 3) Check if AI API key is available
           $apiKey = config('services.google.api_key') ?: env('GOOGLE_AI_API_KEY');
           $apiKey = $apiKey ? trim($apiKey, '"\' ') : null;
           $useAI = !empty($apiKey);
           
           if (!$useAI) {
               Log::info('AI API key not found, using direct text parsing', [
                   'upload_id' => $uploadId,
                   'chunks_count' => count($allTexts)
               ]);
           }
           
           // 4) Process chunks - either with AI or direct parsing
           processWithLLM:
           $parsedQuestions = [];
           
           if ($useAI) {
               Log::info('Starting LLM processing', [
                   'upload_id' => $uploadId,
                   'chunks_count' => count($allTexts)
               ]);
               
               foreach ($allTexts as $chunk) {
                   $prompt = $this->buildParsingPrompt($chunk['text'], $chunk['page']);
                   $llmResp = $this->callLLM($prompt);
               
               Log::debug('LLM response received', [
                   'upload_id' => $uploadId,
                   'page' => $chunk['page'],
                   'response_length' => strlen($llmResp),
                   'response_preview' => substr($llmResp, 0, 200)
               ]);
               
               // Check if response contains an error (not valid questions)
               if (is_string($llmResp) && (strpos($llmResp, '"error"') !== false || strpos($llmResp, 'API key') !== false)) {
                   Log::error('LLM response contains error', [
                       'upload_id' => $uploadId,
                       'page' => $chunk['page'],
                       'response' => substr($llmResp, 0, 500)
                   ]);
                   // Don't treat error messages as questions
                   continue;
               }
               
               $extracted = $this->safeJsonDecode($llmResp);
               
               Log::debug('JSON decode result', [
                   'upload_id' => $uploadId,
                   'page' => $chunk['page'],
                   'is_array' => is_array($extracted),
                   'count' => is_array($extracted) ? count($extracted) : 0,
                   'extracted_preview' => is_array($extracted) && count($extracted) > 0 ? json_encode($extracted[0]) : 'null'
               ]);
               
               if (is_array($extracted) && count($extracted) > 0) {
                   // Validate that extracted items are actually questions (not error messages)
                   $validQuestions = [];
                   foreach ($extracted as $q) {
                       // Check if it looks like a real question (has question_text)
                       if (isset($q['question_text']) && !empty(trim($q['question_text']))) {
                           // Check it's not an error message
                           $questionTextLower = strtolower($q['question_text']);
                           if (strpos($questionTextLower, 'api key') === false &&
                               strpos($questionTextLower, 'error') === false &&
                               strpos($questionTextLower, 'authorization') === false &&
                               strpos($questionTextLower, 'invalid') === false) {
                               
                               // Ensure options is properly formatted
                               if (isset($q['options']) && !is_array($q['options'])) {
                                   // Try to decode if it's a JSON string
                                   if (is_string($q['options'])) {
                                       $decodedOptions = json_decode($q['options'], true);
                                       if (is_array($decodedOptions)) {
                                           $q['options'] = $decodedOptions;
                                       } else {
                                           $q['options'] = null;
                                       }
                                   } else {
                                       $q['options'] = null;
                                   }
                               }
                               
                               // Set defaults for missing fields
                               $q['page'] = $chunk['page'];
                               $q['type'] = $q['type'] ?? 'mcq';
                               $q['marks'] = $q['marks'] ?? null;
                               $q['correct_answer'] = $q['correct_answer'] ?? null;
                               $q['explanation'] = $q['explanation'] ?? null;
                               
                               $validQuestions[] = $q;
                           }
                       }
                   }
                   
                   if (count($validQuestions) > 0) {
                       Log::info('Questions extracted from LLM', [
                           'upload_id' => $uploadId,
                           'page' => $chunk['page'],
                           'questions_count' => count($validQuestions)
                       ]);
                       
                       $parsedQuestions = array_merge($parsedQuestions, $validQuestions);
                   } else {
                       Log::warning('No valid questions extracted from LLM response', [
                           'upload_id' => $uploadId,
                           'page' => $chunk['page'],
                           'extracted_count' => count($extracted),
                           'first_item' => isset($extracted[0]) ? json_encode($extracted[0]) : 'none',
                           'response_preview' => substr($llmResp, 0, 1000)
                       ]);
                   }
               } else {
                   Log::warning('No questions extracted from LLM response - JSON decode failed', [
                       'upload_id' => $uploadId,
                       'page' => $chunk['page'],
                       'response_length' => strlen($llmResp),
                       'response_preview' => substr($llmResp, 0, 1000),
                       'json_error' => json_last_error_msg()
                   ]);
               }
               }
           } else {
               // Direct text parsing without AI
               Log::info('Starting direct text parsing', [
                   'upload_id' => $uploadId,
                   'chunks_count' => count($allTexts)
               ]);
               
               foreach ($allTexts as $chunk) {
                   // Log a sample of the text for debugging
                   $textSample = substr($chunk['text'], 0, 500);
                   Log::debug('Parsing text chunk', [
                       'upload_id' => $uploadId,
                       'page' => $chunk['page'],
                       'text_length' => strlen($chunk['text']),
                       'text_sample' => $textSample
                   ]);
                   
                   $questions = $this->parseQuestionsFromText($chunk['text'], $chunk['page']);
                   if (count($questions) > 0) {
                       $parsedQuestions = array_merge($parsedQuestions, $questions);
                       Log::info('Questions extracted from text', [
                           'upload_id' => $uploadId,
                           'page' => $chunk['page'],
                           'questions_count' => count($questions)
                       ]);
                   } else {
                       Log::warning('No questions found in text chunk', [
                           'upload_id' => $uploadId,
                           'page' => $chunk['page'],
                           'text_length' => strlen($chunk['text']),
                           'text_preview' => substr($chunk['text'], 0, 200)
                       ]);
                   }
               }
           }
           
           Log::info('Processing completed', [
               'upload_id' => $uploadId,
               'total_questions' => count($parsedQuestions),
               'used_ai' => $useAI
           ]);

           // Save parsed_data
           $upload->parsed_data = $parsedQuestions;
           $upload->status = 'completed';
           $upload->save();

           Log::info('Parse completed', [
               'upload_id' => $uploadId,
               'questions_count' => count($parsedQuestions),
               'status' => 'completed'
           ]);

           return response()->json([
               'message' => 'Parsing completed',
               'parsed_questions' => $parsedQuestions,
               'parsed_data' => $parsedQuestions, // Also include for compatibility
               'questions_count' => count($parsedQuestions),
           ]);
       } catch (\Exception $e) {
           $upload->status = 'failed';
           $upload->save();
           return response()->json(['error' => 'Parsing failed', 'detail' => $e->getMessage()], 500);
       }
   }

 // Return parsed JSON for preview
 public function getParsed($paperId, $uploadId)
 {
     $upload = QuestionPaperUpload::findOrFail($uploadId);
     $parsedData = $upload->parsed_data ?? [];
     
     // Ensure parsed_data is always an array
     if (!is_array($parsedData)) {
         $parsedData = [];
     }
     
     Log::info('Getting parsed questions', [
         'upload_id' => $uploadId,
         'status' => $upload->status,
         'questions_count' => count($parsedData)
     ]);
     
     return response()->json([
         'status' => $upload->status,
         'parsed_data' => $parsedData,
         'questions' => $parsedData, // Also include for compatibility
         'questions_count' => count($parsedData)
     ]);
 }

 // Save parsed questions to DB (admin-edited or raw parsed)
 public function saveParsed(Request $request, $paperId, $uploadId)
 {
     $upload = QuestionPaperUpload::findOrFail($uploadId);
     $data = $request->input('questions', $upload->parsed_data ?? []);

     if (!is_array($data)) {
         return response()->json(['message' => 'Invalid questions payload'], 422);
     }

     $savedCount = 0;
     foreach ($data as $q) {
         try {
             Question::create([
                 'question_paper_id' => $paperId,
                 'question_text' => $q['question_text'] ?? '',
                 'type' => $q['type'] ?? 'mcq',
                 'marks' => $q['marks'] ?? 1,
                 'options' => is_array($q['options']) ? json_encode($q['options']) : ($q['options'] ?? null),
                 'correct_answer' => $q['correct_answer'] ?? null,
                 'explanation' => $q['explanation'] ?? null,
             ]);
             $savedCount++;
         } catch (\Exception $e) {
             Log::error('Failed to save question', [
                 'paper_id' => $paperId,
                 'question' => $q,
                 'error' => $e->getMessage()
             ]);
         }
     }
     
     Log::info('Questions saved to database', [
         'paper_id' => $paperId,
         'upload_id' => $uploadId,
         'saved_count' => $savedCount,
         'total_count' => count($data)
     ]);

     $upload->status = 'completed';
     $upload->save();

     return response()->json(['message' => 'Questions saved successfully']);
 }

// Call the function to save the parsed questions to the database

 /**
  * Parse questions directly from text without AI
  * Extracts questions using pattern matching
  */
 private function parseQuestionsFromText($text, $page)
 {
     $questions = [];
     
     // Normalize text - clean up excessive whitespace but preserve structure
     $text = preg_replace('/\s+/', ' ', $text); // Replace all whitespace with single space
     $text = trim($text);
     
     if (strlen($text) < 20) {
         return $questions; // Too short to contain questions
     }
     
     // Split text by question numbers - handles inline text (common with OCR)
     // Pattern: split before "1. ", "2. ", etc.
     $parts = preg_split('/(?=\d+[\.\)]\s+)/', $text, -1, PREG_SPLIT_NO_EMPTY);
     
     foreach ($parts as $part) {
         $part = trim($part);
         if (empty($part) || strlen($part) < 10) continue;
         
         // Skip if it doesn't start with a number (like title text)
         if (!preg_match('/^\d+[\.\)]\s+/', $part)) continue;
         
         // Extract question number and the rest
         if (preg_match('/^(\d+)[\.\)]\s+(.+)$/i', $part, $qMatch)) {
             $rest = $qMatch[2];
             
             // Extract options - handle both proper format (A), B), C), D)) and OCR errors (A}, B}, C}, D})
             $options = [];
             
             // First, normalize malformed option markers
             // Handle patterns like: C}, CG}, C)}, etc. -> C)
             // This handles OCR errors where ) is misread as } or G} or other characters
             $rest = preg_replace('/([A-D])[Gg]?\s*[\)\}]+/i', '$1)', $rest);
             // Also handle cases where there's extra characters: C) -> C)
             $rest = preg_replace('/([A-D])\s*[\)\}]+/i', '$1)', $rest);
             
             // Find all option markers and their positions
             // Pattern: A), B), C), D) with optional spaces
             if (preg_match_all('/([A-D])\)\s*/i', $rest, $optMatches, PREG_OFFSET_CAPTURE)) {
                 $optPositions = [];
                 foreach ($optMatches[0] as $idx => $match) {
                     $optKey = strtoupper($optMatches[1][$idx][0]);
                     $optPos = $match[1];
                     $optLength = strlen($match[0]);
                     
                     // Find the end position - either next option, Answer, or question number
                     $endPos = strlen($rest);
                     
                     // Check for next option
                     if ($idx + 1 < count($optMatches[0])) {
                         $endPos = $optMatches[0][$idx + 1][1];
                     }
                     
                     // Check for Answer marker
                     $answerPos = stripos($rest, 'Answer', $optPos);
                     if ($answerPos !== false && $answerPos < $endPos) {
                         $endPos = $answerPos;
                     }
                     
                     // Check for next question number
                     if (preg_match('/\s+\d+[\.\)]\s+/', $rest, $qMatch, PREG_OFFSET_CAPTURE, $optPos)) {
                         $qPos = $qMatch[0][1];
                         if ($qPos < $endPos) {
                             $endPos = $qPos;
                         }
                     }
                     
                     // Extract the option value
                     $optValue = substr($rest, $optPos + $optLength, $endPos - $optPos - $optLength);
                     $optValue = trim($optValue);
                     
                     // Clean up - remove any trailing option markers that might have been included
                     $optValue = preg_replace('/\s+[A-D]\)\s*.*$/', '', $optValue);
                     $optValue = preg_replace('/\s*Answer\s*:.*$/i', '', $optValue);
                     $optValue = preg_replace('/\s+\d+[\.\)].*$/', '', $optValue);
                     $optValue = trim($optValue);
                     
                     // Only add if we have a valid value
                     if (!empty($optValue) && !isset($options[$optKey])) {
                         $options[$optKey] = $optValue;
                     }
                 }
             }
             
             // Extract answer - look for "Answer: X" or "Answer X"
             $answer = null;
             if (preg_match('/Answer\s*:\s*([A-D])/i', $rest, $ansMatch)) {
                 $answer = strtoupper(trim($ansMatch[1]));
             }
             
             // Extract question text - everything before the first option
             $questionText = $rest;
             // Find where first option starts
             if (preg_match('/^(.+?)(?=\s*[A-D]\))/i', $questionText, $qTextMatch)) {
                 $questionText = trim($qTextMatch[1]);
             } else {
                 // If no options found, remove answer and use rest as question
                 $questionText = preg_replace('/\s*Answer\s*:.*$/i', '', $questionText);
                 $questionText = trim($questionText);
             }
             
             // Clean up question text - remove any trailing numbers (next question)
             $questionText = preg_replace('/\s+\d+[\.\)].*$/', '', $questionText);
             $questionText = trim($questionText);
             
             // CRITICAL: Ensure all 4 options (A, B, C, D) are present
             // If any are missing, try to extract from malformed text or add placeholder
             $options = $this->ensureAllOptions($options, $rest);
             
             // Validate: need question text and at least 2 options (preferably all 4)
             if (strlen($questionText) > 10 && count($options) >= 2) {
                 $questions[] = [
                     'question_text' => $questionText,
                     'options' => $this->formatOptions($options),
                     'correct_answer' => $answer,
                     'type' => 'mcq',
                     'marks' => null,
                     'explanation' => null,
                     'page' => $page
                 ];
             }
         }
     }
     
     // If the above didn't work, try line-by-line parsing (for properly formatted text)
     if (count($questions) === 0) {
         $lines = explode("\n", $text);
         $currentQuestion = null;
         $currentOptions = [];
         $currentAnswer = null;
         
         foreach ($lines as $line) {
             $line = trim($line);
             if (empty($line)) continue;
             
             // Check if line is a question start
             if (preg_match('/^(\d+)[\.\)]\s+(.+)$/', $line, $m)) {
                 // Save previous question
                 if ($currentQuestion !== null) {
                     $questions[] = [
                         'question_text' => $currentQuestion,
                         'options' => $this->formatOptions($currentOptions),
                         'correct_answer' => $currentAnswer,
                         'type' => 'mcq',
                         'marks' => null,
                         'explanation' => null,
                         'page' => $page
                     ];
                 }
                 $currentQuestion = $m[2];
                 $currentOptions = [];
                 $currentAnswer = null;
             }
             // Check if line is an option
             elseif (preg_match('/^([A-D])[\.\)]\s*(.+)$/i', $line, $optMatch)) {
                 $optionKey = strtoupper(trim($optMatch[1]));
                 $currentOptions[$optionKey] = trim($optMatch[2]);
             }
             // Check if line contains the answer
             elseif (preg_match('/^Answer\s*:\s*([A-D])/i', $line, $ansMatch)) {
                 $currentAnswer = strtoupper(trim($ansMatch[1]));
             }
             // Append to question if no options yet
             elseif ($currentQuestion !== null && empty($currentOptions)) {
                 $currentQuestion .= ' ' . $line;
             }
         }
         
         // Save last question
         if ($currentQuestion !== null) {
             $questions[] = [
                 'question_text' => $currentQuestion,
                 'options' => $this->formatOptions($currentOptions),
                 'correct_answer' => $currentAnswer,
                 'type' => 'mcq',
                 'marks' => null,
                 'explanation' => null,
                 'page' => $page
             ];
         }
     }
     
     return $questions;
 }
 
 /**
  * Extract options (A, B, C, D) from text
  */
 private function extractOptions($text)
 {
     $options = [];
     
     // Pattern: A. option text, B. option text, etc.
     if (preg_match_all('/([A-D])[\.\)]\s*([^\n]+)/i', $text, $matches, PREG_SET_ORDER)) {
         foreach ($matches as $match) {
             $key = strtoupper(trim($match[1]));
             $value = trim($match[2]);
             // Remove trailing punctuation that might be part of next option
             $value = preg_replace('/\s*[A-D][\.\)].*$/', '', $value);
             $value = trim($value, '.,; ');
             
             if (strlen($value) > 0) {
                 $options[$key] = $value;
             }
         }
     }
     
     return $this->formatOptions($options);
 }
 
 /**
  * Ensure all 4 options (A, B, C, D) are present
  * If any are missing, try to extract from malformed text
  */
 private function ensureAllOptions($options, $text)
 {
     $requiredKeys = ['A', 'B', 'C', 'D'];
     $missingKeys = [];
     
     // Find which options are missing
     foreach ($requiredKeys as $key) {
         if (!isset($options[$key]) || empty(trim($options[$key]))) {
             $missingKeys[] = $key;
         }
     }
     
     // If all options are present, return as is
     if (empty($missingKeys)) {
         return $options;
     }
     
     // Try to find missing options in malformed text
     // Look for patterns like "CG}" which should be "C)"
     // Or text that appears between options that might be a missing option
     foreach ($missingKeys as $missingKey) {
         // Try to find malformed option markers like "CG}", "C}", etc.
         // Pattern: missingKey followed by G or other chars and }
         $malformedPattern = '/' . $missingKey . '[Gg]?\s*[\)\}]+/i';
         if (preg_match($malformedPattern, $text, $malformedMatch, PREG_OFFSET_CAPTURE)) {
             $startPos = $malformedMatch[0][1] + strlen($malformedMatch[0][0]);
             
             // Find where this option value ends
             $endPos = strlen($text);
             
             // Look for next option, Answer, or question number
             if (preg_match('/\s+([A-D])\)/i', $text, $nextOpt, PREG_OFFSET_CAPTURE, $startPos)) {
                 $endPos = $nextOpt[0][1];
             } elseif (stripos($text, 'Answer', $startPos) !== false) {
                 $endPos = stripos($text, 'Answer', $startPos);
             } elseif (preg_match('/\s+\d+[\.\)]\s+/', $text, $nextQ, PREG_OFFSET_CAPTURE, $startPos)) {
                 $endPos = $nextQ[0][1];
             }
             
             // Extract the option value
             $optValue = substr($text, $startPos, $endPos - $startPos);
             $optValue = trim($optValue);
             
             // Clean up
             $optValue = preg_replace('/\s*Answer\s*:.*$/i', '', $optValue);
             $optValue = preg_replace('/\s+\d+[\.\)].*$/', '', $optValue);
             $optValue = trim($optValue);
             
             if (!empty($optValue)) {
                 $options[$missingKey] = $optValue;
             }
         }
     }
     
     // Use formatOptions which will ensure all 4 keys are present (with empty strings if still missing)
     return $this->formatOptions($options);
 }
 
 /**
  * Format options as object with A, B, C, D keys
  * ALWAYS returns all 4 options - adds empty string for missing ones
  */
 private function formatOptions($options)
 {
     $formatted = [];
     
     // Ensure we have A, B, C, D keys - ALWAYS include all 4
     $keys = ['A', 'B', 'C', 'D'];
     foreach ($keys as $key) {
         if (isset($options[$key]) && !empty(trim($options[$key]))) {
             $formatted[$key] = trim($options[$key]);
         } else {
             // Add empty string for missing options to ensure all 4 are present
             $formatted[$key] = '';
         }
     }
     
     // If we have options but not in standard format, try to map them
     if (empty(array_filter($formatted)) && !empty($options)) {
         $values = array_values($options);
         foreach ($keys as $idx => $key) {
             if (isset($values[$idx])) {
                 $formatted[$key] = trim($values[$idx]);
             } else {
                 $formatted[$key] = '';
             }
         }
     }
     
     return $formatted;
 }
 
 private function buildParsingPrompt($text, $page)
 {
     $instruction = <<<EOT
You are an assistant that extracts questions and options from exam text. The text may contain questions in English, Tamil, or both languages side by side.

CRITICAL INSTRUCTIONS:
1. Extract ALL questions from the text, even if they appear in both English and Tamil
2. For bilingual questions, prefer the English version for question_text, but ensure all options are included
3. Return ONLY a valid JSON array - no markdown, no code blocks, no explanations
4. Each question must have question_text, options (as object with A, B, C, D keys), type, correct_answer, marks, explanation

Output format - array of objects with these exact keys:
- question_text (string) - The question text. Use English if both languages present.
- options (object) - Must be an object with keys "A", "B", "C", "D" and string values. Example: {"A":"120 Ω","B":"480 Ω","C":"340 Ω","D":"240.5 Ω"}
- correct_answer (string or null) - "A", "B", "C", "D" or null if not visible
- type (string) - "mcq" for multiple choice
- marks (integer or null) - Number or null
- explanation (string or null) - Text or null

IMPORTANT RULES:
- Extract EVERY question you find in the text
- Include ALL options (A, B, C, D) for each question
- If options appear in both languages, use English version
- Return a complete JSON array, not partial JSON
- Do not include any text before or after the JSON array

Example output:
[{"question_text":"An incandescent lamp is operated at 240 V and the current is 0.5 A. What is the resistance of the lamp?","options":{"A":"120 Ω","B":"480 Ω","C":"340 Ω","D":"240.5 Ω"},"correct_answer":null,"type":"mcq","marks":null,"explanation":null}]

Now extract all questions from this text (page {$page}):
EOT;
     return $instruction . "\n\n" . $text;
 }

 private function callLLM($prompt, $retryCount = 0, $maxRetries = 3)
 {
     // Use Google Gemini AI
     $apiKey = config('services.google.api_key') ?: env('GOOGLE_AI_API_KEY');
     
     // Remove any quotes or whitespace that might be in .env file
     $apiKey = trim($apiKey, " \t\n\r\0\x0B\"'");
     
     // Check if API key is set
     if (empty($apiKey)) {
         Log::error('Google Gemini API key not configured', [
             'check' => 'GOOGLE_AI_API_KEY in .env file',
             'env_value' => env('GOOGLE_AI_API_KEY') ? 'Set (length: ' . strlen(env('GOOGLE_AI_API_KEY')) . ')' : 'Not set',
             'config_value' => config('services.google.api_key') ? 'Set (length: ' . strlen(config('services.google.api_key')) . ')' : 'Not set'
         ]);
         throw new \Exception('Google Gemini API key is not configured. Please set GOOGLE_AI_API_KEY in your .env file and run: php artisan config:clear');
     }
     
     Log::debug('Using Google Gemini API key', [
         'key_length' => strlen($apiKey),
         'key_preview' => substr($apiKey, 0, 7) . '...' . substr($apiKey, -4),
         'retry_attempt' => $retryCount
     ]);
     
     // Use Gemini 2.5 Pro or available models
     $defaultModel = env('GOOGLE_AI_MODEL', 'gemini-2.0-flash-exp');
     
     // Handle Gemini 2.5 Pro - try different possible model names
     // Gemini 2.5 Pro might be available with different names in v1beta API
     if (strpos(strtolower($defaultModel), '2.5') !== false || 
         strpos(strtolower($defaultModel), 'gemini-2.5') !== false) {
         // Try Gemini 2.5 Pro model names (may vary by API version)
         $models = [
             'gemini-2.0-flash-exp',           // Most likely name for 2.5 Pro
             'gemini-2.0-flash-thinking-exp',  // Thinking version
             'gemini-2.5-pro',                  // Direct name (if available)
             'gemini-2.0-pro-exp',             // Alternative name
             'gemini-1.5-flash',                // Fallback
             'gemini-1.5-pro'                   // Fallback
         ];
     } else {
         // Available Gemini models for v1beta API
         $models = [
             $defaultModel,
             'gemini-2.0-flash-exp',           // Fast experimental model
             'gemini-2.0-flash-thinking-exp',  // Thinking model
             'gemini-1.5-flash',               // Fallback
             'gemini-1.5-pro'                  // Fallback (may not be available in v1beta)
         ];
     }
     
     $model = $models[min($retryCount, count($models) - 1)];
     
     // Determine API version - v1beta is more stable and widely supported
     // Use v1beta by default (responseMimeType not supported in v1beta)
     $apiVersion = 'v1beta';
     $url = "https://generativelanguage.googleapis.com/{$apiVersion}/models/{$model}:generateContent?key={$apiKey}";
     
     Log::debug('Using Gemini API', [
         'model' => $model,
         'api_version' => $apiVersion,
         'url' => str_replace($apiKey, '***', $url)
     ]);

     // Gemini API format for v1beta (responseMimeType is NOT supported)
     $payload = [
         'contents' => [
             [
                 'parts' => [
                     [
                         'text' => 'You extract questions and return JSON only. ' . $prompt
                     ]
                 ]
             ]
         ],
         'generationConfig' => [
             'temperature' => 0.0,
             'maxOutputTokens' => 2048
             // Note: responseMimeType is NOT supported in v1beta API
             // JSON format is requested in the prompt text instead
         ]
     ];

     $resp = Http::withHeaders([
         'Content-Type' => 'application/json',
     ])->post($url, $payload);

     if ($resp->failed()) {
         $errorBody = $resp->body();
         $errorJson = $resp->json();
         $errorMessage = $errorJson['error']['message'] ?? $errorBody;
         
         Log::warning('Google Gemini API request failed', [
             'status' => $resp->status(),
             'error' => $errorMessage,
             'model' => $model,
             'retry_attempt' => $retryCount,
             'response' => $errorBody
         ]);
         
         // Check for specific error types
         if (strpos($errorBody, 'API key') !== false || strpos($errorBody, 'API_KEY_INVALID') !== false || strpos($errorBody, 'authentication') !== false) {
             throw new \Exception('Google Gemini API key is invalid or missing. Please check your GOOGLE_AI_API_KEY in .env file.');
         }
         
         // Check for model not found error - try different model
         $isModelNotFound = (
             strpos($errorBody, 'not found') !== false ||
             strpos($errorBody, 'NOT_FOUND') !== false ||
             strpos($errorBody, 'not supported') !== false ||
             $resp->status() === 404
         );
         
         // Check for rate limit or overload errors - retry with exponential backoff
         $isRetryableError = (
             strpos($errorBody, 'overloaded') !== false ||
             strpos($errorBody, 'rate limit') !== false ||
             strpos($errorBody, 'quota') !== false ||
             strpos($errorBody, 'RESOURCE_EXHAUSTED') !== false ||
             strpos($errorBody, 'UNAVAILABLE') !== false ||
             $resp->status() === 429 ||
             $resp->status() === 503
         );
         
         // If model not found, try next model
         if ($isModelNotFound && $retryCount < $maxRetries) {
             Log::info('Model not found, trying next model', [
                 'failed_model' => $model,
                 'retry_attempt' => $retryCount + 1,
                 'max_retries' => $maxRetries
             ]);
             
             // Try next model without waiting (model selection happens in next call)
             return $this->callLLM($prompt, $retryCount + 1, $maxRetries);
         }
         
         if ($isRetryableError && $retryCount < $maxRetries) {
             // Exponential backoff: 2^retryCount seconds (2s, 4s, 8s)
             $waitTime = pow(2, $retryCount);
             Log::info('Retrying Gemini API request', [
                 'retry_attempt' => $retryCount + 1,
                 'max_retries' => $maxRetries,
                 'wait_seconds' => $waitTime,
                 'model' => $model
             ]);
             
             sleep($waitTime);
             return $this->callLLM($prompt, $retryCount + 1, $maxRetries);
         }
         
         throw new \Exception('Google Gemini API request failed: ' . $errorMessage . ($isRetryableError || $isModelNotFound ? ' (Tried ' . ($retryCount + 1) . ' times with different models)' : ''));
     }

     // Parse Gemini response
     $json = $resp->json();
     
     // Check for error in response
     if (isset($json['error'])) {
         $errorMessage = $json['error']['message'] ?? 'Unknown error';
         
         // Check if it's a retryable error
         $isRetryableError = (
             strpos($errorMessage, 'overloaded') !== false ||
             strpos($errorMessage, 'rate limit') !== false ||
             strpos($errorMessage, 'quota') !== false ||
             strpos($errorMessage, 'RESOURCE_EXHAUSTED') !== false ||
             strpos($errorMessage, 'UNAVAILABLE') !== false
         );
         
         if ($isRetryableError && $retryCount < $maxRetries) {
             $waitTime = pow(2, $retryCount);
             Log::info('Retrying Gemini API request (error in response)', [
                 'retry_attempt' => $retryCount + 1,
                 'max_retries' => $maxRetries,
                 'wait_seconds' => $waitTime,
                 'error' => $errorMessage
             ]);
             
             sleep($waitTime);
             return $this->callLLM($prompt, $retryCount + 1, $maxRetries);
         }
         
         Log::error('Google Gemini API returned error', [
             'error' => $json['error']
         ]);
         throw new \Exception('Google Gemini API error: ' . $errorMessage);
     }
     
     // Gemini response format: candidates[0].content.parts[0].text
     if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
         $responseText = $json['candidates'][0]['content']['parts'][0]['text'];
         // Remove markdown code blocks if present
         $responseText = preg_replace('/```json\s*/', '', $responseText);
         $responseText = preg_replace('/```\s*/', '', $responseText);
         $responseText = trim($responseText);
         
         Log::debug('Gemini API request successful', [
             'model' => $model,
             'response_length' => strlen($responseText)
         ]);
         
         return $responseText;
     }

     // Fallback
     Log::warning('Unexpected Gemini response format', [
         'response' => $json
     ]);
     return $resp->body();
 }

 private function safeJsonDecode($str)
 {
     if (!$str || strlen(trim($str)) === 0) return null;

     // Clean the string first
     $str = trim($str);
     
     // Remove markdown code blocks if present
     $str = preg_replace('/^```json\s*/', '', $str);
     $str = preg_replace('/^```\s*/', '', $str);
     $str = preg_replace('/\s*```$/', '', $str);
     $str = trim($str);
     
     // Try direct decode
     $decoded = json_decode($str, true);
     if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
         return $decoded;
     }
     
     Log::debug('Direct JSON decode failed', [
         'json_error' => json_last_error_msg(),
         'str_preview' => substr($str, 0, 200)
     ]);

     // Try to extract JSON array from text (handle cases where there's extra text)
     // Look for array pattern: [ ... ]
     if (preg_match('/\[[\s\S]*\]/', $str, $matches)) {
         $maybe = $matches[0];
         $decoded = json_decode($maybe, true);
         if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
             Log::debug('Extracted JSON array from text');
             return $decoded;
         }
     }
     
     // Try to find JSON object array pattern
     if (preg_match('/\{[\s\S]*\}/', $str, $matches)) {
         // If it's a single object, wrap it in an array
         $maybe = $matches[0];
         $decoded = json_decode($maybe, true);
         if (json_last_error() === JSON_ERROR_NONE) {
             // If it's a single object, make it an array
             if (is_array($decoded) && isset($decoded['question_text'])) {
                 Log::debug('Found single question object, wrapping in array');
                 return [$decoded];
             }
             if (is_array($decoded)) {
                 return $decoded;
             }
         }
     }
     
     Log::warning('All JSON decode attempts failed', [
         'json_error' => json_last_error_msg(),
         'str_length' => strlen($str),
         'str_preview' => substr($str, 0, 500)
     ]);
     
     return null;
 }

 private function chunkText($text, $size = 2000)
 {
     $text = trim($text);
     $len = strlen($text);
     if ($len <= $size) return [$text];
     $chunks = [];
     $pos = 0;
     while ($pos < $len) {
         $chunks[] = substr($text, $pos, $size);
         $pos += $size;
     }
     return $chunks;
 }
   
}
