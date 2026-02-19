<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_daily_consumptions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->string('report_for')->default('Text SMS')->comment('Like: Text SMS, Voice, Whatsapp, Email, RCS');
            $table->date('report_date');

            $table->unsignedBigInteger('total_submission')->default(0);
            $table->decimal('total_credit_submission', 15, 4)->default(0);

            $table->unsignedBigInteger('delivered_count')->default(0);
            $table->unsignedBigInteger('invalid_count')->default(0);
            $table->unsignedBigInteger('black_count')->default(0);
            $table->unsignedBigInteger('expired_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->unsignedBigInteger('rejected_count')->default(0);
            $table->unsignedBigInteger('process_count')->default(0);

            $table->timestamps();

            $table->unique(['user_id', 'report_date'], 'uniq_user_date');
            $table->index(['report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_daily_consumptions');
    }
};
