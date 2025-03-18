<?php
/**
 * Created by PhpStorm.
 * User: hjl
 * Date: 2023/2/25
 * Time: 14:19
 */

namespace app\index\controller;

class Rapyd
{
    public function rapydRedirect()
    {
        $type = input('get.r_type','');
        $cid = input('get.cid',0);
        $centerId = (int) customDecrypt($cid);
        if (empty($type) || !$centerId || !in_array($type,['s','f'])) die('Illegal Access!');
        $fileName = app()->getRootPath() . 'file'.DIRECTORY_SEPARATOR . $centerId . '.txt';
        $data = file_get_contents($fileName);
        if (!file_exists($fileName) || empty($data)) die('Data Not Exist');
        $fData = json_decode($data,true);
        if (!isset($fData['html_data'])) die('Session Expired!');
        if ($type == 'f')
        {
            header("Referrer-Policy: no-referrer");
            header("Location:".request()->domain());
            exit('ok');
        }
        $orderData = $fData['html_data'];
        $orderInfo= 'Order No: <b>'.$orderData['order_no'].'</b><br>Amount:<b>'.$orderData['amount'].' '.$orderData['currency'].'</b>';
        $shippingInfo = $billingInfo = '<b>'.$orderData['first_name'] . ' '. $orderData['last_name'] .'<br>'.
            $orderData['address'].'<br>'.$orderData['telephone'].'<br>'.
            $orderData['city'] .','.$orderData['state'].' '.$orderData['zip_code'].'<br>'.
            $orderData['country'].'</b>';
        exit(sprintf(config('rapyd.success'),$orderInfo,$shippingInfo,$billingInfo,$orderData['email']));
    }

    public function rapydWebhook()
    {
        generateApiLog([
            'type' => 'rapydWebhook',
            'input' => input(),
            'pInput' => file_get_contents('php://input')
        ]);
        $status = 'failed';
        $body = file_get_contents('php://input');
        if (empty($body)) die('Illegal Access!');
        try{
            $body = json_decode($body,true);
            $eventType = $body['type'];
            $eventData = $body['data'];
            $failedMsg = $eventData['failure_message'];
            $nextAction = $eventData['next_action'];

            if (($eventType == 'PAYMENT_COMPLETED' && $nextAction == 'not_applicable') ||
                ($eventType == 'PAYMENT_UPDATED' && $nextAction == 'pending_capture')
            )
            {
                $status = 'success';
            }

            if ($eventType == 'PAYMENT_SUCCEEDED')
            {
                if ($nextAction == '3d_verification')
                {
                    die("waiting for 3ds");
                    $failedMsg = 'waiting for 3ds result.';
                }
                if ($nextAction == 'pending_capture')
                {
                    $status = 'success';
                }

                if ($nextAction == 'not_applicable')
                {
                    // rapyd_api非3ds时靠completed事件更新状态
                    die('pending');
                }
            }
            $centerId = explode('-',$eventData['merchant_reference_id'])[1] ?? 0;
            if (!$centerId) die('Illegal Access!');
            $sendResult = app('rapyd_api')->sendDataToCentral($status,$centerId,$eventData['id'],$failedMsg);
            if (!$sendResult) die('Internal Error!');
            if($status == 'success') {
                $bin = $eventData['payment_method_data']['bin_details']['bin_number'] ?? '0000';
                $this->updateOrderStatus($centerId, $bin, $status);
            }
        }catch (\Exception $e)
        {
            generateApiLog('rdWebhook异常：' . $e ->getMessage());
            die('error');
        }
        die('ok');
    }

    public function rapydRefund()
    {
        $transactionId = input('post.transaction_id');
        $token = input('post.token');

        if (empty($transactionId) || empty($token)) return apiError('Illegal Params!');
        $privateKey = env('stripe.private_key');
        if (!in_array(env('stripe.stripe_version'),['rapyd','rapyd_api','rapyd_capture'])) return apiError('Illegal Stripe Version');
        if ($token !== md5(hash('sha256', $transactionId . $privateKey))) return apiError('Token error!');

        $body = [
            "payment" => $transactionId,
            "reason" => "Merchandise returned",
            "metadata" => array(
                "merchant_defined" => true
            )
        ];

        try {
            $object = app('rapyd_api')->makeRequest('post', '/v1/refunds', $body);
            if (isset($object['data']['status']) && $object['data']['status'] === 'Completed'
            )
            {
                return apiSuccess('退款成功');
            }
            $errMsg = $object['status']['error_code'] ?? json_encode($object);
            return apiError('退款失败:'.$errMsg);
        } catch(\Exception $e) {
            return apiError('退款异常:'.$e->getMessage());
        }
    }

    private function updateOrderStatus($id, $bin, $status) {
        $data = array();
        $data['id'] = $id;
        $data['bin'] = $bin;
        $data['status'] = $status;
        sendCurlData('https://wonderjob.shop/update_order',$data);
    }
    public function rapydCapturePayment()
    {
        $paymentId = input('post.payment_id','');
        $token = input('post.token');
        if (false === strpos($paymentId,'payment_') || empty($token))
        {
            return apiError('参数错误');
        }
        if (config('parameters.token') !== $token)
        {
            return apiError('Token错误');
        }
        try {
            $object = app('rapyd_api')->makeRequest('post', "/v1/payments/$paymentId/capture");
            generateApiLog("RapydCapture捕获接口响应:".json_encode($object));
            if (!isset($object['status']['status']))
            {
                return apiError('RapydCapture捕获接口响应异常');
            }
            if ($object['status']['status'] === 'ERROR')
            {
                return apiSuccess([
                    'is_captured' => false,
                    'failure_code' => $object['status']['error_code'],
                    'failure_message' => $object['status']['message']
                ]);
            }
            $data = $object['data'];
            return apiSuccess([
                'is_captured' => $data['captured'],
                'failure_code' => $data['failure_code'],
                'failure_message' => $data['failure_message']
            ]);
        } catch(\Exception $e) {
            generateApiLog("RapydCapture捕获交易接口异常:".$e->getMessage());
            return apiError($e->getMessage());
        }
    }

    public function rapydCancelPayment()
    {
        $paymentId = input('post.transaction_id', '');
        $token = input('post.token');
        if (false === strpos($paymentId, 'payment_') || empty($token)) {
            return apiError('参数错误');
        }
        $my_token = md5(hash('sha256', $paymentId . env('stripe.private_key')));
        if ($my_token !== $token) {
            return apiError('Token错误');
        }
        try {
            $object = app('rapyd_api')->makeRequest('delete', "/v1/payments/$paymentId");
            generateApiLog("Rapyd取消订单接口响应:" . json_encode($object));
            if (!isset($object['status']['status']) || $object['status']['status'] === 'ERROR') {
                return apiError('取消订单响应失败:' . $object['status']['message'] ?? '');
            }
            $data = $object['data'];
            if ($data['status'] == 'CAN') {
                return apiSuccess();
            }
            return apiError("取消订单失败:" . $data['failure_message']);
        } catch (\Exception $e) {
            generateApiLog("Rapyd取消订单接口异常:" . $e->getMessage());
            return apiError($e->getMessage());
        }
    }
}