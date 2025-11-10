<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
public function up()
{
    Schema::create('question_paper_uploads', function (Blueprint $table) {
        $table->id();
        $table->foreignId('question_paper_id')->constrained('question_papers')->onDelete('cascade');
        $table->string('file_path');
        $table->enum('status', ['uploaded', 'parsing', 'completed', 'failed'])->default('uploaded');
        $table->json('parsed_data')->nullable(); // temporary storage for parsed questions
        $table->timestamps();
    });
}


    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('question_paper_uploads');
    }
};
