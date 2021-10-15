<?php

header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 'on');
ini_set('html_errors', 1);
ini_set('ignore_repeated_errors', 1);
error_reporting(E_ERROR | E_WARNING | E_PARSE);

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use Medoo\Medoo;

$auth = json_decode(file_get_contents('auth.json'), true);
$apiurl = $auth['play2']['url'];
$play2username = $auth['play2']['username'];
$play2password = $auth['play2']['password'];

$mediasite = new Client([
    'headers' => [
        'Accept' => 'application/json',
        'sfapikey' => $auth['play2']['sfapikey'],
    ],
    'auth' => [$play2username, $play2password]
]);

if (php_sapi_name() == 'cli') {
    $opts = "o:u:e::t:";
    $input = getopt($opts);
    $op = $input['o'] ?? null;
    $username = $input['u'] ?? null;
    $email = $input['e'] ?? null;
    // $ticket = $input['t'] ?? null;
} else {
    // op: export = 1, delete = 2
    $op = $_GET['op'];
    $username = $_GET['username'];
    $email = $_GET['mail'];
    //$ticket = $_GET['ticket'];
}

/*
if (!$ticket || !$username) {
  http_response_code(400);
  die();
}

$ch = curl_init();
$tokerurl = 'https://toker-test.dsv.su.se/verify';

curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
curl_setopt($ch, CURLOPT_POSTFIELDS, $ticket);
curl_setopt($ch, CURLOPT_URL, $tokerurl);
$contents = curl_exec($ch);
$headers  = curl_getinfo($ch);
curl_close($ch);

// Check auth.
if ($headers['http_code'] !== 200 || !in_array('urn:mace:swami.se:gmai:dsv-user:gdpr', json_decode($contents)->entitlements)) {
  // Throw unauthorized code.
  http_response_code(401);
  die();
}

if ($o == 2) {
  //echo "Data removal is not supported by Mediasite 7.0.25. Exiting...\n";
  http_response_code(400);
  die();
}
*/
//echo "Retrieving data for the following credential(s): $u $e\n";
$timestart = time();

// Try to generate a user report.

try {
    $reportid = '1d7d4531a07949d4b6a1ead48852e63c20';
    $report = getReport($reportid);
    $data = json_decode($report['Description']);
    if ($data->CompletedOn + 3600 > time()) {
        if ($data->Status == 'ExportSuccessful' && !empty($data->Link)) {
            handleReport($data->Link);
            http_response_code(200);
            die();
        }
    }

    set_time_limit(0);
    $sleep_time = 5;

    // Let's execute.
    $execute = $mediasite->post($apiurl . "/UserReports('$reportid')/Execute", ['json' => ['DateRangeTypeOverride' => 'Yesterday']]);
    if ($execute->getStatusCode() == '200') {
        $resultid = json_decode($execute->getBody(), true)['ResultId'];
        $executejobid = json_decode($execute->getBody(), true)['JobId'];

        // Do an execution.
        $status = '';
        while (TRUE) {
            if ($timestart < time() - 600) {
                http_response_code(500);
                die();
            }
            sleep($sleep_time);
            $currentstatus = checkJobStatus($executejobid);
            if ($currentstatus <> $status) {
                $status = $currentstatus;
                echo date('H:i:s') . " Report execution: $status\n";
            }
            $info = json_encode(["Status" => "Execute$status", "JobId" => $executejobid, "ReportId" => $reportid]);
            $p = $mediasite->patch($apiurl . "/UserReports('$reportid')", ['json' => ["Description" => $info]]);
            if ($status == 'Successful') {
                break;
            }
        }

        // Do an export.
        $export = $mediasite->post($apiurl . "/UserReports('$reportid')/Export", ['json' => ["ResultId" => "$resultid", "FileFormat" => "Xml"]]);
        if ($export->getStatusCode() == '200') {
            $link = json_decode($export->getBody(), true)['DownloadLink'];
            $exportjobid = json_decode($export->getBody(), true)['JobId'];
            while (TRUE) {
                if ($timestart < time() - 600) {
                    http_response_code(500);
                    die();
                }
                sleep($sleep_time);
                $currentstatus = checkJobStatus($exportjobid);
                if ($currentstatus <> $status) {
                    $status = $currentstatus;
                    echo date('H:i:s') . " Report export: $status\n";
                }
                $info = json_encode(["Status" => "Export$status", "JobId" => $exportjobid, "ReportId" => $reportid, "ResultId" => $resultid, "Link" => $link]);
                $p = $mediasite->patch($apiurl . "/UserReports('$reportid')", ['json' => ["Description" => $info]]);
                if ($status == 'Successful') {
                    $date = date('Y-m-d H:i:s');
                    $p = $mediasite->patch($apiurl . "/UserReports('$reportid')", ['json' => ["Description" => json_encode(["Status" => "Export$status", "CompletedOn" => time(), "ReportId" => $reportid, "ResultId" => $resultid, "Link" => $link])]]);
                    handleReport($link);
                    http_response_code(200);
                    break;
                }
            }
        }
    } else {
        http_response_code(400);
        die();
    }
} catch (Exception $e) {
    var_dump($e->getMessage());
    http_response_code(500);
    return $e;
}

// Functions
function checkJobStatus($jobid)
{
    global $mediasite, $apiurl;
    $r = $mediasite->get($apiurl . "/Jobs('$jobid')");
    return json_decode($r->getBody(), true)['Status'];
}

function handleReport($url)
{
    global $mediasite, $auth;
    //$myFile = fopen("$name.xml", 'w') or die('Problems');
    $file = tmpfile();
    $response = $mediasite->request('GET', "$url", ['sink' => $file]);
    $array = json_decode(json_encode(simplexml_load_file(stream_get_meta_data($file)['uri'])), TRUE);
    $database = new Medoo([
        'type' => $auth['db']['type'],
        'host' => $auth['db']['host'],
        'database' => $auth['db']['database'],
        'username' => $auth['db']['username'],
        'password' => $auth['db']['password']
    ]);
    // Drop todays insert
    $database->delete('videos', ['created[>=]' => date('Y-m-d H:i:s', strtotime("today", time()))]);
    foreach ($array['Users']['User'] as $user) {
        if ($user['Username'] != 'Anonymous' && $database->has('users', ['username' => $user['Username']])) {
            if (key_exists('Id', $user['Presentation'])) {
                $user['Presentation'] = [$user['Presentation']];
            }
            foreach ($user['Presentation'] as $presentation) {
                $database->insert('videos', [
                    'userid' => $database->get('users', 'id', ['username' => $user['Username']]),
                    'presentation_id' => $presentation['Id'],
                    'presentation_name' => $presentation['Title'],
                    'presentation_duration' => $presentation['Duration'],
                    'totalviews' => $presentation['TotalViews'],
                    'timewatched' => $presentation['TotalTimeWatched'],
                    'percentwatched' => (float)$presentation['PercentWatched'],
                    'coverage' => $presentation['Coverage']
                ]);
            }
        }
    }

    fclose($file);
}

function getReport($reportid)
{
    global $mediasite, $apiurl;
    $response = $mediasite->get("$apiurl/UserReports('$reportid')");
    return json_decode($response->getBody(), TRUE);
}

function getUserReportData($reportid)
{
    global $mediasite, $apiurl;
    $response = $mediasite->get("$apiurl/UserReports('$reportid')");
    $report = json_decode($response->getBody(), true);
    if (!empty($report['Description'])) {
        return json_decode($report['Description']);
    }
    return false;
}
