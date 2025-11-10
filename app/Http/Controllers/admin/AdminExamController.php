<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminExamController extends Controller
{
    public function index()
    {
        return Exam::all();
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $exam = Exam::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
        ]);
        return response()->json($exam);
    }

    public function show(Exam $exam)
    {
        return $exam;
    }

    public function update(Request $request, Exam $exam)
    {
        $exam->update($request->only('name'));
        return response()->json($exam);
    }

    public function destroy(Exam $exam)
    {
        $exam->delete();
        return response()->json(['message' => 'Exam deleted']);
    }
}
