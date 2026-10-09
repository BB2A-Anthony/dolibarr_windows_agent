<?php
/**
 * Dictionary object for tracked software (llx_systeminfo_softwares).
 */
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class SysteminfoSoftware extends CommonObject
{
    public $element = 'systeminfosoftware';
    public $table_element = 'systeminfo_softwares';

    public $ref;
    public $label;
    public $pattern;
    public $active;

    public $fields = array(
        'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'showoncombobox' => 1),
        'label' => array('type' => 'varchar(255)', 'label' => 'Label', 'enabled' => 1, 'position' => 20, 'notnull' => 1),
        'pattern' => array('type' => 'varchar(255)', 'label' => 'SearchPattern', 'enabled' => 1, 'position' => 30),
        'active' => array('type' => 'tinyint', 'label' => 'Active', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'default' => 1),
    );

    public $ismultientitymanaged = 0;
}
