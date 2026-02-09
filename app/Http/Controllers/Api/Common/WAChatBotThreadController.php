<?php

namespace App\Http\Controllers\Api\Common;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\WhatsAppConfiguration;
use App\Models\WhatsAppTemplate;
use App\Models\WhatsAppSendSms;
use App\Models\WhatsAppSendSmsQueue;
use App\Models\WhatsAppSendSmsHistory;
use App\Models\WhatsAppReplyThread;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;
use Auth;
use DB;
use Exception;
use Excel;
use Carbon\Carbon;
use App\Imports\CampaignImport;
use Log;

class WAChatBotThreadController extends Controller
{
    protected $intime;
    public function __construct()
    {
        $this->intime = \Carbon\Carbon::now();
        $this->middleware('permission:whatsapp-sms');
    }

    public function waReplyThreadUsers(Request $request)
    {
        /*
        select MAX(`id`), `profile_name`, `phone_number_id`, (select message FROM whats_app_reply_threads wa_table where wa_table.id=MAX(wa_inner.`id`)) as message FROM whats_app_reply_threads wa_inner group by `phone_number_id` order by `id` desc;
        */

        try {
            $latestIds = \DB::table('whats_app_reply_threads')
            ->selectRaw('MAX(id) as id')
            ->groupBy('profile_name');

            $query = \DB::table('whats_app_reply_threads as wa')
                ->joinSub($latestIds, 'latest', function ($join) {
                    $join->on('wa.id', '=', 'latest.id');
                })
                ->select(
                    'wa.id',
                    'wa.user_id',
                    'wa.profile_name',
                    'wa.phone_number_id',
                    'wa.message'
                )
                ->orderBy('wa.id', 'DESC');


            if(in_array(loggedInUserType(), [1,2]))
            {
                $getNumbers = \DB::table('whats_app_configurations')
                ->where('user_id', auth()->id())->pluck('display_phone_number_req');
                $query->where(function($q) use ($getNumbers) {
                    $q->where('phone_number_id', $getNumbers)
                        ->orWhere('display_phone_number', $getNumbers);
                });
            }

            if(!empty($request->user_id))
            {
                $getNumbers = \DB::table('whats_app_configurations')->where('user_id', auth()->id())->pluck('display_phone_number_req');
                $query->where(function($q) use ($getNumbers) {
                    $q->where('phone_number_id', $getNumbers)
                        ->orWhere('display_phone_number', $getNumbers);
                });
            }

            if(!empty($request->whats_app_send_sms_id))
            {
                $query->where('whats_app_send_sms_id', $request->whats_app_send_sms_id);
            }

            if(!empty($request->queue_history_unique_key))
            {
                $query->where('queue_history_unique_key', $request->queue_history_unique_key);
            }

            if(!empty($request->phone_number))
            {
                $query->where('phone_number_id', 'LIKE', '%'.$request->phone_number.'%');
            }

            $query = $query->get();

            return response()->json(prepareResult(false, $query, trans('translate.fetched_records'), $this->intime), config('httpcodes.success'));
        } catch (\Throwable $e) {
            \Log::error($e);
            return response()->json(prepareResult(true, $e->getMessage(), trans('translate.something_went_wrong'), $this->intime), config('httpcodes.internal_server_error'));
        }
    }

