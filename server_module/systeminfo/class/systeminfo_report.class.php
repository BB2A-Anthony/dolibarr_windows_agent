<?php
/**
 * DAO class for systeminfo reports.
 */
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class SysteminfoReport extends CommonObject
{
    public $element = 'systeminforeport';
    public $table_element = 'systeminfo_reports';

    /**
     * @var DoliDB Database handler
     */
    public $db;

    public $guid;
    public $fk_soc;
    public $hostname;
    public $os;
    public $report;
    public $date_creation;

    /**
     * Save a report: update the existing row for this machine (guid),
     * or create it on first report (upsert, one row per machine).
     *
     * @return int Row id (< 0 on error)
     */
    public function save($user)
    {
        $existing = new SysteminfoReport($this->db);
        $found = $existing->fetchLatest($this->guid);
        if ($found < 0) {
            return -1;
        }

        $now = dol_now();

        if ($found == 1) {
            $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET";
            $sql .= " fk_soc = " . ($this->fk_soc > 0 ? (int) $this->fk_soc : 'NULL') . ",";
            $sql .= " hostname = " . ($this->hostname ? "'" . $this->db->escape($this->hostname) . "'" : 'NULL') . ",";
            $sql .= " os = " . ($this->os ? "'" . $this->db->escape($this->os) . "'" : 'NULL') . ",";
            $sql .= " report = '" . $this->db->escape($this->report) . "',";
            $sql .= " date_creation = '" . $this->db->idate($now) . "'";
            $sql .= " WHERE rowid = " . (int) $existing->id;

            if ($this->db->query($sql)) {
                $this->id = $existing->id;
                return $this->id;
            }
            return -1;
        }

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " (guid, fk_soc, hostname, os, report, date_creation)";
        $sql .= " VALUES (";
        $sql .= "'" . $this->db->escape($this->guid) . "', ";
        $sql .= ($this->fk_soc > 0 ? (int) $this->fk_soc : 'NULL') . ", ";
        $sql .= ($this->hostname ? "'" . $this->db->escape($this->hostname) . "'" : 'NULL') . ", ";
        $sql .= ($this->os ? "'" . $this->db->escape($this->os) . "'" : 'NULL') . ", ";
        $sql .= "'" . $this->db->escape($this->report) . "', ";
        $sql .= "'" . $this->db->idate($now) . "')";

        if ($this->db->query($sql)) {
            $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX . $this->table_element);
            return $this->id;
        }
        return -1;
    }

    /**
     * Fetch the latest report for a given guid.
     *
     * @param  string $guid Machine identifier
     * @return int    1 if found, 0 if not found, < 0 on error
     */
    public function fetchLatest($guid)
    {
        $sql = "SELECT rowid, guid, fk_soc, hostname, os, report, date_creation";
        $sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE guid = '" . $this->db->escape($guid) . "'";
        $sql .= " ORDER BY date_creation DESC, rowid DESC";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return -1;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return 0;
        }

        $this->id = $obj->rowid;
        $this->guid = $obj->guid;
        $this->fk_soc = $obj->fk_soc;
        $this->hostname = $obj->hostname;
        $this->os = $obj->os;
        $this->report = $obj->report;
        $this->date_creation = $this->db->jdate($obj->date_creation);
        return 1;
    }

    /**
     * Fetch the latest report for a given thirdparty (fk_soc).
     *
     * @param  int $fk_soc Thirdparty id
     * @return int 1 if found, 0 if not found, < 0 on error
     */
    public function fetchLatestBySoc($fk_soc)
    {
        $sql = "SELECT rowid, guid, fk_soc, hostname, os, report, date_creation";
        $sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE fk_soc = " . (int) $fk_soc;
        $sql .= " ORDER BY date_creation DESC, rowid DESC";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return -1;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return 0;
        }

        $this->id = $obj->rowid;
        $this->guid = $obj->guid;
        $this->fk_soc = $obj->fk_soc;
        $this->hostname = $obj->hostname;
        $this->os = $obj->os;
        $this->report = $obj->report;
        $this->date_creation = $this->db->jdate($obj->date_creation);
        return 1;
    }

    /**
     * List all machines (one row per machine since save() is an upsert).
     *
     * @return array Array of report rows (raw objects), empty on error
     */
    public function listLatestPerMachine()
    {
        $sql = "SELECT rowid, guid, fk_soc, hostname, os, report, date_creation";
        $sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " ORDER BY guid";

        $rows = array();
        $resql = $this->db->query($sql);
        if (!$resql) {
            return $rows;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = $obj;
        }
        return $rows;
    }
}
