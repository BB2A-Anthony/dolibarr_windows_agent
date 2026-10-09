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
        $this->const = array();
        $this->tabs = array();
        $this->rights = array();

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

        // Store the API key in module config so the admin can copy it
        // for the agent config.json (visible in the module constants page).
        dolibarr_set_const($this->db, 'SYSTEMINFO_AGENT_APIKEY', $apikey, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'SYSTEMINFO_AGENT_LOGIN', self::AGENT_LOGIN, 'chaine', 0, '', $conf->entity);

        dol_syslog('systeminfo: utilisateur technique ' . self::AGENT_LOGIN . ' pret (apikey generee)', LOG_INFO);
        return 1;
    }
}
