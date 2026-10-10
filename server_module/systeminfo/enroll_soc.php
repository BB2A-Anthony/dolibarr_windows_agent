<?php
/**
 * Enrollment endpoint (called by the agent at first connection).
 *
 * POST /custom/systeminfo/enroll_soc.php
 * Body JSON: {"code": "...", "guid": "..."}
 *
 * Replaces both the former global token (enroll.php) and the thirdparty
 * enrollment: a single one-time code (valid 5 minutes) generated on the
 * thirdparty card links the machine to the thirdparty AND delivers the
 * technical user's API key. The code is consumed on success.
 */
define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);
define('NOBROWSERNOTIFY', 1);

require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

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

// --- Rate limiting (F1): max 20 attempts per IP per 10 minutes -------------

$ip = empty($_SERVER['REMOTE_ADDR']) ? 'unknown' : substr($_SERVER['REMOTE_ADDR'], 0, 64);
$sql = "SELECT COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "systeminfo_ratelimit";
$sql .= " WHERE ip = '" . $db->escape($ip) . "'";
$sql .= " AND date_attempt > '" . $db->idate(dol_now() - 600) . "'";
$resql = $db->query($sql);
$nbAttempts = 0;
if ($resql) {
    $objRate = $db->fetch_object($resql);
    $nbAttempts = $objRate ? (int) $objRate->nb : 0;
}
if ($nbAttempts >= 20) {
    enroll_fail(429, 'Trop de tentatives, reessayez plus tard.');
}
$sql = "INSERT INTO " . MAIN_DB_PREFIX . "systeminfo_ratelimit (ip, date_attempt)";
$sql .= " VALUES ('" . $db->escape($ip) . "', '" . $db->idate(dol_now()) . "')";
$db->query($sql);
// Purge attempts older than 1 hour (cheap, keeps the table small).
$sql = "DELETE FROM " . MAIN_DB_PREFIX . "systeminfo_ratelimit";
$sql .= " WHERE date_attempt < '" . $db->idate(dol_now() - 3600) . "'";
$db->query($sql);

// --- Payload size limit (F5): refuse bodies larger than 1 MB ----------------

$contentLength = empty($_SERVER['CONTENT_LENGTH']) ? 0 : (int) $_SERVER['CONTENT_LENGTH'];
if ($contentLength > 1048576) {
    enroll_fail(413, 'Corps de requete trop volumineux.');
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || empty($payload['code']) || empty($payload['guid'])) {
    $guid = '';
    enroll_fail(400, 'code et guid requis');
}

$code = substr(preg_replace('/[^a-zA-Z0-9]/', '', $payload['code']), 0, 32);
$guid = substr($payload['guid'], 0, 128);
$confirmReassign = !empty($payload['confirm_reassign']);

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

// --- The machine must exist (it must have reported at least once) ------------

$sql = "SELECT rowid, fk_soc FROM " . MAIN_DB_PREFIX . "systeminfo_reports";
$sql .= " WHERE guid = '" . $db->escape($guid) . "'";
$resql = $db->query($sql);
if (!$resql) {
    enroll_fail(500, 'Erreur base de donnees');
}
$machine = $db->fetch_object($resql);
if (!$machine) {
    enroll_fail(404, 'Machine inconnue : elle doit avoir envoye au moins un rapport avant l\'enrolement');
}

// --- Reassignment proposal ---------------------------------------------------
// If the machine already belongs to a different thirdparty, ask for an
// explicit confirmation instead of silently reassigning (equipment resale).

$currentSocId = (int) ($machine->fk_soc ?: 0);
$targetSocId = (int) $obj->fk_soc;

if ($currentSocId > 0 && $currentSocId != $targetSocId && !$confirmReassign) {
    $currentThirdparty = new Societe($db);
    $currentThirdparty->fetch($currentSocId);
    $targetThirdparty = new Societe($db);
    $targetThirdparty->fetch($targetSocId);

    dol_syslog(
        'systeminfo/enroll_soc: reaffectation proposee guid=' . substr($guid, 0, 8)
        . '... tiers actuel #' . $currentSocId . ' -> tiers cible #' . $targetSocId,
        LOG_INFO
    );

    http_response_code(409);
    echo json_encode(array(
        'error' => 'Machine deja rattachee au tiers : ' . $currentThirdparty->name
            . '. Confirmer la reaffectation vers ' . $targetThirdparty->name . ' ?',
        'confirm_reassign' => true,
        'current_thirdparty_id' => $currentSocId,
        'current_thirdparty_name' => $currentThirdparty->name,
        'target_thirdparty_id' => $targetSocId,
        'target_thirdparty_name' => $targetThirdparty->name,
    ));
    exit;
}

// --- Consume the code FIRST (one-time use, atomic) -------------------------
// Consumed before the assignment so two concurrent requests with the same
// code cannot both succeed: only the one flipping used=0 -> 1 proceeds.
$sql = "UPDATE " . MAIN_DB_PREFIX . "systeminfo_enroll";
$sql .= " SET used = 1 WHERE rowid = " . (int) $obj->rowid . " AND used = 0";
$resqlConsume = $db->query($sql);
if (!$resqlConsume || $db->affected_rows($resqlConsume) == 0) {
    enroll_fail(403, 'Code deja utilise');
}


// --- Assignment -------------------------------------------------------------

$sql = "UPDATE " . MAIN_DB_PREFIX . "systeminfo_reports";
$sql .= " SET fk_soc = " . (int) $obj->fk_soc;
$sql .= " WHERE guid = '" . $db->escape($guid) . "'";
$resqlUpdate = $db->query($sql);
if (!$resqlUpdate) {
    enroll_fail(500, 'Erreur lors de l\'affectation');
}

// --- Deliver the technical user's API key -----------------------------------

$agentUser = new User($db);
if ($agentUser->fetch('', 'useragent') <= 0) {
    enroll_fail(500, 'Utilisateur technique useragent introuvable');
}
if (empty($agentUser->api_key)) {
    enroll_fail(500, 'Aucune cle API pour useragent');
}

// Return human-readable thirdparty details for the agent confirmation message.
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
    'api_key' => $agentUser->api_key,
));
