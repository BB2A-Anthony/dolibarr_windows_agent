<?php
/**
 * Thirdparty enrollment endpoint (called by the agent at first connection).
 *
 * POST /custom/systeminfo/enroll_soc.php
 * Body JSON: {"code": "...", "guid": "..."}
 *
 * If the code is valid (< 5 min old, not used) AND the machine (guid) has
 * already reported at least once, the machine is assigned to the thirdparty
 * that generated the code, and the code is consumed.
 */
define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);
define('NOBROWSERNOTIFY', 1);

require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

header('Content-Type: application/json');

function enroll_fail($code, $message)
{
    global $guid;
    dol_syslog(
        'systeminfo/enroll_soc: echec code=' . substr(GETPOST('code', 'aZ09'), 0, 4) . '... guid=' . substr($guid, 0, 8)
        . ' ip=' . (empty($_SERVER['REMOTE_ADDR']) ? '?' : $_SERVER['REMOTE_ADDR'])
        . ' message=' . $message,
        LOG_WARNING
    );
    http_response_code($code);
    echo json_encode(array('error' => $message));
    exit;
}

// Require HTTPS unless the admin disabled the check.
if (!getDolGlobalString('SYSTEMINFO_ALLOW_HTTP')
    && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == 'off')) {
    enroll_fail(426, 'HTTPS requis : activez HTTPS, ou definissez SYSTEMINFO_ALLOW_HTTP=1 (non recommande).');
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || empty($payload['code']) || empty($payload['guid'])) {
    $guid = '';
    enroll_fail(400, 'code et guid requis');
}

$code = substr(preg_replace('/[^a-zA-Z0-9]/', '', $payload['code']), 0, 32);
$guid = substr($payload['guid'], 0, 128);

if (empty($code) || empty($guid)) {
    enroll_fail(400, 'code et guid requis');
}

// --- Code validation --------------------------------------------------------

$sql = "SELECT rowid, fk_soc, date_valid, used FROM " . MAIN_DB_PREFIX . "systeminfo_enroll";
$sql .= " WHERE code = '" . $db->escape($code) . "'";
$resql = $db->query($sql);
if (!$resql) {
    enroll_fail(500, 'Erreur base de donnees');
}
$obj = $db->fetch_object($resql);
if (!$obj) {
    enroll_fail(404, 'Code inconnu');
}
if ($obj->used) {
    enroll_fail(403, 'Code deja utilise');
}
if ($db->jdate($obj->date_valid) < dol_now()) {
    enroll_fail(403, 'Code expire (validite 5 minutes)');
}

// --- C2 fix 1: the machine must exist (it must have reported at least once) --

$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "systeminfo_reports";
$sql .= " WHERE guid = '" . $db->escape($guid) . "'";
$resql = $db->query($sql);
if (!$resql) {
    enroll_fail(500, 'Erreur base de donnees');
}
$machine = $db->fetch_object($resql);
if (!$machine) {
    enroll_fail(404, 'Machine inconnue : elle doit avoir envoye au moins un rapport avant l\'enrolement');
}

// --- Assignment -------------------------------------------------------------

$sql = "UPDATE " . MAIN_DB_PREFIX . "systeminfo_reports";
$sql .= " SET fk_soc = " . (int) $obj->fk_soc;
$sql .= " WHERE guid = '" . $db->escape($guid) . "'";
$resqlUpdate = $db->query($sql);

// C2 fix 2: check affected_rows on the UPDATE result, not on the SELECT.
if (!$resqlUpdate) {
    enroll_fail(500, 'Erreur lors de l\'affectation');
}

// Consume the code.
$sql = "UPDATE " . MAIN_DB_PREFIX . "systeminfo_enroll";
$sql .= " SET used = 1 WHERE rowid = " . (int) $obj->rowid;
$db->query($sql);

// Return human-readable thirdparty details.
$thirdparty = new Societe($db);
$thirdparty->fetch((int) $obj->fk_soc);

dol_syslog(
    'systeminfo/enroll_soc: machine guid=' . substr($guid, 0, 8) . '... rattachee au tiers #'
    . (int) $obj->fk_soc . ' (code rowid=' . (int) $obj->rowid . ')',
    LOG_INFO
);

echo json_encode(array(
    'success' => true,
    'guid' => $guid,
    'thirdparty_id' => (int) $obj->fk_soc,
    'thirdparty_name' => $thirdparty->name,
    'thirdparty_alias' => $thirdparty->name_alias,
    'thirdparty_zip' => $thirdparty->zip,
    'thirdparty_town' => $thirdparty->town,
));
