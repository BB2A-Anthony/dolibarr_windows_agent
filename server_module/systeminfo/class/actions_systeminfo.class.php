<?php
/**
 * Hooks: thirdparty card — display machine report block and an
 * "Add machine" button that generates a one-time enrollment code
 * (valid 5 minutes) to be entered in the agent.
 */
require_once __DIR__ . '/systeminfo_report.class.php';
require_once __DIR__ . '/isp_logo.class.php';

class ActionsSysteminfo
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    public $resprints = '';

    public $results = array();

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Generate a one-time enrollment code for this thirdparty.
     *
     * @param  array $parameters Hook context ('socid' or 'object')
     * @return int
     */
    public function createEnrollCode($parameters)
    {
        $socid = 0;
        if (!empty($parameters['socid'])) {
            $socid = (int) $parameters['socid'];
        } elseif (!empty($parameters['object']->id)) {
            $socid = (int) $parameters['object']->id;
        }
        if (empty($socid)) {
            $this->results['error'] = 'Tiers inconnu';
            return -1;
        }

        $code = strtoupper(substr(md5(uniqid('', true) . $socid), 0, 8));
        $validity = 5 * 60;

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "systeminfo_enroll";
        $sql .= " (code, fk_soc, date_valid, used)";
        $sql .= " VALUES ('" . $this->db->escape($code) . "', " . $socid;
        $sql .= ", '" . $this->db->idate(dol_now() + $validity) . "', 0)";
        if (!$this->db->query($sql)) {
            $this->results['error'] = $this->db->lasterror();
            return -1;
        }

        $this->results['code'] = $code;
        $this->results['validity'] = $validity;
        return 1;
    }

    /**
     * Add the system info block on the thirdparty card.
     *
     * @param  array $parameters Hook context ('object' => Societe)
     * @return int
     */
    public function addMoreCards($parameters)
    {
        global $langs;

        if (empty($parameters['object']) || !is_object($parameters['object'])) {
            return 0;
        }
        $societe = $parameters['object'];
        if (empty($societe->id)) {
            return 0;
        }

        $report = new SysteminfoReport($this->db);
        $machines = $report->fetchAllBySoc($societe->id);
        if (empty($machines)) {
            return 0;
        }

        $out = '<!-- Systeminfo -->';
        $out .= '<div class="fichecenter">';
        foreach ($machines as $machine) {
            $data = json_decode($machine->report, true);
            $mem = isset($data['memory']['total_bytes'])
                ? round($data['memory']['total_bytes'] / 1073741824, 1) . ' Go' : '';
            $cpu = isset($data['cpu']['count_logical'])
                ? $data['cpu']['count_logical'] . ' coeurs' : '';

            $out .= '<div class="boxe">';
            $out .= '<table class="border centpercent">';
            $title = $machine->hostname ? $machine->hostname : $machine->guid;
            $out .= '<tr class="liste_titre"><th colspan="2">Informations systeme (agent Windows) — ' . dol_escape_htmltag($title) . '</th></tr>';
            $out .= '<tr><td>Dernier rapport</td><td>' . dol_print_date($this->db->jdate($machine->date_creation), 'dayhour') . '</td></tr>';
            if ($machine->hostname) {
                $out .= '<tr><td>Hostname</td><td>' . dol_escape_htmltag($machine->hostname) . '</td></tr>';
            }
            if ($machine->os) {
                $out .= '<tr><td>OS</td><td>' . dol_escape_htmltag($machine->os) . '</td></tr>';
            }
            if ($mem) {
                $out .= '<tr><td>Memoire</td><td>' . dol_escape_htmltag($mem) . '</td></tr>';
            }
            if ($cpu) {
                $out .= '<tr><td>CPU</td><td>' . dol_escape_htmltag($cpu) . '</td></tr>';
            }
            if (!empty($data['public_ip'])) {
                $out .= '<tr><td>IP publique</td><td>' . dol_escape_htmltag($data['public_ip']) . '</td></tr>';
            }
            $isp = isset($data['isp']) ? $data['isp'] : '';
            $ispName = SysteminfoIspLogo::cleanName($isp);
            if ($ispName) {
                $ispLogo = SysteminfoIspLogo::logoUrl($isp);
                $out .= '<tr><td>FAI</td><td><img src="' . $ispLogo . '" alt="' . dol_escape_htmltag($ispName) . '" style="vertical-align: middle; height: 18px;" /> ' . dol_escape_htmltag($ispName) . '</td></tr>';
            }
            $tv = isset($data['teamviewer']) ? $data['teamviewer'] : array();
            if (!empty($tv['installed'])) {
                $tvId = !empty($tv['id']) ? $tv['id'] : 'ID inconnu';
                $tvVersion = !empty($tv['version']) ? ' (v' . $tv['version'] . ')' : '';
                $out .= '<tr><td>TeamViewer</td><td><strong>' . dol_escape_htmltag($tvId) . '</strong>' . dol_escape_htmltag($tvVersion) . '</td></tr>';
            }
            $out .= '</table></div>';
        }
        $out .= '</div>';

        $this->resprints = $out;
        return 0;
    }
}
