<?php
/**
 * REST API endpoints for the systeminfo module (Dolibarr API Explorer).
 *
 * POST /api/index.php/systeminfo/machine        -> store a report
 * GET  /api/index.php/systeminfo/machine        -> latest report per machine
 * GET  /api/index.php/systeminfo/machine/{id}   -> latest report of one machine
 */
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once __DIR__ . '/systeminfo_report.class.php';
require_once __DIR__ . '/systeminfo_software.class.php';

class Systeminfo extends DolibarrApi
{
    /**
     * @var DoliDB Database handler
     */
    private $db;

    /**
     * Constructor.
     */
    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * Receive system info from the Windows agent and store it.
     *
     * @param  array $payload JSON body sent by the agent
     * @return array
     * @throws RestException 400 Missing guid
     */
    public function postMachine($payload)
    {
        global $user;

        $this->_checkHttps();

        // The agent posts reports: require the module write permission
        // (granted to the technical 'useragent' at module activation).
        if (empty($user) || !$user->hasRight('systeminfo', 'write')) {
            throw new RestException(
                403,
                'Permission systeminfo write requise pour envoyer un rapport'
            );
        }

        if (empty($payload['guid'])) {
            throw new RestException(400, 'Champ guid manquant');
        }

        $payload['softwares'] = $this->_matchSoftwares(
            isset($payload['installed_softwares']) ? $payload['installed_softwares'] : array()
        );

        $report = new SysteminfoReport($this->db);
        $report->guid = $payload['guid'];
        $report->setFromPayload($payload);
        $report->report = json_encode($payload);
        $id = $report->save($user);

        if ($id < 0) {
            throw new RestException(500, 'Erreur lors de l\'enregistrement du rapport');
        }

        dol_syslog(
            'systeminfo: rapport enregistre (rowid=' . $id . ') pour la machine guid='
            . $payload['guid'],
            LOG_INFO
        );

        return array(
            'success' => true,
            'report_id' => $id,
            'guid' => $payload['guid'],
            'softwares' => $payload['softwares'],
        );
    }

    /**
     * Assign a machine (guid) to a thirdparty. Called from Dolibarr,
     * not from the agent: the agent never sends fk_soc.
     *
     * @param  string $guid   Machine identifier
     * @param  int    $fk_soc Thirdparty id
     * @return array
     * @throws RestException 404 Unknown machine/thirdparty
     */
    public function putMachineSoc($guid, $fk_soc)
    {
        $this->_checkHttps();

        if (empty($guid) || empty($fk_soc)) {
            throw new RestException(400, 'guid et fk_soc requis');
        }

        $report = new SysteminfoReport($this->db);
        if ($report->fetchLatest($guid) != 1) {
            throw new RestException(404, 'Machine guid=' . $guid . ' introuvable');
        }

        $thirdparty = new Societe($this->db);
        if ($thirdparty->fetch((int) $fk_soc) <= 0) {
            throw new RestException(404, 'fk_soc=' . $fk_soc . ' : tiers introuvable');
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . "systeminfo_reports";
        $sql .= " SET fk_soc = " . (int) $fk_soc;
        $sql .= " WHERE rowid = " . (int) $report->id;
        if (!$this->db->query($sql)) {
            throw new RestException(500, 'Erreur lors de l\'affectation');
        }

        return array(
            'success' => true,
            'guid' => $guid,
            'thirdparty_id' => (int) $fk_soc,
        );
    }

    /**
     * Match installed softwares against the dictionary (llx_systeminfo_softwares).
     *
     * @param  array $installed List of installed software names sent by the agent
     * @return array ref => array(label, installed, match)
     */
    private function _matchSoftwares($installed)
    {
        $out = array();

        $sql = "SELECT ref, label, pattern FROM " . MAIN_DB_PREFIX . "systeminfo_softwares";
        $sql .= " WHERE active = 1";
        $resql = $this->db->query($sql);
        if (!$resql) {
            return $out;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $candidates = array($obj->ref, $obj->label);
            if (!empty($obj->pattern)) {
                $candidates[] = $obj->pattern;
            }
            $found = null;
            foreach ($installed as $name) {
                foreach ($candidates as $candidate) {
                    if ($candidate !== '' && stripos($name, $candidate) !== false) {
                        $found = $name;
                        break 2;
                    }
                }
            }
            $out[$obj->ref] = array(
                'label' => $obj->label,
                'installed' => ($found !== null),
                'match' => $found,
            );
        }
        return $out;
    }

    /**
     * Get the latest report of each known machine.
     *
     * Optional SQL filter on metric columns, e.g.:
     *   GET /systeminfo/machine?sqlfilters=(t.firewall_enabled:=:0)
     *   GET /systeminfo/machine?sqlfilters=(t.pending_updates:>:0)
     *
     * @param  string $sqlfilters Filter syntax (Dolibarr standard)
     * @return array
     * @throws RestException 400 Invalid filter
     */
    public function indexMachine($sqlfilters = '')
    {
        $where = '';
        if ($sqlfilters) {
            try {
                $where = $this->db->sanitizeSqlFilter($sqlfilters);
            } catch (Exception $e) {
                throw new RestException(400, 'Filtre invalide: ' . $e->getMessage());
            }
        }

        $report = new SysteminfoReport($this->db);
        $rows = $report->listLatestPerMachine($where);
        $out = array();
        foreach ($rows as $obj) {
            $out[] = $this->_formatRow($obj);
        }
        return $out;
    }

    /**
     * Get the latest report of one machine by its guid.
     *
     * @param  string $guid Machine identifier
     * @return array
     * @throws RestException 404 Unknown machine
     */
    public function getMachine($guid)
    {
        $report = new SysteminfoReport($this->db);
        $result = $report->fetchLatest($guid);
        if ($result < 0) {
            throw new RestException(500, 'Erreur base de donnees');
        }
        if ($result == 0) {
            throw new RestException(404, 'Aucun rapport pour guid=' . $guid);
        }

        $row = (object) array(
            'rowid' => $report->id,
            'guid' => $report->guid,
            'fk_soc' => $report->fk_soc,
            'hostname' => $report->hostname,
            'os' => $report->os,
            'report' => $report->report,
            'date_creation' => $report->date_creation,
        );
        return $this->_formatRow($row);
    }

    /**
     * Refuse non-HTTPS requests unless explicitly disabled by the admin
     * via the SYSTEMINFO_ALLOW_HTTP constant (module settings page).
     *
     * @return void
     * @throws RestException 426 Upgrade Required
     */
    private function _checkHttps()
    {
        if (getDolGlobalString('SYSTEMINFO_ALLOW_HTTP')) {
            return;
        }
        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == 'off') {
            throw new RestException(
                426,
                'HTTPS requis : activez HTTPS sur le serveur, ou definissez '
                . 'SYSTEMINFO_ALLOW_HTTP=1 pour desactiver ce controle (non recommande).'
            );
        }
    }

    /**
     * Format a report row for API output, with each metric as a field.
     */
    private function _formatRow($obj)
    {
        $row = array(
            'guid' => $obj->guid,
            'thirdparty_id' => $obj->fk_soc,
            'hostname' => $obj->hostname,
            'os' => $obj->os,
            'date_creation' => $obj->date_creation,
            'report' => json_decode($obj->report, true),
        );
        foreach (SysteminfoReport::$columns as $col => $type) {
            if ($col == 'fk_soc') {
                continue;
            }
            if (isset($obj->$col)) {
                $row[$col] = $obj->$col;
            }
        }
        return $row;
    }
}
