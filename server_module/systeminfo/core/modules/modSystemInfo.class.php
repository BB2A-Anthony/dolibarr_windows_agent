<?php
/**
 * Module descriptor for the systeminfo custom module.
 */
include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modSystemInfo extends DolibarrModules
{
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
}
