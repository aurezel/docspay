<?php
/**
 * Created by PhpStorm.
 * User: hjl
 * Date: 2023/2/25
 * Time: 14:20
 */

namespace app\index\service;

class PlaceRapydOrderService extends BaseService
{
    public function placeOrder(array $params = [])
    {
        if (!$this->checkToken($params)) return apiError();
        $centralIdFile = app()->getRootPath() . DIRECTORY_SEPARATOR . 'file' . DIRECTORY_SEPARATOR . $params['center_id'] . '.txt';
        if (!file_exists($centralIdFile)) return apiError();
        $cid = customEncrypt($params['center_id']);
        $baseUrl = request()->domain();

        $cancel_checkout_url = $baseUrl . "/checkout/pay/rdRedirect?r_type=f&cid=$cid";
        $complete_checkout_url = $baseUrl . "/checkout/pay/rdRedirect?r_type=s&cid=$cid";

        $body = [
            "amount" =>  $params['amount'],
            "complete_checkout_url" => $complete_checkout_url,
            "country" =>$params['country'],
            "currency" => $params['currency'],
            "cancel_checkout_url" => $cancel_checkout_url,
            "language" => "en",
            'merchant_reference_id' => $cid,
            'payment_method_type_categories' => [
                'card'
            ]
        ];

        generateApiLog($body);
        try {
            $object = $this->makeRequest('post', '/v1/checkout', $body);
            if (!isset($object['status']['status']) || $object['status']['status'] !== 'SUCCESS') return apiError();
            $responseData = intval(env('stripe.merchant_token')) ? $object['data']['id'] : $object["data"]["redirect_url"];
            return apiSuccess($responseData);
        } catch(\Exception $e) {
            generateApiLog(['error' =>$e]);
            return apiError();
        }
    }


    public function makeRequest($method, $path, $body = null) {
        $base_url = 'https://api.rapyd.net';
        if (env('local_env')) $base_url = 'https://sandboxapi.rapyd.net';

        $access_key = env('stripe.public_key');     // The access key received from Rapyd.
        $secret_key = env('stripe.private_key'); //     // Never transmit the secret key by itself.

        $idempotency = randomStr(12);      // Unique for each request.
        $http_method = $method;                // Lower case.
        $salt = randomStr(12);             // Randomly generated for each request.
        $date = new \DateTime();
        $timestamp = $date->getTimestamp();    // Current Unix time.

        $body_string = !is_null($body) ? json_encode($body,JSON_UNESCAPED_SLASHES) : '';
        $sig_string = "$http_method$path$salt$timestamp$access_key$secret_key$body_string";

        $hash_sig_string = hash_hmac("sha256", $sig_string, $secret_key);
        $signature = base64_encode($hash_sig_string);

        $request_data = NULL;

        if ($method === 'post') {
            $request_data = array(
                CURLOPT_URL => "$base_url$path",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body_string,
                CURLOPT_SSL_VERIFYPEER => 0

            );
        } else {
            $request_data = array(
                CURLOPT_URL => "$base_url$path",
                CURLOPT_RETURNTRANSFER => true,
            );
        }

        $curl = curl_init();
        curl_setopt_array($curl, $request_data);

        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "access_key: $access_key",
            "salt: $salt",
            "timestamp: $timestamp",
            "signature: $signature",
            "idempotency: $idempotency"
        ));

        $response = curl_exec($curl);
        generateApiLog($response);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new \Exception("cURL Error #:".$err);
        } else {
            return json_decode($response, true);
        }
    }

    public function sendDataToCentral($status, $center_id, $payment_id = 0, $msg = '')
    {
        if (!in_array($status, ['success', 'failed'])) return false;
        // 发送到中控
        $postCenterData = [
            'transaction_id' => $payment_id,
            'center_id' => $center_id,
            'action' => 'create',
            'status' => $status,
            'failed_reason' => $msg
        ];
        $sendResult = json_decode(sendCurlData(CHANGE_PAY_STATUS_URL, $postCenterData, CURL_HEADER_DATA), true);
        if (!isset($sendResult['status']) or $sendResult['status'] == 0) {
            generateApiLog(REFERER_URL . '创建订单传送信息到中控失败：' . json_encode($sendResult));
            return false;
        }
        return true;
    }
}