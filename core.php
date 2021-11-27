<?php

$file = fopen("result.json", 'w');

$leadID = null;

if ($_POST) {

    try {
        $account_id = intval($_POST['account']['id']);
        $subdomain = $_POST['account']['subdomain'];

        $access_token = getAccessToken($subdomain);
        $leadID = $_POST['leads']['status'][0]['id'];

        $lead = getLead($access_token, $leadID, $subdomain);
        $getStartWorkDate = '';
        $daysNeedToWork = '';
        $getEndWorkDate = '';
        $status = '';
        $currentDatePlusOne = '';
        $dealStatus = '';

        foreach (json_decode($lead)->custom_fields_values as $field) {
            switch($field->field_name) {
                case 'Дата договора':
                    $getStartWorkDate = $field->values[0]->value;
                    break;
                case 'Срок исполнения (дней)':
                    $daysNeedToWork = $field->values[0]->value;
                    break;
                case 'Срок поставки (Дата)':
                    $getEndWorkDate = $field->values[0]->value;
                    break;
                case 'Стаус исполнения':
                    $dealStatus = $field->values[0]->value;
                    break;
                }
            }

        if ($leadID && $getStartWorkDate && $daysNeedToWork) {

            $getStartWorkDate = (float) $getStartWorkDate;
            $daysNeedToWork = (int) $daysNeedToWork;

            $endTime = getEndDate($daysNeedToWork, $getStartWorkDate);
            $endTime = (float) $endTime;

            $timer = date('d-m-Y');
            $currentDate = strtotime($timer);
            $currentDate = (float) $currentDate;

            $currentDatePlusOne = $currentDate + 604800;
            $currentDatePlusOne = (float) $currentDatePlusOne;

            if ($endTime == $getEndWorkDate) {
                if ($getEndWorkDate < $currentDatePlusOne && $dealStatus !== "Вышли за сроки договора") {
                    $status = "Вышли за сроки договора";
                    sendDataStatusAmo($access_token, $subdomain, $leadID, $status);
                    addDealAmo($access_token,$subdomain,$leadID,$currentDate);
                }
//Это условие нужно что бы вебхук сам себя не триггерил постоянно в случае активации по изменению сделки
                die();
            } else {
                if ($endTime == $currentDatePlusOne) {
//Если конечная дата равна текущей дате+7 дней - ставим статус ниже
                    $status = "Осталось 7 дней";
                    sendDataAmo($access_token, $endTime, $subdomain, $leadID, $status);
                } elseif ($endTime < $currentDatePlusOne) {
//Если конечная дата меньше или равна текущей дате - ставим статус ниже
                    $status = "Вышли за сроки договора";
                    $currentDate = (int) $currentDate;
                    sendDataAmo($access_token, $endTime, $subdomain, $leadID, $status);
                    addDealAmo($access_token,$subdomain,$leadID,$currentDate);

                } elseif ($endTime > $currentDatePlusOne) {
//Если конечная дата больше текушей+7 дней - ставим статус ниже
                    $status = "Допустимо";
                    sendDataAmo($access_token, $endTime, $subdomain, $leadID, $status);
                }
            }
//если срок поставки равен дате +7 дней  - тогда поле "Срок" становится - "Осталось 7 дней ".
//            Если меньше - "Просрочено".
//            Если больше - "Допустимо"
        } else {
            fwrite($file, 'no needed data');
        }
    } catch (Exception $e) {
        fwrite($file, 'error');
    }
} else {
    fwrite($file, 'no-work-function');
}