    /*public function waReplyThreadCaseWise(Request $request)
    {
        try {
            // do not change model query, 
            // if you want to change orderBy then also reorder the read record list.
            $query = WhatsAppReplyThread::orderBy('id', 'DESC')
            ->with('WhatsAppSendSms:id,campaign,sender_number,message,whats_app_configuration_id', 'WhatsAppSendSmsQueue:id,message,sender_number,mobile','WhatsAppSendSmsHistory:id,message,sender_number,mobile');

            if(in_array(loggedInUserType(), [1,2]))
            {
                $getNumbers = \DB::table('whats_app_configurations')->where('user_id', auth()->id())->pluck('display_phone_number_req');
                $query->where(function($q) use ($getNumbers) {
                    $q->where('whats_app_reply_threads.phone_number_id', $getNumbers)
                        ->orWhere('whats_app_reply_threads.display_phone_number', $getNumbers);
                });
            }

            if(!empty($request->user_id))
            {
                $getNumbers = \DB::table('whats_app_configurations')->where('user_id', auth()->id())->pluck('display_phone_number_req');
                $query->where(function($q) use ($getNumbers) {
                    $q->where('whats_app_reply_threads.phone_number_id', $getNumbers)
                        ->orWhere('whats_app_reply_threads.display_phone_number', $getNumbers);
                });
            }

            if(!empty($request->whats_app_send_sms_id))
            {
                $query->where('whats_app_reply_threads.whats_app_send_sms_id', $request->whats_app_send_sms_id);
            }

            if(!empty($request->queue_history_unique_key))
            {
                $query->where('whats_app_reply_threads.queue_history_unique_key', $request->queue_history_unique_key);
            }

            if(!empty($request->phone_number))
            {
                $phone_number = $request->phone_number;
                $query->where(function($q) use ($phone_number) {
                    $q->where('whats_app_reply_threads.phone_number_id', $phone_number)
                        ->orWhere('whats_app_reply_threads.display_phone_number', $phone_number);
                });
            }

            if(!empty($request->per_page_record))
            {
                $perPage = $request->per_page_record;
                $page = $request->input('page', 1);
                $total = $query->count();
                $result = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

                $last_customer_rpl_id = null;
                if(count($result)>0)
                {
                    foreach ($result as $key => $value) 
                    {
                        if($value->is_vendor_reply!=1)
                        {
                            $last_customer_rpl_id = $value->id;
                            $getConfInfo = WhatsAppConfiguration::find(@$value->WhatsAppSendSms->whats_app_configuration_id);
                            if($getConfInfo)
                            {
                                //\Log::info($getConfInfo);
                                $access_token = base64_decode($getConfInfo->access_token);
                                $sender_number = $getConfInfo->sender_number;
                                $appVersion = $getConfInfo->app_version;
                                $response_token = $value->response_token;
                                wAReplyMessageRead($access_token, $sender_number, $appVersion, $response_token);
                            }
                            break;
                        }
                    }
                }

                $pagination =  [
                    'data' => $result->reverse()->values(),
                    'total' => $total,
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'last_page' => ceil($total / $perPage),
                    'last_message_id' => $last_customer_rpl_id
                ];
                $query = $pagination;
            }
            else
            {
                $query = $query->get();
            }

            return response()->json(prepareResult(false, $query, trans('translate.fetched_records'), $this->intime), config('httpcodes.success'));
        } catch (\Throwable $e) {
            \Log::error($e);
            return response()->json(prepareResult(true, $e->getMessage(), trans('translate.something_went_wrong'), $this->intime), config('httpcodes.internal_server_error'));
        }
    }*/

