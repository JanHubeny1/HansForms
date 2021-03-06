<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDateInputsAnswersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('date_inputs_answers', function (Blueprint $table) {
            $table->id();
            $table->date("value")->nullable();
            $table->timestamps();

            //fk: form_completion_id, date_input_id
            $table->foreignId('form_completion_id')->constrained('form_completions')->onDelete('cascade');
            $table->foreignId('date_input_id')->constrained('date_inputs')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('date_inputs_answers');
    }
}
