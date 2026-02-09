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
        Schema::create('whats_app_chat_bot_session_api_records', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('whats_app_configuration_id')->nullable();

            $table->json('request_payload')->nullable();

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
        Schema::dropIfExists('whats_app_chat_bot_session_api_records');
    }
};