    public function waReplyThreadCaseWise(Request $request)
    {
        try {

            $perPage = (int) ($request->per_page_record ?? 20);

            $query = WhatsAppReplyThread::query()->with([
                    'WhatsAppSendSms:id,campaign,sender_number,message,whats_app_configuration_id',
                    'WhatsAppSendSmsQueue:id,message,sender_number,mobile',
                    'WhatsAppSendSmsHistory:id,message,sender_number,mobile'
                ])
                ->orderBy('id', 'DESC');

            if (in_array(loggedInUserType(), [1, 2])) {
                $getNumbers = \DB::table('whats_app_configurations')
                    ->where('user_id', auth()->id())
                    ->pluck('display_phone_number_req');

                $query->where(function($q) use ($getNumbers) {
                    $q->where('whats_app_reply_threads.phone_number_id', $getNumbers)
                        ->orWhere('whats_app_reply_threads.display_phone_number', $getNumbers);
                });
            }

            if(!empty($request->user_id))
            {
                $getNumbers = \DB::table('whats_app_configurations')->where('user_id', auth()->id())->pluck('display_phone_number_req');
                $query->where(function($q) use ($getNumbers) {
                    $q->where('whats_app_reply_threads.phone_number_id', $getNumbers)
                        ->orWhere('whats_app_reply_threads.display_phone_number', $getNumbers);
                });
            }

            if ($request->filled('whats_app_send_sms_id')) {
                $query->where('whats_app_send_sms_id', $request->whats_app_send_sms_id);
            }

            if ($request->filled('queue_history_unique_key')) {
                $query->where('queue_history_unique_key', $request->queue_history_unique_key);
            }

            if ($request->filled('phone_number')) {
                $query->where(function ($q) use ($request) {
                    $q->where('phone_number_id', $request->phone_number)
                      ->orWhere('display_phone_number', $request->phone_number);
                });
            }

            $cursor = $request->input('cursor');
            $data = $query->cursorPaginate(
                $perPage,
                ['*'],
                'cursor',
                $cursor
            );

            $lastCustomerReply = null;

            foreach ($data->items() as $row) {
                if ($row->is_vendor_reply != 1) {
                    $lastCustomerReply = $row;
                    break;
                }
            }

            if ($lastCustomerReply) {
                dispatch(new \App\Jobs\WhatsAppReadReceiptJob(
                    $lastCustomerReply->response_token,
                    optional($lastCustomerReply->WhatsAppSendSms)->whats_app_configuration_id
                ))
                ->onConnection('redis')
                ->onQueue('whatsapp');
            }

            $response = [
                    'data' => collect($data->items())->reverse()->values(), 
                    'cursor' => [
                        'next' => optional($data->nextCursor())->encode(),
                        'prev' => optional($data->previousCursor())->encode(),
                    ],
                    'has_more' => $data->hasMorePages(),
                    'last_message_id' => optional($lastCustomerReply)->id,
                    'per_page' => $data->perPage(),
                ];

            return response()->json(
                prepareResult(false, $response, trans('translate.fetched_records'), $this->intime),
                config('httpcodes.success')
            );

        } catch (\Throwable $e) {
            \Log::error($e);
            return response()->json(
                prepareResult(true, $e->getMessage(), trans('translate.something_went_wrong'), $this->intime),
                config('httpcodes.internal_server_error')
            );
        }
    }

    public function waSendReplyMessage(Request $request)
    {
        $validation = \Validator::make($request->all(), [
            'whats_app_configuration_id' => 'nullable|exists:whats_app_configurations,id',
            'whats_app_reply_thread_id' => 'nullable|exists:whats_app_reply_threads,id',
            'type' => 'required|in:text,image,video,document'
        ]);

        if ($validation->fails()) {
            return response()->json(prepareResult(true, $validation->messages(), trans('translate.validation_failed'), $this->intime), config('httpcodes.bad_request'));
        }

        if(in_array(loggedInUserType(), [0,3]))
        {
            $validation = \Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
            ]);

            if ($validation->fails()) {
                return response()->json(prepareResult(true, $validation->messages(), trans('translate.validation_failed'), $this->intime), config('httpcodes.bad_request'));
            }

