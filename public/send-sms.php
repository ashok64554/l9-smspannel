<?php

$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => 'http://localhost:8000/api/login',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_POSTFIELDS =>'{
    "email": "reseller1@nrt.co.in",
    "password": "123456"
}',
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
));

$response = curl_exec($curl);
echo '<pre>';
curl_close($curl);
$decode = json_decode($response);
$data = $decode->data;

/* token update */

$postData = [
  'user_id' =>  2,
  'campaign' =>  'Test Campaign2',
  'secondary_route_id' =>  1,
  'dlt_template_id' =>  $_REQUEST['dlt_template_id'],
  'route_type' =>  1,
  'sms_type' =>  1,
  'message' =>  null,
  'same_as_template' =>  false,
  'campaign_send_date_time' =>  null,
  'is_flash' =>  0,
  'priority' =>  1,
  'mobile_numbers' =>  $_REQUEST['mobile_numbers'],
  'file_path' => @empty($_REQUEST['file_path']) ? null : $_REQUEST['file_path'],
  'file_mobile_field_name' => @empty($_REQUEST['file_mobile_field_name']) ? null : $_REQUEST['file_mobile_field_name'],
  'contact_group_ids' => []
];
$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => 'http://localhost:8000/api/send-sms',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_POSTFIELDS => json_encode($postData),
  CURLOPT_HTTPHEADER => array(
    'Authorization: Bearer '.$data->access_token,
    'Content-Type: application/json'
  ),
));

$response = curl_exec($curl);

curl_close($curl);
echo $response;