// Функция получения токена. Читает Refresh Token из файла token.json. Данный токен постоянно обновляется при получении хука от AmoCRM. Если токен не обновлялся в течении 3 месяцев, нужно получить его вручную.
function getAccessToken(string $subdomain): string|null {

    $tokenFile = fopen("token.json", "r");
    $tokens = json_decode(fread($tokenFile, filesize("token.json")), true);
    $client_id = $tokens[$subdomain]['client_id'];
    $client_secret = $tokens[$subdomain]['client_secret'];
    $redirect_uri = $tokens[$subdomain]['redirect_uri'];
    $refresh_token = $tokens[$subdomain]['refresh_token'];

    $curl = curl_init();
    fclose($tokenFile);

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://'.$subdomain.'.amocrm.ru/oauth2/access_token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{
          "client_id": '.json_encode($client_id).',
          "client_secret": '.json_encode($client_secret).',
          "grant_type": "refresh_token",
          "refresh_token":'.json_encode($refresh_token).',
          "redirect_uri": '.json_encode($redirect_uri).'
        }',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Cookie: session_id=9o4atg2s28c0khfv4qbia3ja28; user_lang=ru'
        ),
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    if ($response) {
        $File = fopen("token.json", "w");
        $parse_response = json_decode($response);
        $tokens[$subdomain]['refresh_token'] = $parse_response->refresh_token;
        fwrite($File, json_encode($tokens));
        fclose($File);
        return $parse_response->access_token;
    }
    return null;
}

// Данная функция отвечает за получение информации о сделке. Менять ничего не нужно. Возвращает JSON-объект.
function getLead(string $token, string $id, string $subdomain): string
{
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://'.$subdomain.'.amocrm.ru/api/v4/leads/'.$id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer '.$token,
            'Cookie: session_id=9o4atg2s28c0khfv4qbia3ja28; user_lang=ru'
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    return $response;
}

function sendDataAmo(string $accessToken, float $message, string $subdomain, int $id,string $status): string
{
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://'.$subdomain.'.amocrm.ru/api/v4/leads/'.$id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => '{"custom_fields_values":[{"field_id":427289,"values":[{"value":'.$message.'}]},
                                                        {"field_id":427287,"values":[{"value":"'.$status.'"}]}]}',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer '.$accessToken,
            'Content-Type: application/json',
            'Cookie: session_id=c8fe0d7e9t6ieuu56d4fqg060q; user_lang=ru'
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    return $response;

}

function sendDataStatusAmo(string $accessToken, string $subdomain, int $id,string $status): string
{
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://'.$subdomain.'.amocrm.ru/api/v4/leads/'.$id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => '{"custom_fields_values":[{"field_id":427287,"values":[{"value":"'.$status.'"}]}]}',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer '.$accessToken,
            'Content-Type: application/json',
            'Cookie: session_id=c8fe0d7e9t6ieuu56d4fqg060q; user_lang=ru'
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    return $response;

}

function getEndDate(int $numberOfWorkingDays, float $startDateInTimestamp): float
{
    $startDate = (new \DateTime())
        ->setTimestamp($startDateInTimestamp)
        ->modify('next day midnight');

    $endTime = (new \DateTime())
        ->setTimestamp($startDateInTimestamp)
        ->modify(\sprintf('+%d days midnight', $numberOfWorkingDays));

    for ($currentDay = $startDate; $currentDay <= $endTime; $currentDay->modify('next day')) {
        $weekday = $currentDay->format('D');


        if ($weekday === 'Sat' || $weekday === 'Sun') {
            $endTime->modify('next day');
        }
    }
    return $endTime->getTimestamp();
}

function addDealAmo(string $accessToken, string $subdomain, int $id, int $complete):string {

    $curl = curl_init();


    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://'.$subdomain.'.amocrm.ru/api/v4/tasks',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => '[{"responsible_user_id": 5979865,"task_type_id":2367562,"text":"ДАТА ВЫПОЛЕНИЯ РАБОТ ПРОСРОЧЕНА","complete_till":'.$complete.',"entity_id":'.$id.',"entity_type":"leads"}]',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer '. $accessToken,
            'Content-Type: application/json',
            'Cookie: session_id=c8fe0d7e9t6ieuu56d4fqg060q; user_lang=ru'
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);

    return $response;

}

fclose($file);
die();
