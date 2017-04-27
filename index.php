<?php

$allowedMethods = array('FETCH', 'POST', 'DELETE', 'SAVE', 'INFO', 'GET', 'UPLOAD',
    'SORT', 'EXPORT', 'IMPORT', 'LOGOUT', 'EXEC', 'IMAGE', 'PARSING', 'REPORT');
$allowedMethods = implode(",", $allowedMethods);

$headers = getallheaders();
$headers['Secookie'] = empty($headers['Secookie']) ? $headers['secookie'] : $headers['Secookie'];
if (!empty($headers['Secookie']))
    session_id($headers['Secookie']);
session_start();

chdir($_SERVER['DOCUMENT_ROOT']);
date_default_timezone_set("Europe/Moscow");
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

define('API_ROOT', $_SERVER['DOCUMENT_ROOT'] . '/api/');
define('API_ROOT_URL', "https://" . $_SERVER['SERVER_NAME'] . "/api");
define('AUTH_SERVER', "https://api.siteedit.ru");

function writeLog($data)
{
    if (!is_string($data))
        $data = print_r($data, 1);
    $file = fopen($_SERVER['DOCUMENT_ROOT'] . "/api/debug.log", "a+");
    $query = "$data" . "\n";
    fputs($file, $query);
    fclose($file);
}

require_once 'lib/lib_utf8.php';
require_once 'lib/lib_function.php';
require_once 'lib/PHPExcel.php';
require_once 'lib/PHPExcel/Writer/Excel2007.php';
require_once API_ROOT . "vendor/autoload.php";

$apiMethod = $_SERVER['REQUEST_METHOD'];
$apiClass = parse_url($_SERVER["REQUEST_URI"]);
$apiClass = str_replace("api/", "", trim($apiClass['path'], "/"));
$origin = !empty($headers['Origin']) ? $headers['Origin'] : $headers['origin'];
if (!empty($origin)) {
    $url = parse_url($origin);
    if ($url) {
        if ($url['host'] == 'sm66.e-stile.ru')
            header("Access-Control-Allow-Origin: https://66sm.ru");
        if ($url['host'] == 'localhost' && $url['port'] == 1338)
            header("Access-Control-Allow-Origin: http://localhost:1338");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Headers: Project, Secookie");
        header("Access-Control-Allow-Methods: $allowedMethods");
    }
    if ($apiMethod == "OPTIONS")
        exit;
}


if ($apiClass == "Auth" && strtolower($apiMethod) == "logout") {
    $_SESSION = array();
    session_destroy();
    echo "Session destroy!";
    exit;
}

if ($apiClass == "Auth" && strtolower($apiMethod) == "get") {
    if (empty($_SESSION['isAuth'])) {
        header("HTTP/1.1 401 Unauthorized");
        echo 'Сессия истекла! Необходима авторизация!';
        exit;
    }
}

$phpInput = file_get_contents('php://input');

define("HOSTNAME", $_SERVER["HTTP_HOST"]);
define('DOCUMENT_ROOT', $_SERVER["DOCUMENT_ROOT"]);
$dbConfig = DOCUMENT_ROOT . '/system/config_db.php';

if (file_exists($dbConfig))
    require_once $dbConfig;
else {
    header("HTTP/1.1 401 Unauthorized");
    echo 'Сессия истекла! Необходима авторизация!';
    exit;
}

if ($apiClass != "Auth" && empty($_SESSION['isAuth'])) {
    header("HTTP/1.1 401 Unauthorized");
    echo 'Необходима авторизация!';
    exit;
}

$apiObject = $apiClass;
if (!class_exists($apiClass = "\\SE\\Shop\\" . str_replace("/", "\\", $apiClass))) {
    header("HTTP/1.1 501 Not Implemented");
    echo "Объект '{$apiObject}' не найден!";
    exit;
}
if (!method_exists($apiClass, $apiMethod)) {
    header("HTTP/1.1 501 Not Implemented");
    echo "Метод'{$apiMethod}' не поддерживается!";
    exit;
}

$apiObject = new $apiClass($phpInput);
if ($apiObject->initConnection($CONFIG))
    $apiObject->$apiMethod();
$apiObject->output();
