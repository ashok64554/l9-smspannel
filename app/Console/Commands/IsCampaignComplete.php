<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IsCampaignComplete extends Command
{
    protected $signature = 'check:campaign';
    protected $description = 'Mark campaigns as completed based on delivery stats';

    public function handle()
    {
        $fromTime = now()->subMinutes(30);

        $stats = DB::table('send_sms_queues')
            ->select(
                'send_sms_id',

                DB::raw("SUM(stat = 'DELIVRD') AS total_delivered"),
                DB::raw("SUM(stat = 'BLACK') AS total_block_number"),
                DB::raw("SUM(stat = 'Invalid') AS total_invalid_number"),
                DB::raw("SUM(stat NOT IN ('DELIVRD','BLACK','Invalid','Pending','Accepted')) AS total_failed")
            )
            ->whereIn('send_sms_id', function ($q) use ($fromTime) {
                $q->select('id')
                  ->from('send_sms')
                  ->where('status', 'Ready-to-complete')
                  ->where('campaign', 'API')
                  ->where('campaign_send_date_time', '>=', $fromTime);
            })
            ->groupBy('send_sms_id')
            ->get();

        foreach ($stats as $row) {

            DB::table('send_sms')
                ->where('id', $row->send_sms_id)
                ->update([
                    'total_delivered'      => $row->total_delivered,
                    'total_failed'         => $row->total_failed,
                    'total_block_number'   => $row->total_block_number,
                    'total_invalid_number' => $row->total_invalid_number,
                ]);
        }

        DB::statement("
            UPDATE send_sms
            SET status = 'Completed'
            WHERE status = 'Ready-to-complete'
              AND campaign = 'API'
              AND total_contacts <= (
                  total_block_number
                + total_invalid_number
                + total_delivered
                + total_failed
              )
        ");

        $this->info('Campaign completion check finished.');
        return Command::SUCCESS;
    }
}