            $user_id = $request->user_id;
        }
        else
        {
            $user_id = auth()->id();
        }

        if(!empty($request->phone_number_id) && !empty($request->display_phone_number))
        {
            $getConfInfo = WhatsAppConfiguration::where('user_id', $user_id)
                ->where(function($q) use ($request) {
                    $q->where('display_phone_number_req', $request->phone_number_id)
                        ->orWhere('display_phone_number_req', $request->display_phone_number);
                })
                ->first();

            $to_number = (($getConfInfo->display_phone_number_req==$request->phone_number_id) ? $request->display_phone_number : $request->phone_number_id);
        }
        else
        {
            $getConfInfo = WhatsAppConfiguration::where('user_id', $user_id)->find($request->whats_app_configuration_id);
        }

        
        if(!$getConfInfo)
        {
            return response()->json(prepareResult(true, [], trans('translate.record_not_found'), $this->intime), config('httpcodes.not_found'));
        }
        $access_token = base64_decode($getConfInfo->access_token);
        $sender_number = $getConfInfo->sender_number;
        $appVersion = $getConfInfo->app_version;

        $queue_history_unique_key = null;
        $whats_app_send_sms_id = null;

        try {
            if(!empty($request->whats_app_reply_thread_id))
            {
                $getRecord = WhatsAppReplyThread::where('user_id', $user_id)->with('user:id,name')->find($request->whats_app_reply_thread_id);
                if($getRecord)
                {
                    $queue_history_unique_key = $getRecord->queue_history_unique_key;
                    $whats_app_send_sms_id = $getRecord->whats_app_send_sms_id;
                    $to_number = $getRecord->display_phone_number;
                }
                else
                {
                    return response()->json(prepareResult(true, [], trans('translate.record_not_found'), $this->intime), config('httpcodes.not_found'));
                }
            }

            $response = waSendReplyMsg($access_token, $sender_number, $appVersion, $to_number, $request->type, $request->message, $request->response_token, $request->file_name);
            if($response['error']==false)
            {
                $response = json_decode($response['response'], true);

                $phone_number_id = $getConfInfo->display_phone_number_req;
                $display_phone_number = $to_number;
                $profile_name = $getConfInfo->user->name;

                $wa_reply_threads[] = [
                    'queue_history_unique_key' => $queue_history_unique_key,
                    'whats_app_send_sms_id' => $whats_app_send_sms_id,
                    'user_id' => $user_id,
                    'profile_name' => $profile_name,
                    'phone_number_id' => $phone_number_id,
                    'display_phone_number' => $display_phone_number,
                    'user_mobile' => $display_phone_number,
                    'message' => $request->message,
                    'context_ref_wa_id' => null,
                    'error_info' => null,
                    'received_date' => date('Y-m-d H:i:s'),
                    'response_token' => @$response['messages'][0]['id'],
                    'use_credit' => null,
                    'is_vendor_reply' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                executeWAReplyThreds($wa_reply_threads);

                return response()->json(prepareResult(false, $wa_reply_threads, trans('translate.created'), $this->intime), config('httpcodes.created'));
            }

            return response()->json(prepareResult(true, $response, trans('translate.record_not_found'), $this->intime), config('httpcodes.not_found'));
        } catch (\Throwable $e) {
            \Log::error($e);
            return response()->json(prepareResult(true, $e->getMessage(), trans('translate.something_went_wrong'), $this->intime), config('httpcodes.internal_server_error'));
        }
    }

    public function waDownloadReplyFile(Request $request)
    {
        $validation = \Validator::make($request->all(), [
            'whats_app_reply_thread_id' => 'required|exists:whats_app_reply_threads,id',
            'whats_app_configuration_id' => 'required|exists:whats_app_configurations,id',
        ]);

        if ($validation->fails()) {
            return response()->json(prepareResult(true, $validation->messages(), trans('translate.validation_failed'), $this->intime), config('httpcodes.bad_request'));
        }

        if(in_array(loggedInUserType(), [0,3]))
        {
            $validation = \Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
            ]);

            if ($validation->fails()) {
                return response()->json(prepareResult(true, $validation->messages(), trans('translate.validation_failed'), $this->intime), config('httpcodes.bad_request'));
            }

            $user_id = $request->user_id;
        }
        else
        {
            $user_id = auth()->id();
        }

        $getConfInfo = WhatsAppConfiguration::where('user_id', $user_id)->find($request->whats_app_configuration_id);
        if(!$getConfInfo)
        {
            return response()->json(prepareResult(true, [], trans('translate.record_not_found'), $this->intime), config('httpcodes.not_found'));
        }

        try {

            $getRecord = WhatsAppReplyThread::where('user_id', $user_id)->find($request->whats_app_reply_thread_id);
            if($getRecord)
            {
                $access_token = base64_decode($getConfInfo->access_token);
                $mediaId = $getRecord->media_id;
                $appVersion = $getConfInfo->app_version;
                
                $data = getMediaFileFromWA($access_token, $mediaId, $appVersion);

                $getRecord->media_url = $data;
                $getRecord->save();

                return response()->json(prepareResult(false, $getRecord, trans('translate.synced'), $this->intime), config('httpcodes.success'));
            }

            return response()->json(prepareResult(true, [], trans('translate.record_not_found'), $this->intime), config('httpcodes.not_found'));

        } catch (\Throwable $e) {
            \Log::error($e);
            return response()->json(prepareResult(true, $e->getMessage(), trans('translate.something_went_wrong'), $this->intime), config('httpcodes.internal_server_error'));
        }
    }

}
