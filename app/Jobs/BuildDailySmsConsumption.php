<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BuildDailySmsConsumption implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $date;

    public function __construct($date)
    {
        $this->date = Carbon::parse($date)->toDateString();
    }

    public function handle()
    {
        $from = $this->date . ' 00:00:00';
        $to   = $this->date . ' 23:59:59';

        // SMS
        $rows = DB::table('send_sms as s')
            ->join('send_sms_histories as h', 'h.send_sms_id', '=', 's.id')
            ->select(
                's.user_id',
                DB::raw("'{$this->date}' as report_date"),
                DB::raw('COUNT(h.id) as total_submission'),
                DB::raw('SUM(h.use_credit) as total_credit_submission'),

                DB::raw("SUM(h.stat='DELIVRD') as delivered_count"),
                DB::raw("SUM(h.stat='Invalid') as invalid_count"),
                DB::raw("SUM(h.stat='BLACK') as black_count"),
                DB::raw("SUM(h.stat='EXPIRED') as expired_count"),
                DB::raw("SUM(h.stat IN ('FAILED','UNDELIV')) as failed_count"),
                DB::raw("SUM(h.stat='REJECTD') as rejected_count"),
                DB::raw("SUM(h.stat NOT IN ('DELIVRD','Invalid','BLACK','EXPIRED','FAILED','REJECTD','UNDELIV')) as process_count")
            )
            ->whereBetween('s.campaign_send_date_time', [$from, $to])
            ->groupBy('s.user_id')
            ->get();

        foreach ($rows as $row) {
            DB::table('sms_daily_consumptions')->updateOrInsert(
                [
                    'user_id' => $row->user_id,
                    'report_for' => 'Text SMS',
                    'report_date' => $this->date,
                ],
                [
                    'total_submission' => $row->total_submission,
                    'total_credit_submission' => $row->total_credit_submission,
                    'delivered_count' => $row->delivered_count,
                    'invalid_count' => $row->invalid_count,
                    'black_count' => $row->black_count,
                    'expired_count' => $row->expired_count,
                    'failed_count' => $row->failed_count,
                    'rejected_count' => $row->rejected_count,
                    'process_count' => $row->process_count,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }


        // Notification
        if($this->date != date('Y-m-d'))
        {
            $logs = \DB::table('sms_daily_consumptions')->whereBetween('report_date', [$from, $to])->get();
            foreach ($logs as $key => $log) 
            {
                $userInfo = \DB::table('users')->find($log->user_id);

                if($userInfo)
                {
                    /////notification and mail//////
                    $variable_data = [
                        '{{date}}' => $this->date,
                        '{{no_of_credit}}' => $log->total_credit_submission,
                        '{{channel_name}}' => 'TEXT SMS',
                        '{{no_of_submission}}' => $log->total_submission
                    ];
                    notification('total-credit-used', $userInfo, $variable_data);
                }
            }
        }
    }
}
