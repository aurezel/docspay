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
        if ($type == 's')
        {
            $url = $fData['s_url'];
        }else{
            $url = $fData['f_url'];
        }
        header("Location:".$url);
        exit();
    }

    public function rapydWebhook()
    {
        generateApiLog([
            'type' => 'rapydWebhook',
            'input' => input(),
            'pInput' => file_get_contents('php://input')
        ]);
        $status = 'success';
        $body = file_get_contents('php://input');
        if (empty($body)) die('Illegal Access!');
        try{
            $body = json_decode($body,true);
            if ($body['type'] === 'PAYMENT_SUCCEEDED') die('ok');
            if ($body['type'] !== 'PAYMENT_COMPLETED') $status = 'failed';
            $data = $body['data'];
            $centerId = (int) customDecrypt($data['merchant_reference_id']);
            if (!$centerId) die('Illegal Access!');
            $sendResult = app('rapyd')->sendDataToCentral($status,$centerId,$data['id'],$data['failure_message']);
            if (!$sendResult) die('Internal Error!');
        }catch (\Exception $e)
        {
            generateApiLog('rdWebhook异常：' . $e ->getMessage());
            die('error');
        }
        die('ok');
    }
}