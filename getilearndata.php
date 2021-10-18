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
$url = $auth['ilearn']['url'];
$secret = $auth['ilearn']['secret'];

$ilearn = new Client([
    'headers' => [
        'Accept' => 'application/json'
    ]]);

$db = new Medoo([
    'type' => $auth['db']['type'],
    'host' => $auth['db']['host'],
    'database' => $auth['db']['database'],
    'username' => $auth['db']['username'],
    'password' => $auth['db']['password']
]);

$type = '';
if (php_sapi_name() == 'cli') {
    $opts = "t:";
    $longopts = ["type:"];
    $input = getopt($opts, $longopts);
    $type = $input['t'] ?? $input['type'];
}

switch ($type) {
    case 'users':
        fetchUsers();
        break;
    case 'vpl':
        $users = $db->select('users', ['id', 'username'], ['courseid' => 1155]);
        fetchVpl();
        break;
    case 'grade':
        $users = $db->select('users', ['id', 'username'], ['courseid' => 1155]);
        fetchGrade();
        break;
    case 'log':
        $users = $db->select('users', ['id', 'username'], ['courseid' => 1155]);
        fetchLog();
        break;
    case 'quiz':
        $users = $db->select('users', ['id', 'username'], ['courseid' => 1155]);
        fetchQuiz();
        break;
    default:
        fetchUsers();
        $users = $db->select('users', ['id', 'username'], ['courseid' => 1155]);
        fetchVpl();
        fetchGrade();
        fetchLog();
        fetchQuiz();
}

function fetchUsers()
{
    global $db, $ilearn, $url, $secret;
    echo "Fetching students from courseid 1155 \n\r";
    $response = $ilearn->post($url . "getlogs.php?course=1155&type=course", ['form_params' => ['secret' => $secret]]);;
    $data = json_decode($response->getBody(), true);
    foreach ($data as $i => $user) {
        if ($db->has('users', ['username' => $user['username']])) {
            $db->update('users', [
                'id' => $user['id'],
                'courseid' => 1155,
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname']],
                ['username' => $user['username']]);
        } else {
            $db->insert('users', [
                'id' => $user['id'],
                'courseid' => 1155,
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname'],
                'username' => $user['username']
            ]);
        }
        echo progress_bar($i, count($data));
    }
}

function fetchVpl()
{
    global $db, $ilearn, $url, $users, $secret;
    echo "\n\rFetching vpl submissions from courseid 1155 \n\r";
    foreach ($users as $i => $user) {
        $response = $ilearn->post($url . "getlogs.php?course=1155&type=vpl&userid=" . $user['id'], ['form_params' => ['secret' => $secret]]);
        $data = json_decode($response->getBody(), true);
        foreach ($data as $submission) {
            $n = 0;
            do {
                try {
                    if (!$db->has('vpl_submissions', ['id' => $submission['id']])) {
                        $db->insert('vpl_submissions', $submission);
                    } else {
                        $db->update('vpl_submissions', $submission, ['id' => $submission['id']]);
                    }
                    break;
                } catch (Error $e) {
                    $n++;
                    usleep(10);
                    var_dump($e);
                    exit();
                }
            } while ($n < 3);
        }
        echo progress_bar($i, count($users));
    }
}

function fetchQuiz()
{
    global $db, $ilearn, $url, $users, $secret;
    echo "\n\rFetching quiz attempts from courseid 1155 \n\r";
    foreach ($users as $i => $user) {
        //  echo "Fetching quiz attempts for " . $user['username'] . " \n\r";
        $response = $ilearn->post($url . "getlogs.php?course=1155&type=quiz&userid=" . $user['id'], ['form_params' => ['secret' => $secret]]);
        $data = json_decode($response->getBody(), true);
        foreach ($data as $attempt) {
            $n = 0;
            do {
                try {
                    if (!$db->has('quiz_attempts', ['attemptid' => $attempt['attemptid']])) {
                        $attempt['questions'] = json_encode($attempt['questions']);
                        $db->insert('quiz_attempts', $attempt);
                    } else {
                        $db->update('quiz_attempts', $attempt, ['attemptid' => $attempt['attemptid']]);
                    }
                    break;
                } catch (Error $e) {
                    $n++;
                    usleep(10);
                    echo $e->getMessage() . "\n\r";
                }
            } while ($n < 3);
        }
        echo progress_bar($i, count($users));
    }
}

function fetchLog()
{
    global $db, $ilearn, $url, $users, $secret;
    echo "\n\rFetching logs from courseid 1155 \n\r";
    foreach ($users as $i => $user) {
        $lastlogid = $db->query('SELECT id from log where userid = ' . $user['id'] . ' and courseid = 1155 order by id desc limit 1')->fetchAll()[0]['id'] ?? 0;
        $haslogs = true;
        $offset = 0;
        do {
            $response = $ilearn->post($url . "getlogs.php?course=1155&type=log&userid=" . $user['id'] . "&offset=$offset&lastid=" . $lastlogid, ['form_params' => ['secret' => $secret]]);
            if ($response->getStatusCode() == 200) {
                $data = json_decode($response->getBody(), true);
                if ($data['empty']) {
                    $haslogs = false;
                } else {
                    foreach ($data as $log) {
                        $i = 0;
                        do {
                            try {
                                if (!$db->has('log', ['id' => $log['id']])) {
                                    $db->insert('log', $log);
                                } else {
                                    $db->update('log', $log, ['id' => $log['id']]);
                                }
                                break;
                            } catch (Error $e) {
                                $i++;
                                usleep(10);
                                echo $e->getMessage() . "\n\r";
                            }
                        } while ($i < 3);
                    }
                }
                $offset += 1000;
            } else {
                echo "There was an error in " . $response->getEffectiveUrl() . "\n\r";
                exit();
            }
        } while ($haslogs);
        echo progress_bar($i, count($users));
    }
}

function fetchGrade()
{
    global $db, $ilearn, $url, $users, $secret;
    echo "\n\rFetching grade histories from courseid 1155 \n\r";
    foreach ($users as $i => $user) {
        $response = $ilearn->post($url . "getlogs.php?course=1155&type=grade&userid=" . $user['id'], ['form_params' => ['secret' => $secret]]);
        $data = json_decode($response->getBody(), true);
        if (!$data['empty']) {
            foreach ($data as $history) {
                $i = 0;
                do {
                    try {
                        if (!$db->has('grades_history', ['id' => $history['id']])) {
                            $db->insert('grades_history', $history);
                        } else {
                            $db->update('grades_history', $history, ['id' => $history['id']]);
                        }
                        break;
                    } catch (Error $e) {
                        $i++;
                        usleep(10);
                        echo $e->getMessage() . "\n\r";
                    }
                } while ($i < 3);
            }
        }
        echo progress_bar($i, count($users));
    }
}

function progress_bar($done, $total, $info = "", $width = 50): string
{
    $perc = round(($done * 100) / $total);
    $bar = round(($width * $perc) / 100);
    return sprintf("%s%%[%s>%s]%s\r", $perc, str_repeat("=", $bar), str_repeat(" ", $width - $bar), $info);
}