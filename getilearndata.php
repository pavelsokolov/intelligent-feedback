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
$salt = $auth['ilearn']['salt'];

$ilearn = new Client([
    'headers' => [
        'Accept' => 'application/json'
    ]
]);

$db = new Medoo([
    'type' => $auth['db']['type'],
    'host' => $auth['db']['host'],
    'database' => $auth['db']['database'],
    'username' => $auth['db']['username'],
    'password' => $auth['db']['password']
]);

$type = '';
$hideprogress= false;
$courseid = 0;
if (php_sapi_name() == 'cli') {
    $opts = "c:t:h";
    $longopts = ["course:", "type:", "hideprogress"];
    $input = getopt($opts, $longopts);
    $type = $input['t'] ?? $input['type'];
    $courseid = $input['c'] ?? $input['course'];
    echo $courseid;
    $hideprogress = key_exists('h', $input) || key_exists('hideprogress', $input);
}

if (!$courseid) {
    die('No course id is specified');
}

$users = fetchUsers();
switch ($type) {
    case 'users':
        break;
    case 'vpl':
        fetchVpl();
        break;
    case 'grade':
        fetchGrade();
        break;
    case 'log':
        fetchLog();
        break;
    case 'quiz':
        fetchQuiz();
        break;
    case 'assign':
        fetchAssign();
        break;
    default:
        fetchVpl();
        fetchGrade();
        fetchLog();
        fetchAssign();
        fetchQuiz();
}
echo "\n\r" . date('H:i:s') . " Completed\n\r";

function fetchUsers()
{
    global $db, $ilearn, $url, $secret, $salt, $courseid, $hideprogress;
    echo date('H:i:s') . " Fetching students from course id=$courseid \n\r";
    $response = $ilearn->post($url . "getlogs.php?course=$courseid&type=course", ['form_params' => ['secret' => $secret]]);;
    $users = json_decode($response->getBody(), true);
    foreach ($users as $i => $user) {
        $id = md5($user['id'] . $salt);
        if ($db->has('users', ['id' => $id])) {
            $db->update('users',
                [
                    'id' => $id,
                    'courseid' => $courseid
                ],
                ['username' => md5($user['username'])]
            );
        } else {
            $db->insert('users', [
                'id' => $id,
                'courseid' => $courseid,
                'username' => md5($user['username'])
            ]);
        }
        if (!$hideprogress) {
            echo progress_bar($i + 1, count($users));
        }
    }
    return $users;
}

function fetchVpl()
{
    global $db, $ilearn, $url, $users, $secret, $salt, $courseid, $hideprogress;
    echo "\n\r" . date('H:i:s') . " Fetching vpl submissions from course id=$courseid \n\r";
    foreach ($users as $i => $user) {
        $hasheduserid = md5($user['id'] . $salt);
        $k = 0;
        do {
            try {
                $response = $ilearn->post($url . "getlogs.php?course=$courseid&type=vpl&userid=" . $user['id'], ['form_params' => ['secret' => $secret]]);
                $data = json_decode($response->getBody(), true);
                foreach ($data as $submission) {
                    $submission['userid'] = $hasheduserid;
                    $submission['grader'] = md5($submission['grader'] . $salt);
                    if (!$db->has('vpl_submissions', ['id' => $submission['id']])) {
                        $db->insert('vpl_submissions', $submission);
                    } else {
                        $db->update('vpl_submissions', $submission, ['id' => $submission['id']]);
                    }
                }
                if (!$hideprogress) {
                    echo progress_bar($i + 1, count($users));
                }
                break;
            } catch (RequestException $e) {
                echo "\n\rRequest to " . $url . "getlogs.php?course=$courseid&type=vpl&userid=" . $user['id'] . ' returned a ' . $e->getCode() . " code. Retrying... \n\r";
                $k++;
                usleep(10);
            }
        } while ($k < 3);
    }
}

function fetchQuiz()
{
    global $db, $ilearn, $url, $users, $secret, $salt, $courseid, $hideprogress;
    echo "\n\r" . date('H:i:s') . " Fetching quiz attempts from course id=$courseid \n\r";
    foreach ($users as $i => $user) {
        $hasheduserid = md5($user['id'] . $salt);
        $k = 0;
        do {
            try {
                $response = $ilearn->post($url . "getlogs.php?course=$courseid&type=quiz&userid=" . $user['id'], ['form_params' => ['secret' => $secret]]);
                if ($response->getStatusCode() == 200) {
                    $data = json_decode($response->getBody(), true);
                    foreach ($data as $attempt) {
                        $attempt['userid'] = $hasheduserid;
                        if (!$db->has('quiz_attempts', ['attemptid' => $attempt['attemptid']])) {
                            $attempt['questions'] = json_encode($attempt['questions']);
                            $db->insert('quiz_attempts', $attempt);
                        } else {
                            $db->update('quiz_attempts', $attempt, ['attemptid' => $attempt['attemptid']]);
                        }
                    }
                    if (!$hideprogress) {
                        echo progress_bar($i + 1, count($users));
                    }
                    break;
                }
            } catch (RequestException $e) {
                echo "\n\rRequest to " . $url . "getlogs.php?course=$courseid&type=quiz&userid=" . $user['id'] . ' returned a ' . $e->getCode() . " code. Retrying... \n\r";
                $k++;
                usleep(10);
            }
        } while ($k < 3);
    }
}

