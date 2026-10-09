<?php
/**
 * Thirdparty enrollment endpoint (called by the agent at first connection).
 *
 * POST /custom/systeminfo/enroll_soc.php
 * Body JSON: {"code": "...", "guid": "..."}
 *
 * If the code is valid (< 5 min old, not used), the machine (guid) is
 * automatically assigned to the thirdparty (fk_soc) that generated it,
 * and the code is consumed.
 */
define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);
define('NOBROWSERNOTIFY', 1);

require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

header('Content-Type: application/json');

function enroll_fail($code, $message)
{
    http_response_code($code);
    echo json_encode(array('error' => $message));
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || empty($payload['code']) || empty($payload['guid'])) {
    enroll_fail(400, 'code et guid requis');
}

$code = substr(preg_replace('/[^a-zA-Z0-9]/', '', $payload['code']), 0, 32);
$guid = substr($payload['guid'], 0, 128);

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

// Assign the thirdparty to the machine and consume the code.
$sql = "UPDATE " . MAIN_DB_PREFIX . "systeminfo_reports";
$sql .= " SET fk_soc = " . (int) $obj->fk_soc;
$sql .= " WHERE guid = '" . $db->escape($guid) . "'";
if (!$db->query($sql) || $db->affected_rows($resql) == -1) {
    enroll_fail(500, 'Erreur lors de l\'affectation');
}

$sql = "UPDATE " . MAIN_DB_PREFIX . "systeminfo_enroll";
$sql .= " SET used = 1 WHERE rowid = " . (int) $obj->rowid;
$db->query($sql);

// Return human-readable thirdparty details for the agent confirmation message.
$thirdparty = new Societe($db);
$thirdparty->fetch((int) $obj->fk_soc);

echo json_encode(array(
    'success' => true,
    'guid' => $guid,
    'thirdparty_id' => (int) $obj->fk_soc,
    'thirdparty_name' => $thirdparty->name,
    'thirdparty_alias' => $thirdparty->name_alias,
    'thirdparty_zip' => $thirdparty->zip,
    'thirdparty_town' => $thirdparty->town,
));
