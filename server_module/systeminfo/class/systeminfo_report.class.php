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

    public $unique_id;
    public $fk_soc;
    public $hostname;
    public $os;
    public $report;
    public $date_creation;

    /**
     * Save a report row (history is kept).
     *
     * @return int Row id (< 0 on error)
     */
    public function create($user)
    {
        $error = 0;
        $now = dol_now();

        $this->db->begin();

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " (unique_id, fk_soc, hostname, os, report, date_creation)";
        $sql .= " VALUES (";
        $sql .= "'" . $this->db->escape($this->unique_id) . "', ";
        $sql .= ($this->fk_soc > 0 ? (int) $this->fk_soc : 'NULL') . ", ";
        $sql .= ($this->hostname ? "'" . $this->db->escape($this->hostname) . "'" : 'NULL') . ", ";
        $sql .= ($this->os ? "'" . $this->db->escape($this->os) . "'" : 'NULL') . ", ";
        $sql .= "'" . $this->db->escape($this->report) . "', ";
        $sql .= "'" . $this->db->idate($now) . "')";

        $resql = $this->db->query($sql);
        if ($resql) {
            $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX . $this->table_element);
        } else {
            $error++;
        }

        if ($error) {
            $this->db->rollback();
            return -1;
        }

        $this->db->commit();
        return $this->id;
    }

    /**
     * Fetch the latest report for a given unique_id.
     *
     * @param  string $unique_id Machine identifier
     * @return int    1 if found, 0 if not found, < 0 on error
     */
    public function fetchLatest($unique_id)
    {
        $sql = "SELECT rowid, unique_id, fk_soc, hostname, os, report, date_creation";
        $sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE unique_id = '" . $this->db->escape($unique_id) . "'";
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
        $this->unique_id = $obj->unique_id;
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
        $sql = "SELECT rowid, unique_id, fk_soc, hostname, os, report, date_creation";
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
        $this->unique_id = $obj->unique_id;
        $this->fk_soc = $obj->fk_soc;
        $this->hostname = $obj->hostname;
        $this->os = $obj->os;
        $this->report = $obj->report;
        $this->date_creation = $this->db->jdate($obj->date_creation);
        return 1;
    }

    /**
     * List the latest report of each known machine.
     *
     * @return array Array of report rows (raw objects), empty on error
     */
    public function listLatestPerMachine()
    {
        $sql = "SELECT r.rowid, r.unique_id, r.fk_soc, r.hostname, r.os, r.report, r.date_creation";
        $sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element . " AS r";
        $sql .= " INNER JOIN (";
        $sql .= "   SELECT unique_id, MAX(rowid) AS maxid";
        $sql .= "   FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= "   GROUP BY unique_id";
        $sql .= " ) AS m ON m.unique_id = r.unique_id AND m.maxid = r.rowid";
        $sql .= " ORDER BY r.unique_id";

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
