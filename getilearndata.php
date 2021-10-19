<?php

header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 'on');
ini_set('html_errors', 1);
ini_set('ignore_repeated_errors', 1);
error_reporting(E_ERROR | E_WARNING | E_PARSE);

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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
        echo "\n\rCompleted\n\r";
        break;
    case 'vpl':
        $users = $db->select('users', ['id', 'username'], ['courseid' => 1155]);
        fetchVpl();
        echo "\n\rCompleted\n\r";
        break;
    case 'grade':
        $users = $db->select('users', ['id', 'username'], ['courseid' => 1155]);
        fetchGrade();
        echo "\n\rCompleted\n\r";
        break;
    case 'log':
        $users = $db->select('users', ['id', 'username'], ['courseid' => 1155]);
        fetchLog();
        echo "\n\rCompleted\n\r";
        break;
    case 'quiz':
        $users = $db->select('users', ['id', 'username'], ['courseid' => 1155]);
        fetchQuiz();
        echo "\n\rCompleted\n\r";
        break;
    default:
        fetchUsers();
        $users = $db->select('users', ['id', 'username'], ['courseid' => 1155]);
        fetchVpl();
        fetchGrade();
        fetchLog();
        fetchQuiz();
        echo "\n\rCompleted\n\r";
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
        $k = 0;
        do {
            try {
                $response = $ilearn->post($url . "getlogs.php?course=1155&type=vpl&userid=" . $user['id'], ['form_params' => ['secret' => $secret]]);
                $data = json_decode($response->getBody(), true);
                foreach ($data as $submission) {
                    if (!$db->has('vpl_submissions', ['id' => $submission['id']])) {
                        $db->insert('vpl_submissions', $submission);
                    } else {
                        $db->update('vpl_submissions', $submission, ['id' => $submission['id']]);
                    }
                }
                echo progress_bar($i, count($users));
                break;
            } catch (RequestException $e) {
                echo "\n\rRequest to " . $url . "getlogs.php?course=1155&type=vpl&userid=" . $user['id'] . ' returned a ' . $e->getCode() . " code. Retrying... \n\r";
                $k++;
                usleep(10);
            }
        } while ($k < 3);
    }
}

function fetchQuiz()
{
    global $db, $ilearn, $url, $users, $secret;
    echo "\n\rFetching quiz attempts from courseid 1155 \n\r";
    foreach ($users as $i => $user) {
        $k = 0;
        do {
            try {
                $response = $ilearn->post($url . "getlogs.php?course=1155&type=quiz&userid=" . $user['id'], ['form_params' => ['secret' => $secret]]);
                if ($response->getStatusCode() == 200) {
                    $data = json_decode($response->getBody(), true);
                    foreach ($data as $attempt) {
                        if (!$db->has('quiz_attempts', ['attemptid' => $attempt['attemptid']])) {
                            $attempt['questions'] = json_encode($attempt['questions']);
                            $db->insert('quiz_attempts', $attempt);
                        } else {
                            $db->update('quiz_attempts', $attempt, ['attemptid' => $attempt['attemptid']]);
                        }
                    }
                    echo progress_bar($i, count($users));
                    break;
                }
            } catch (RequestException $e) {
                echo "\n\rRequest to " . $url . "getlogs.php?course=1155&type=quiz&userid=" . $user['id'] . ' returned a ' . $e->getCode() . " code. Retrying... \n\r";
                $k++;
                usleep(10);
            }
        } while ($k < 3);
    }
}

function fetchLog()
{
    global $db, $ilearn, $url, $users, $secret;
    echo "\n\rFetching logs from courseid 1155 \n\r";
    foreach ($users as $i => $user) {
        usleep(10);
        $lastlogid = $db->query('SELECT id from log where userid = ' . $user['id'] . ' and courseid = 1155 order by id desc limit 1')->fetchAll()[0]['id'] ?? 0;
        $haslogs = true;
        $offset = 0;
        do {
            $k = 0;
            do {
                try {
                    $response = $ilearn->post($url . "getlogs.php?course=1155&type=log&userid=" . $user['id'] . "&offset=$offset&lastid=" . $lastlogid, ['form_params' => ['secret' => $secret]]);
                    if ($response->getStatusCode() == 200) {
                        $data = json_decode($response->getBody(), true);
                        if ($data['empty']) {
                            $haslogs = false;
                        } else {
                            foreach ($data as $log) {
                                if (!$db->has('log', ['id' => $log['id']])) {
                                    $db->insert('log', $log);
                                } else {
                                    $db->update('log', $log, ['id' => $log['id']]);
                                }
                            }
                        }
                        $offset += 1000;
                        break;
                    }
                } catch (RequestException $e) {
                    echo "\n\rRequest to " . $url . "getlogs.php?course=1155&type=log&userid=" . $user['id'] . "&offset=$offset&lastid=" . $lastlogid . ' returned a ' . $e->getCode() . " code. Retrying... \n\r";
                    $k++;
                    usleep(10);
                }
            } while ($k < 3);
        } while ($haslogs);
        echo progress_bar($i, count($users));
    }
}

function fetchGrade()
{
    global $db, $ilearn, $url, $users, $secret;
    echo "\n\rFetching grade histories from courseid 1155 \n\r";
    foreach ($users as $i => $user) {
        $k = 0;
        $lastdate = $db->query('SELECT timemodified from grades_history where userid = ' . $user['id'] . ' and courseid = 1155 order by id desc limit 1')->fetchAll()[0]['timemodified'] ?? 0;
        do {
            try {
                usleep(10);
                $response = $ilearn->post($url . "getlogs.php?course=1155&type=grade&userid=" . $user['id'] . '&lastdate='.$lastdate, ['form_params' => ['secret' => $secret]]);
                if ($response->getStatusCode() == 200) {
                    $data = json_decode($response->getBody(), true);
                    if (!$data['empty']) {
                        foreach ($data as $history) {
                            if (!$db->has('grades_history', ['id' => $history['id']])) {
                                $db->insert('grades_history', $history);
                            } else {
                                $db->update('grades_history', $history, ['id' => $history['id']]);
                            }
                        }
                    }
                    echo progress_bar($i, count($users));
                    break;
                }
            } catch (RequestException $e) {
                echo "\n\rRequest to " . $url . "getlogs.php?course=1155&type=grade&userid=" . $user['id'] . '&lastdate='.$lastdate . ' returned a ' . $e->getCode() . " code. Retrying... \n\r";
                $k++;
                usleep(10);
            }
        } while ($k < 3);
    }
}

function progress_bar($done, $total, $info = "", $width = 50): string
{
    $perc = round(($done * 100) / $total);
    $bar = round(($width * $perc) / 100);
    return sprintf("%s%%[%s>%s]%s\r", $perc, str_repeat("=", $bar), str_repeat(" ", $width - $bar), $info);
}