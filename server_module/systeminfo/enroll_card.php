<?php
/**
 * "Add machine" page linked from the thirdparty card.
 * Generates a one-time enrollment code (valid 5 minutes) and displays it,
 * with the machine assignment instructions.
 */
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

$id = GETPOST('socid', 'int');
if (empty($id)) {
    $id = GETPOST('id', 'int');
}

$object = new Societe($db);
if (empty($id) || $object->fetch($id) <= 0) {
    accessforbidden('Tiers inconnu', 0, 1);
}

llxHeader('', 'Agent - Ajouter une machine');

// Generate a fresh code on each display of this page.
$hooktype = 'systeminfo';
$parameters = array('socid' => $object->id);

$code = '';
$validity = 300;
$error = '';

$code = strtoupper(substr(md5(uniqid('', true) . $object->id), 0, 8));
$sql = "INSERT INTO " . MAIN_DB_PREFIX . "systeminfo_enroll";
$sql .= " (code, fk_soc, date_valid, used)";
$sql .= " VALUES ('" . $db->escape($code) . "', " . (int) $object->id;
$sql .= ", '" . $db->idate(dol_now() + $validity) . "', 0)";
if (!$db->query($sql)) {
    $error = $db->lasterror();
}

print load_fiche_titre('Ajouter une machine au tiers : ' . $object->name, '', 'globe');

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
