<?php
/**
 * Module descriptor for the systeminfo custom module.
 */
include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modSystemInfo extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs;

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
            'api' => array('systeminfo')
        );

        $this->hidden = false;
        $this->const = array();
        $this->tabs = array();
        $this->rights = array();
    }
}