function fetchLog()
{
    global $db, $ilearn, $url, $users, $secret, $salt, $courseid, $hideprogress;
    echo "\n\r" . date('H:i:s') . " Fetching logs from course id=$courseid \n\r";
    foreach ($users as $i => $user) {
        $hasheduserid = md5($user['id'] . $salt);
        $query = $db->query('SELECT id from log where userid = ' . $hasheduserid . ' and courseid = ' . $courseid . ' order by id desc limit 1');
        $lastlogid = $query ? $query->fetchAll()[0]['id'] : 0;
        //$lastlogid = $db->query('SELECT id from log where userid = ' . $id . ' and courseid = '.$courseid.' order by id desc limit 1')->fetchAll()[0]['id'] ?? 0;
        $haslogs = true;
        $offset = 0;
        do {
            $k = 0;
            do {
                try {
                    $response = $ilearn->post($url . "getlogs.php?course=$courseid&type=log&userid=" . $user['id'] . "&offset=$offset&lastid=" . $lastlogid, ['form_params' => ['secret' => $secret]]);
                    if ($response->getStatusCode() == 200) {
                        $data = json_decode($response->getBody(), true);
                        if ($data['empty']) {
                            $haslogs = false;
                        } else {
                            foreach ($data as $log) {
                                $log['userid'] = $hasheduserid;
                                $log['relateduserid'] = md5($log['relateduserid'] . $salt);
                                $log['realuserid'] = md5($log['realuserid'] . $salt);
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
                    echo "\n\rRequest to " . $url . "getlogs.php?course=$courseid&type=log&userid=" . $user['id'] . "&offset=$offset&lastid=" . $lastlogid . ' returned a ' . $e->getCode() . " code. Retrying... \n\r";
                    $k++;
                    usleep(10);
                }
            } while ($k < 3);
        } while ($haslogs);
        if (!$hideprogress) {
            echo progress_bar($i + 1, count($users));
        }
    }
}

function fetchGrade()
{
    global $db, $ilearn, $url, $users, $secret, $salt, $courseid, $hideprogress;
    echo "\n\r" . date('H:i:s') . " Fetching grade histories from course id=$courseid \n\r";
    foreach ($users as $i => $user) {
        $k = 0;
        $hasheduserid = md5($user['id'] . $salt);
        $query = $db->query('SELECT timemodified from grades_history where userid = ' . $hasheduserid . ' and courseid = ' . $courseid . ' order by id desc limit 1');
        $lastdate = $query ? $query->fetchAll()[0]['timemodified'] : 0;
        //$lastdate = $db->query('SELECT timemodified from grades_history where userid = ' . $id . ' and courseid = '.$courseid.' order by id desc limit 1')->fetchAll()[0]['timemodified'] ?? 0;
        do {
            try {
                $response = $ilearn->post($url . "getlogs.php?course=$courseid&type=grade&userid=" . $user['id'] . '&lastdate=' . $lastdate, ['form_params' => ['secret' => $secret]]);
                if ($response->getStatusCode() == 200) {
                    $data = json_decode($response->getBody(), true);
                    if (!$data['empty']) {
                        foreach ($data as $history) {
                            $history['userid'] = $hasheduserid;
                            if (!$db->has('grades_history', ['id' => $history['id']])) {
                                $db->insert('grades_history', $history);
                            } else {
                                $db->update('grades_history', $history, ['id' => $history['id']]);
                            }
                        }
                    }
                    if (!$hideprogress) {
                        echo progress_bar($i + 1, count($users));
                    }
                    break;
                }
            } catch (RequestException $e) {
                echo "\n\rRequest to " . $url . "getlogs.php?course=$courseid&type=grade&userid=" . $user['id'] . '&lastdate=' . $lastdate . ' returned a ' . $e->getCode() . " code. Retrying... \n\r";
                $k++;
                usleep(10);
            }
        } while ($k < 3);
    }
}

function fetchAssign()
{
    global $db, $ilearn, $url, $users, $secret, $salt, $courseid, $hideprogress;
    echo "\n\r" . date('H:i:s') . " Fetching assign submissions from course id=$courseid \n\r";
    foreach ($users as $i => $user) {
        $hasheduserid = md5($user['id'] . $salt);
        echo "$hasheduserid";
        $k = 0;
        do {
            try {
                $response = $ilearn->post($url . "getlogs.php?course=$courseid&type=assign&userid=" . $user['id'], ['form_params' => ['secret' => $secret]]);
                $data = json_decode($response->getBody(), true);
                foreach ($data as $submission) {
                    $submission['userid'] = $hasheduserid;
                    if (!$db->has('assign_submissions', ['id' => $submission['id']])) {
                        $db->insert('assign_submissions', $submission);
                    } else {
                        $db->update('assign_submissions', $submission, ['id' => $submission['id']]);
                    }
                }
                if (!$hideprogress) {
                    echo progress_bar($i + 1, count($users));
                }
                break;
            } catch (RequestException $e) {
                echo "\n\rRequest to " . $url . "getlogs.php?course=$courseid&type=assign&userid=" . $user['id'] . ' returned a ' . $e->getCode() . " code. Retrying... \n\r";
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