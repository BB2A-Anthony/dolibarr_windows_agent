<?php
/**
 * Module settings page: HTTPS requirement and other options.
 */
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

global $conf, $db, $langs;

$langs->load('admin');
$langs->load('systeminfo@systeminfo');

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action == 'set') {
    $allowHttp = GETPOST('allow_http', 'int');
    dolibarr_set_const($db, 'SYSTEMINFO_ALLOW_HTTP', $allowHttp ? 1 : 0, 'chaine', 0, '', $conf->entity);
    setEventMessage('Options enregistrees');
}

llxHeader('', 'Systeminfo - Configuration');

print load_fiche_titre('Configuration du module Systeminfo', '', 'globe');

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="action" value="set">';
print '<input type="hidden" name="token" value="' . newToken() . '">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Option</th><th>Valeur</th></tr>';

$checked = getDolGlobalString('SYSTEMINFO_ALLOW_HTTP') ? ' checked' : '';
print '<tr><td>Exiger HTTPS pour les requetes de l\'agent (recommande)</td>';
print '<td><input type="checkbox" name="allow_http" value="1"' . $checked . '> Ne pas exiger HTTPS (autoriser HTTP, non recommande)</td></tr>';

print '</table>';
print '<br>';
print '<input type="submit" class="button" value="Enregistrer">';
print '</form>';

print '<p class="opacitymedium">Les endpoints de l\'agent (POST rapport, enrôlement) refusent le HTTP non chiffre avec l\'erreur 426 tant que cette option n\'est pas cochee.</p>';

llxFooter();
$db->close();
