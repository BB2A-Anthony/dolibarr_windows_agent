<?php
/**
 * One-time enrollment endpoint: exchange an enrollment token for the
 * technical user's API key. Lets the agent be installed by entering only
 * the Dolibarr URL and the enrollment token (displayed once to the admin).
 *
 * GET /custom/systeminfo/enroll.php?token=xxx
 */
define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);
define('NOBROWSERNOTIFY', 1);

require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

header('Content-Type: application/json');

function enroll_fail($code, $message)
{
    http_response_code($code);
    echo json_encode(array('error' => $message));
    exit;
}

// Require HTTPS unless the admin disabled the check.
if (!getDolGlobalString('SYSTEMINFO_ALLOW_HTTP')
    && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == 'off')) {
    enroll_fail(426, 'HTTPS requis : activez HTTPS, ou definissez SYSTEMINFO_ALLOW_HTTP=1 (non recommande).');
}

$token = GETPOST('token', 'a-z0-9');
if (empty($token)) {
    enroll_fail(400, 'Jeton manquant');
}

$expected = getDolGlobalString('SYSTEMINFO_ENROLL_TOKEN');
if (empty($expected) || !hash_equals($expected, $token)) {
    enroll_fail(403, 'Jeton invalide');
}

$user = new User($db);
if ($user->fetch('', 'useragent') <= 0) {
    enroll_fail(500, 'Utilisateur technique useragent introuvable');
}
if (empty($user->api_key)) {
    enroll_fail(500, 'Aucune cle API pour useragent');
}

echo json_encode(array(
    'success' => true,
    'login' => $user->login,
    'api_key' => $user->api_key,
));
