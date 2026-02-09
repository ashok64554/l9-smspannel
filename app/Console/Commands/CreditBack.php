<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\SendSms;
use App\Models\DlrcodeVender;
use App\Models\SendSmsQueue;
use App\Models\SendSmsHistory;
use App\Models\VoiceSms;
use App\Models\VoiceSmsQueue;
use App\Models\VoiceSmsHistory;
use Log;
use Illuminate\Support\Facades\DB;

class CreditBack extends Command
{
    protected $signature = 'credit:back';

    protected $description = 'Command description';
public function handle()
    {
        $this->archiveYesterdayCampaigns(5000);
        return Command::SUCCESS;
    }

    public function archiveYesterdayCampaigns(int $campaignBatch = 1000): void
    {
        $yesterday = now()->subDay()->toDateString();
        $lastId = 0;

        while (true) {

            $campaignIds = DB::table('send_sms as s')
                ->whereDate('s.campaign_send_date_time', $yesterday)
                ->whereNotIn('s.status', ['Pending', 'In-process'])
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('send_sms_queues as q')
                      ->whereColumn('q.send_sms_id', 's.id');
                })
                ->where('s.id', '>', $lastId)
                ->orderBy('s.id')
                ->limit($campaignBatch)
                ->pluck('s.id');

            if ($campaignIds->isEmpty()) {
                break;
            }

            $lastId = $campaignIds->last();

            DB::transaction(function () use ($campaignIds) 
            {
                DB::statement("
                    INSERT INTO send_sms_histories
                    (
                        send_sms_id, primary_route_id, unique_key, mobile, message,
                        use_credit, is_auto, stat, err, status,
                        submit_date, done_date, response_token, sub, dlvrd,
                        created_at, updated_at
                    )
                    SELECT
                        send_sms_id, primary_route_id, unique_key, mobile, message,
                        use_credit, is_auto, stat, err, status,
                        submit_date, done_date, response_token, sub, dlvrd,
                        created_at, updated_at
                    FROM send_sms_queues
                    WHERE send_sms_id IN (" . $campaignIds->implode(',') . ")
                ");

                DB::table('send_sms_queues')
                    ->whereIn('send_sms_id', $campaignIds)
                    ->delete();

                DB::table('send_sms')
                    ->whereIn('id', $campaignIds)
                    ->update([
                        'status'           => 'Completed',
                        'credit_back_date' => now()
                    ]);
            });
        }
    }}
