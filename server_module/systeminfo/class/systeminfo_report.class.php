<?php
/**
 * DAO class for systeminfo reports.
 *
 * One row per machine (upsert on guid). Each key metric is stored in its
 * own column so lists/filters can query it directly.
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
     * Report columns (metric per column, filterable).
     *
     * @var array column => sql type for escape decisions
     */
    public static $columns = array(
        'fk_soc' => 'int',
        'hostname' => 'string',
        'fqdn' => 'string',
        'os' => 'string',
        'os_version' => 'string',
        'os_build' => 'string',
        'architecture' => 'string',
        'processor' => 'string',
        'cpu_logical' => 'int',
        'cpu_physical' => 'int',
        'cpu_percent' => 'double',
        'memory_total' => 'int',
        'memory_available' => 'int',
        'memory_percent' => 'double',
        'disk_total' => 'int',
        'disk_used' => 'int',
        'disk_free' => 'int',
        'disk_percent' => 'double',
        'ip_address' => 'string',
        'mac_address' => 'string',
        'firewall_enabled' => 'int',
        'pending_updates' => 'int',
        'last_update_date' => 'date',
        'softwares_missing' => 'int',
    );

    /**
     * Build the column values of this object from an agent payload.
     *
     * @param array $payload Report sent by the agent (with 'softwares' matched)
     * @return void
     */
    public function setFromPayload($payload)
    {
        $os = isset($payload['os_details']) ? $payload['os_details'] : array();

        $this->fk_soc = isset($payload['fk_soc']) ? (int) $payload['fk_soc'] : null;
        $this->hostname = isset($payload['hostname']) ? $payload['hostname'] : null;
        $this->fqdn = isset($payload['fqdn']) ? $payload['fqdn'] : null;
        $this->os = isset($os['ProductName']) ? $os['ProductName'] : null;
        $this->os_version = isset($payload['platform_version']) ? $payload['platform_version'] : null;
        $this->os_build = isset($os['CurrentBuild']) ? $os['CurrentBuild'] : null;
        $this->architecture = isset($payload['architecture']) ? $payload['architecture'] : null;
        $this->processor = isset($payload['processor']) ? $payload['processor'] : null;

        $cpu = isset($payload['cpu']) ? $payload['cpu'] : array();
        $this->cpu_logical = isset($cpu['count_logical']) ? (int) $cpu['count_logical'] : null;
        $this->cpu_physical = isset($cpu['count_physical']) ? (int) $cpu['count_physical'] : null;
        $this->cpu_percent = isset($cpu['percent_used']) ? (double) $cpu['percent_used'] : null;

        $mem = isset($payload['memory']) ? $payload['memory'] : array();
        $this->memory_total = isset($mem['total_bytes']) ? (int) $mem['total_bytes'] : null;
        $this->memory_available = isset($mem['available_bytes']) ? (int) $mem['available_bytes'] : null;
        $this->memory_percent = isset($mem['percent_used']) ? (double) $mem['percent_used'] : null;

        // Main (system) disk = first entry, fallback C:\
        $disk = null;
        if (isset($payload['disks']) && is_array($payload['disks'])) {
            foreach ($payload['disks'] as $d) {
                if (isset($d['mountpoint']) && stripos($d['mountpoint'], 'C:') !== false) {
                    $disk = $d;
                    break;
                }
            }
            if (!$disk && count($payload['disks']) > 0) {
                $disk = $payload['disks'][0];
            }
        }
        if ($disk) {
            $this->disk_total = isset($disk['total_bytes']) ? (int) $disk['total_bytes'] : null;
            $this->disk_used = isset($disk['used_bytes']) ? (int) $disk['used_bytes'] : null;
            $this->disk_free = isset($disk['free_bytes']) ? (int) $disk['free_bytes'] : null;
            $this->disk_percent = isset($disk['percent_used']) ? (double) $disk['percent_used'] : null;
        }

        // First IPv4 address found
        if (isset($payload['network_interfaces']) && is_array($payload['network_interfaces'])) {
            foreach ($payload['network_interfaces'] as $nic) {
                foreach ((isset($nic['addresses']) ? $nic['addresses'] : array()) as $addr) {
                    if (isset($addr['family']) && $addr['family'] == '2' && isset($addr['address'])) {
                        $this->ip_address = $addr['address'];
                        break 2;
                    }
                }
            }
        }
        $macs = isset($payload['mac_addresses']) ? $payload['mac_addresses'] : array();
        $this->mac_address = !empty($macs) ? $macs[0] : null;

        $fw = isset($payload['firewall']) ? $payload['firewall'] : array();
        $this->firewall_enabled = isset($fw['enabled']) ? (int) $fw['enabled'] : null;

        $upd = isset($payload['updates']) ? $payload['updates'] : array();
        $this->pending_updates = isset($upd['pending_count']) ? (int) $upd['pending_count'] : null;
        if (isset($upd['last_update']['date']) && $upd['last_update']['date']) {
            $ts = strtotime($upd['last_update']['date']);
            if ($ts) {
                $this->last_update_date = $ts;
            }
        }

        $missing = 0;
        if (isset($payload['softwares']) && is_array($payload['softwares'])) {
            foreach ($payload['softwares'] as $sw) {
                if (isset($sw['installed']) && !$sw['installed']) {
                    $missing++;
                }
            }
        }
        $this->softwares_missing = $missing;
    }

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
            $sql .= $this->_columnSql();
            $sql .= ", date_creation = '" . $this->db->idate($now) . "'";
            $sql .= " WHERE rowid = " . (int) $existing->id;

            if ($this->db->query($sql)) {
                $this->id = $existing->id;
                return $this->id;
            }
            return -1;
        }

        $cols = array_keys(self::$columns);
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " (guid, " . implode(", ", $cols) . ", report, date_creation)";
        $sql .= " VALUES ('" . $this->db->escape($this->guid) . "', ";
        $sql .= $this->_columnSql(true) . ", ";
        $sql .= "'" . $this->db->escape($this->report) . "', ";
        $sql .= "'" . $this->db->idate($now) . "')";

        if ($this->db->query($sql)) {
            $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX . $this->table_element);
            return $this->id;
        }
        return -1;
    }

    /**
     * Build the SQL fragment for metric columns.
     *
     * @param  bool $valuesOnly true for INSERT VALUES (no "col =" prefix)
     * @return string
     */
    private function _columnSql($valuesOnly = false)
    {
        $parts = array();
        foreach (self::$columns as $col => $type) {
            $val = $this->$col;
            $sqlVal = 'NULL';
            if ($val !== null && $val !== '') {
                switch ($type) {
                    case 'int':
                        $sqlVal = (string) (int) $val;
                        break;
                    case 'double':
                        $sqlVal = (string) (double) $val;
                        break;
                    case 'date':
                        $sqlVal = "'" . $this->db->idate($val) . "'";
                        break;
                    default:
                        $sqlVal = "'" . $this->db->escape($val) . "'";
                }
            }
            $parts[] = $valuesOnly ? $sqlVal : $col . " = " . $sqlVal;
        }
        if ($valuesOnly) {
            return implode(", ", $parts);
        }
        return implode(", ", $parts);
    }

    /**
     * Fetch the latest report for a given guid.
     *
     * @param  string $guid Machine identifier
     * @return int    1 if found, 0 if not found, < 0 on error
     */
    public function fetchLatest($guid)
    {
        $sql = "SELECT *" . " FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE guid = '" . $this->db->escape($guid) . "'";

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
     * @param  string $where Optional SQL filter (already sanitized)
     * @return array Array of report rows (raw objects), empty on error
     */
    public function listLatestPerMachine($where = '')
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . $this->table_element . " AS t";
        if ($where) {
            $sql .= " WHERE " . $where;
        }
        $sql .= " ORDER BY t.guid";

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
