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

    $agentVersion = GETPOST('agent_windows_version', 'alphanohtml');
    dolibarr_set_const($db, 'SYSTEMINFO_AGENT_WINDOWS_VERSION', trim($agentVersion), 'chaine', 0, '', $conf->entity);

    $downloadUrl = GETPOST('agent_download_url', 'alphanohtml');
    dolibarr_set_const($db, 'SYSTEMINFO_AGENT_DOWNLOAD_URL', trim($downloadUrl), 'chaine', 0, '', $conf->entity);

    $sha256 = GETPOST('agent_sha256', 'alphanohtml');
    dolibarr_set_const($db, 'SYSTEMINFO_AGENT_SHA256', strtolower(trim($sha256)), 'chaine', 0, '', $conf->entity);

    setEventMessage('Options enregistrees');
}

llxHeader('', 'Systeminfo - Configuration');

print load_fiche_titre('Configuration du module Systeminfo', '', 'globe');

// Security warning when HTTP is allowed.
if (getDolGlobalString('SYSTEMINFO_ALLOW_HTTP')) {
    print '<div class="warning" style="background:#fff3cd; border:1px solid #d4a017; border-left:4px solid #d4a017; padding:12px; margin-bottom:12px; border-radius:3px;">';
    print '<strong>⚠ AVERTISSEMENT SECURITE — USAGE UNIQUEMENT POUR TEST</strong>';
    print '<br>Le HTTPS est actuellement <strong>désactivé</strong> : la clé API, le jeton d\'enrôlement et les codes d\'enrôlement tiers circulent <strong>en clair</strong> sur le réseau et peuvent être interceptés.';
    print '<br>Décochez cette option en production et activez HTTPS sur le serveur.';
    print '</div>';
}

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="action" value="set">';
print '<input type="hidden" name="token" value="' . newToken() . '">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Option</th><th>Valeur</th></tr>';

$checked = getDolGlobalString('SYSTEMINFO_ALLOW_HTTP') ? ' checked' : '';
print '<tr><td>Exiger HTTPS pour les requetes de l\'agent (recommande)</td>';
print '<td><input type="checkbox" name="allow_http" value="1"' . $checked . '> Ne pas exiger HTTPS <span style="color:#d4a017; font-weight:bold;">(⚠ à risque : uniquement pour test)</span></td></tr>';

$agentVersion = getDolGlobalString('SYSTEMINFO_AGENT_WINDOWS_VERSION');
print '<tr><td>Derniere version de l\'agent Windows</td>';
print '<td><input type="text" name="agent_windows_version" value="' . dol_escape_htmltag($agentVersion) . '" size="16"> publiee aux agents via l\'API <code>GET /systeminfo/config</code></td></tr>';

$downloadUrl = getDolGlobalString('SYSTEMINFO_AGENT_DOWNLOAD_URL');
print '<tr><td>URL du paquet de mise a jour (zip)</td>';
print '<td><input type="text" name="agent_download_url" value="' . dol_escape_htmltag($downloadUrl) . '" size="64"> <span class="opacitymedium">optionnel : https vers DolibarrAgent.zip (exes PyInstaller)</span></td></tr>';

$sha256 = getDolGlobalString('SYSTEMINFO_AGENT_SHA256');
print '<tr><td>SHA-256 du paquet</td>';
print '<td><input type="text" name="agent_sha256" value="' . dol_escape_htmltag($sha256) . '" size="72"> <span class="opacitymedium">obligatoire pour la mise a jour automatique : le paquet est rejete si le checksum ne correspond pas</span></td></tr>';

print '</table>';
print '<br>';
print '<input type="submit" class="button" value="Enregistrer">';
print '</form>';

print '<p class="opacitymedium">Les endpoints de l\'agent (POST rapport, enrôlement) refusent le HTTP non chiffre avec l\'erreur 426 tant que cette option n\'est pas cochee. Cette option ne doit etre activee que pour des tests, jamais en production.</p>';

llxFooter();
$db->close();
