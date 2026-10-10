<?php
/**
 * Module descriptor for the systeminfo custom module.
 */
include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modSystemInfo extends DolibarrModules
{
    /**
     * Technical user created by the module for the Windows agent.
     * Its password is random and never stored: the agent only uses
     * the API key (DOLIBARR_API_KEY header).
     */
    const AGENT_LOGIN = 'useragent';

    /**
     * Latest published version of the Windows agent. Exposed to the agent
     * through the API (getConfig) so it can detect and apply updates.
     */
    const AGENT_WINDOWS_VERSION = '1.1.0';

    /**
     * Download URL of the agent package (zip). Exposed to the agent
     * through the API (getConfig) for the auto-update mechanism.
     * Defaults to the Git repository release tagged with the version
     * stored in AGENT_WINDOWS_VERSION: the '{version}' placeholder is
     * replaced by that version in getConfig.
     */
    const AGENT_WINDOWS_UPDATE_URL = 'https://github.com/BB2A-Anthony/dolibarr_windows_agent/releases/download/{version}/DolibarrAgent.zip';

    public function __construct($db)
    {
        global $conf, $langs;

        $this->db = $db;
        $this->numero = 449000;
        $this->rights_class = 'systeminfo';
        $this->family = "other";
        $this->module_position = 500;
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Reception des informations systemes envoyees par l'agent Windows";

        $this->version = '1.0.0';
        $this->picto = 'globe';

        $this->module_parts = array(
            'api' => array('systeminfo'),
            'hooks' => array(
                'thirdpartycard'
            )
        );

        $this->hidden = false;
        $this->config_page_url = array('systeminfo.php', 'systeminfo');
        $this->const = array();
        $this->tabs = array(
            // The 5th field is a condition evaluated by verifCond():
            // the tab is only visible with the module 'enroll' permission.
            'thirdparty:+agent:Ajouter une machine (agent):systeminfo@systeminfo:/custom/systeminfo/enroll_card.php?socid=__ID__:$user->hasRight(\'systeminfo\', \'enroll\')'
        );

        // Module-specific permissions.
        $this->rights = array();
        $r = 0;

        $r++;
        $this->rights[$r][0] = $this->numero + 1; // 449001
        $this->rights[$r][1] = 'Lire les informations systeme des machines';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'read';

        $r++;
        $this->rights[$r][0] = $this->numero + 2; // 449002
        $this->rights[$r][1] = 'Ajouter une machine (enrolement)';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'enroll';

        $r++;
        $this->rights[$r][0] = $this->numero + 3; // 449003
        $this->rights[$r][1] = 'Ecrire les informations systeme (agent)';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'write';

        $this->dictionaries = array(
            'langs' => 'systeminfo@systeminfo',
            'tabname' => array(
                MAIN_DB_PREFIX . "systeminfo_softwares"
            ),
            'tablib' => array(
                "SysteminfoSoftwares"
            ),
            'tablabel' => array(
                "Logiciels à surveiller"
            ),
            'tabfield' => array(
                "ref,label,pattern,active"
            ),
            'tabfieldvalue' => array(
                "ref,label,pattern,active"
            ),
            'tabfieldinsert' => array(
                "ref,label,pattern,active"
            ),
            'tabrowid' => array(
                "rowid"
            ),
            'tabcond' => array(
                $conf->systeminfo->enabled
            ),
            'tabhelp' => array(
                array("pattern" => "Motif (regex) recherché dans les noms de logiciels installés, ex. /office/i. Si vide, utilise ref et label.")
            )
        );
    }

    /**
     * Module activation: create the technical agent user and its API key.
     *
     * @param  string $options Options when enabling module
     * @return int             1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        $result = $this->_loadFiles('/systeminfo/sql/');
        if ($result < 0) {
            return 0;
        }

        if ($this->_createAgentUser() < 0) {
            return 0;
        }

        return $this->_init($options);
    }

    /**
     * Create the technical user 'useragent' with a random password
     * (never stored anywhere) and an API key for REST calls.
     *
     * @return int 1 OK, 0 nothing to do, < 0 KO
     */
    private function _createAgentUser()
    {
        global $conf, $user, $langs;

        require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

        $tmpuser = new User($this->db);
        $result = $tmpuser->fetch('', self::AGENT_LOGIN);

        $apikey = '';
        if ($result > 0 && !empty($tmpuser->api_key)) {
            $apikey = $tmpuser->api_key;
        } else {
            $apikey = dol_trunc(uniqid('', true) . uniqid('', true), 40, 'right', 'UTF-8', false);
        }

        // Random one-time password: only used to satisfy Dolibarr's
        // password policy at creation, discarded right after, never stored.
        $randomPassword = dol_get_random(4, 4)
            . strtoupper(dol_get_random(2, 2))
            . dol_get_random(2, 2) . '!' . dol_get_random(2, 2) . '#';

        if ($result > 0) {
            // User exists: only (re)set the API key, never touch the password.
            $tmpuser->api_key = $apikey;
            if ($tmpuser->update($tmpuser->id, $user, 0, 0, 1) < 0) {
                $this->error = $tmpuser->error;
                return -1;
            }
        } else {
            $tmpuser->login = self::AGENT_LOGIN;
            $tmpuser->lastname = 'Agent';
            $tmpuser->firstname = 'Systeminfo';
            $tmpuser->pass = $randomPassword;
            $tmpuser->api_key = $apikey;
            $tmpuser->admin = 0;
            $tmpuser->entity = 0;
            $tmpuser->email = '';
            if ($tmpuser->create($user, 0) < 0) {
                $this->error = $tmpuser->error;
                return -1;
            }
        }
        $randomPassword = '';
        unset($randomPassword);

        dolibarr_set_const($this->db, 'SYSTEMINFO_AGENT_LOGIN', self::AGENT_LOGIN, 'chaine', 0, '', $conf->entity);

        // Grant read + write rights to the technical user so it can post
        // reports through the API (Dolibarr checks module rights per user).
        foreach (array($this->numero + 1, $this->numero + 3) as $rightId) {
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "user_rights";
            $sql .= " WHERE fk_user = " . (int) $tmpuser->id . " AND fk_id = " . (int) $rightId;
            $resql = $this->db->query($sql);
            if ($resql && $this->db->num_rows($resql) == 0) {
                $sql = "INSERT INTO " . MAIN_DB_PREFIX . "user_rights (fk_user, fk_id)";
                $sql .= " VALUES (" . (int) $tmpuser->id . ", " . (int) $rightId . ")";
                $this->db->query($sql);
            }
        }

        dol_syslog('systeminfo: utilisateur technique ' . self::AGENT_LOGIN . ' pret (apikey generee)', LOG_INFO);
        return 1;
    }
}
