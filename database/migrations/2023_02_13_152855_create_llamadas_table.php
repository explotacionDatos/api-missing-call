<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLlamadasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('llamadas', function (Blueprint $table) {
            // $table->id();
            $table->bigIncrements('id_llamada_estado');
            $table->string('id_llamada')->nullable(); 
            $table->string('cola')->nullable();
            $table->string('numero_llamante')->nullable();
            $table->string('fecha')->nullable();
            $table->string('hora')->nullable();
            $table->string('estado')->nullable();
            $table->string('estado_tramitacion')->nullable();
            $table->string('grupo_id')->nullable();
            $table->boolean('no_visible')->default(0);
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
        Schema::dropIfExists('llamadas');
    }
}
