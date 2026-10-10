<?php
/**
 * "Add machine" page linked from the thirdparty card.
 * Generates a one-time enrollment code (valid 5 minutes) and displays it.
 *
 * Security: requires the module 'enroll' permission, a linked employee user
 * (internal), restrictedArea() on the thirdparty and a Dolibarr CSRF token.
 */
// This page is authenticated: no NOLOGIN here, the user must have a
// Dolibarr session with the module rights.
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

// --- Access control ---------------------------------------------------------

global $conf, $db, $user;

if (empty($user) || empty($user->id)) {
    accessforbidden('Utilisateur non authentifie', 0, 1);
}

// Restriction: user must be an internal employee (link to a Dolibarr user
// with employee flag, i.e. not an external contact).
if (!empty($user->socid)) {
    accessforbidden('Acces reserve aux utilisateurs internes (salaries)', 0, 1);
}
if (empty($user->employee) && empty($user->admin)) {
    accessforbidden('Acces reserve aux utilisateurs salaries', 0, 1);
}

// Module permission check: systeminfo->enroll.
if (!$user->hasRight('systeminfo', 'enroll')) {
    accessforbidden('Permission manquante : Ajouter une machine (enrolement)', 0, 1);
}

$id = GETPOST('socid', 'int');
if (empty($id)) {
    $id = GETPOST('id', 'int');
}

$object = new Societe($db);
if (empty($id) || $object->fetch($id) <= 0) {
    accessforbidden('Tiers inconnu', 0, 1);
}

// restrictedArea: check read access to this thirdparty (handles
// commercial-representative restrictions and socid user restrictions).
restrictedArea($user, 'societe', $object->id, 'societe');

// --- CSRF protection --------------------------------------------------------

// The page is read-only (GET generates a code), but Dolibarr's token
// mechanism is used to validate session on this page.
$backtopage = '';
$token = newToken();
if (GETPOST('token', 'alpha') && GETPOST('token', 'alpha') != $token) {
    accessforbidden('Jeton de securite invalide (CSRF)', 0, 1);
}

llxHeader('', 'Agent - Ajouter une machine');

// Purge expired or used codes (F6): keeps the table small.
$sql = "DELETE FROM " . MAIN_DB_PREFIX . "systeminfo_enroll";
$sql .= " WHERE date_valid < '" . $db->idate(dol_now() - 3600) . "'";
$sql .= " OR (used = 1 AND date_valid < '" . $db->idate(dol_now()) . "')";
$db->query($sql);

// Limit active codes per thirdparty (F3): max 10 unused, unexpired codes.
$sql = "SELECT COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "systeminfo_enroll";
$sql .= " WHERE fk_soc = " . (int) $object->id;
$sql .= " AND used = 0 AND date_valid > '" . $db->idate(dol_now()) . "'";
$resql = $db->query($sql);
$nbActive = 0;
if ($resql) {
    $objCount = $db->fetch_object($resql);
    $nbActive = $objCount ? (int) $objCount->nb : 0;
}

$validity = 300;
$error = '';
$code = '';

if ($nbActive >= 10) {
    $error = 'Trop de codes actifs pour ce tiers : attendez leur expiration (5 minutes) avant d\'en generer de nouveaux.';
} else {
    // C3 fix: cryptographically secure code (random_bytes), not md5(uniqid()).
    $code = strtoupper(bin2hex(random_bytes(4)));

    $sql = "INSERT INTO " . MAIN_DB_PREFIX . "systeminfo_enroll";
    $sql .= " (code, fk_soc, date_valid, used)";
    $sql .= " VALUES ('" . $db->escape($code) . "', " . (int) $object->id;
    $sql .= ", '" . $db->idate(dol_now() + $validity) . "', 0)";
    if (!$db->query($sql)) {
        $error = $db->lasterror();
    } else {
        dol_syslog(
            'systeminfo: code enrolement genere pour tiers #' . $object->id
            . ' par utilisateur #' . $user->id,
            LOG_INFO
        );
    }
}

print load_fiche_titre('Ajouter une machine au tiers : ' . dol_escape_htmltag($object->name), '', 'globe');

if ($error) {
    print '<div class="error">' . dol_escape_htmltag($error) . '</div>';
} else {
    print '<div class="fichecenter">';
    print '<p>Ce code d\'enrôlement unique lie une machine à ce tiers.</p>';
    print '<p><strong>Validité : 5 minutes.</strong> Saisissez-le dans l\'agent Windows lors de sa première connexion (Paramètres → Code d\'enrôlement tiers).</p>';
    print '<p style="font-size: 2em; letter-spacing: 0.3em; font-family: monospace; font-weight: bold;">' . dol_escape_htmltag($code) . '</p>';
    print '<p class="opacitymedium">Une fois le code utilisé, la machine remonte avec ses informations système et est automatiquement rattachée à <strong>' . dol_escape_htmltag($object->name) . '</strong>.</p>';
    print '</div>';
}

llxFooter();
$db->close();
