<?php

namespace app\index\service;

class PlaceWorldPayOrderService extends BaseService
{
    public function placeOrder(array $params = [])
    {
        if (!$this->checkToken($params)) return apiError();
        $postData = $params;
        $private_key = env('stripe.private_key');
        $currency_dec = config('parameters.currency_dec');


        try {

            $headers = [

                'Content-Type:application/vnd.worldpay.verified-tokens-v1.hal+json'
            ];
            $url = 'https://try.access.worldpay.com/verifiedTokens/sessions';
            $requestData = [
                'identity' => 'efdf8ca3-97fe-4b79-9034-0daabe16f669', //
                'cardExpiryDate' => [
                    'month' => intval($postData['expiry_month']),
                    'year' => intval('20'.$postData['expiry_year'])
                ],
                'cvc' => intval($postData['cvc']),
                'cardNumber' => intval(str_replace(' ','',$postData['card_number']))
            ];
           // return apiError($requestData);
            try{

                $responseData = $this->wpHttp($url,$requestData,$headers);
                file_put_contents('step1.json',json_encode($responseData));
                if (isset($responseData['_links']['verifiedTokens:session']['href']))
                {

                    $url = 'https://try.access.worldpay.com/verifiedTokens';
                    $sessionUrl = $responseData['_links']['verifiedTokens:session']['href'];
                    $responseData = $this->wpHttp($url,[],$headers,false);
                    file_put_contents('step2.json',json_encode($responseData));
                    if (isset($responseData['_links']['verifiedTokens:cardOnFile']['href']))
                    {
                        $address1 = $postData['address1'];
                        $address2 = $postData['address2'];
                        $requestData = array (
                            'description' => 'Token-Description',
                            'paymentInstrument' =>
                                array (
                                    'type' => 'card/checkout',
                                    'cardHolderName' => $postData['first_name'] . ' ' . $postData['last_name'],
                                    'sessionHref' => $sessionUrl,
                                    'billingAddress' =>
                                        array (
                                            'address1' => $address1,
                                            'address2' => $address2,
                                            'address3' => '',
                                            'postalCode' => $postData['zip'],
                                            'city' => $postData['city'],
                                            'state' => $postData['state'],
                                            'countryCode' => $postData['country'],
                                        ),
                                ),
                            'narrative' =>
                                array (
                                    'line1' => $address1,
                                    'line2' => $address2,
                                ),
                            'merchant' =>
                                array (
                                    'entity' => 'MindPalaceLtd',
                                ),
                            'verificationCurrency' => $postData['currency'],
                        );
                        $responseData = $this->wpHttp($responseData['_links']['verifiedTokens:cardOnFile']['href'],$requestData,$headers);
                        file_put_contents('step3.json',json_encode($responseData));
                    }

                }

                return apiError($responseData);
            }catch (\Exception $e)
            {
                generateApiLog('创建Session异常:'.$e->getMessage());
                return apiError();
            }
//            if(isset($currency_dec[strtoupper($postData['currency_code'])])) {
//                $amount = $postData['amount'];
//                for($i = 0; $i < $currency_dec[strtoupper($postData['currency_code'])]; $i++) {
//                    $amount *= 10;
//                }
//            }




        }catch (\Exception $ex) {
            $orderNo = $postData['order_no'] ?? 0;
            $centerId = $postData['center_id'] ?? 0;
            generateApiLog([
                '创建订单异常',
                "订单ID：{$orderNo}",
                "中控ID：{$centerId}",
                '错误信息：' => [
                    'msg' => $ex->getMessage(),
                    'code' => $ex->getCode(),
                    'line' => $ex->getLine(),
                    'trace' => $ex->getTraceAsString()
                ]
            ]);
            return apiError();
        }
    }

    private function wpHttp($url,$params,$headers,$is_post = true)
    {
        generateApiLog('worldpay request data:'.json_encode([
                'url' => $url,
                'header' => $headers,
                'body' => $params
            ]));
        $ch = curl_init();
        if ($is_post)
        {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }else{
            curl_setopt($ch,CURLOPT_URL,$url . http_build_query($params));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        if ($curlErrno) {
            $errMsg = curl_error($ch);
            throw new \ErrorException("Error:$errMsg");
        }
        return json_decode($result,true);
        //application/vnd.worldpay.verified-tokens-v1.hal+json


    }
}