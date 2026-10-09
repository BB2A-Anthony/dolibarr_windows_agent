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
     * @throws RestException 400 Missing unique_id
     */
    public function postMachine($payload)
    {
        global $user;

        if (empty($payload['unique_id'])) {
            throw new RestException(400, 'Champ unique_id manquant');
        }

        $fk_soc = null;
        if (!empty($payload['fk_soc'])) {
            $fk_soc = (int) $payload['fk_soc'];
            $thirdparty = new Societe($this->db);
            if ($thirdparty->fetch($fk_soc) <= 0) {
                throw new RestException(404, 'fk_soc=' . $fk_soc . ' : tiers introuvable');
            }
        }

        $hostname = isset($payload['hostname']) ? $payload['hostname'] : '';
        $os = isset($payload['os_details']['ProductName'])
            ? $payload['os_details']['ProductName'] : '';

        $report = new SysteminfoReport($this->db);
        $report->unique_id = $payload['unique_id'];
        $report->fk_soc = $fk_soc;
        $report->hostname = $hostname;
        $report->os = $os;
        $payload['softwares'] = $this->_matchSoftwares(
            isset($payload['installed_softwares']) ? $payload['installed_softwares'] : array()
        );
        $report->report = json_encode($payload);
        $id = $report->save($user);

        if ($id < 0) {
            throw new RestException(500, 'Erreur lors de l\'enregistrement du rapport');
        }

        dol_syslog(
            'systeminfo: rapport enregistre (rowid=' . $id . ')'
            . ($fk_soc ? ' pour le tiers #' . $fk_soc : ' sans tiers'),
            LOG_INFO
        );

        return array(
            'success' => true,
            'report_id' => $id,
            'thirdparty_id' => $fk_soc,
            'softwares' => $payload['softwares'],
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
     * @return array
     */
    public function indexMachine()
    {
        $report = new SysteminfoReport($this->db);
        $rows = $report->listLatestPerMachine();
        $out = array();
        foreach ($rows as $obj) {
            $out[] = $this->_formatRow($obj);
        }
        return $out;
    }

    /**
     * Get the latest report of one machine by its unique_id.
     *
     * @param  string $unique_id Machine identifier
     * @return array
     * @throws RestException 404 Unknown machine
     */
    public function getMachine($unique_id)
    {
        $report = new SysteminfoReport($this->db);
        $result = $report->fetchLatest($unique_id);
        if ($result < 0) {
            throw new RestException(500, 'Erreur base de donnees');
        }
        if ($result == 0) {
            throw new RestException(404, 'Aucun rapport pour unique_id=' . $unique_id);
        }

        $row = (object) array(
            'rowid' => $report->id,
            'unique_id' => $report->unique_id,
            'fk_soc' => $report->fk_soc,
            'hostname' => $report->hostname,
            'os' => $report->os,
            'report' => $report->report,
            'date_creation' => $report->date_creation,
        );
        return $this->_formatRow($row);
    }

    /**
     * Format a report row for API output.
     */
    private function _formatRow($obj)
    {
        return array(
            'unique_id' => $obj->unique_id,
            'thirdparty_id' => $obj->fk_soc,
            'hostname' => $obj->hostname,
            'os' => $obj->os,
            'date_creation' => $obj->date_creation,
            'report' => json_decode($obj->report, true),
        );
    }
}
