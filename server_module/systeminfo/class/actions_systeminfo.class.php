<?php
/**
 * Hooks: display the machine's latest system report on the thirdparty card.
 */
require_once __DIR__ . '/systeminfo_report.class.php';

class ActionsSysteminfo
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var array Hook results
     */
    public $resprints = '';

    public $results = array();

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Add a system info block on the thirdparty card.
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
        if (empty($societe->code_client)) {
            return 0;
        }

        $report = new SysteminfoReport($this->db);
        $result = $report->fetchLatest($societe->code_client);
        if ($result != 1) {
            return 0;
        }

        $data = json_decode($report->report, true);
        $mem = isset($data['memory']['total_bytes'])
            ? round($data['memory']['total_bytes'] / 1073741824, 1) . ' Go' : '';
        $cpu = isset($data['cpu']['count_logical'])
            ? $data['cpu']['count_logical'] . ' coeurs' : '';

        $out = '<!-- Systeminfo -->';
        $out .= '<div class="fichecenter"><div class="boxe">';
        $out .= '<table class="border centpercent">';
        $out .= '<tr class="liste_titre"><th colspan="2">Informations systeme (agent Windows)</th></tr>';
        $out .= '<tr><td>Dernier rapport</td><td>' . dol_print_date($report->date_creation, 'dayhour') . '</td></tr>';
        if ($report->hostname) {
            $out .= '<tr><td>Hostname</td><td>' . dol_escape_htmltag($report->hostname) . '</td></tr>';
        }
        if ($report->os) {
            $out .= '<tr><td>OS</td><td>' . dol_escape_htmltag($report->os) . '</td></tr>';
        }
        if ($mem) {
            $out .= '<tr><td>Memoire</td><td>' . dol_escape_htmltag($mem) . '</td></tr>';
        }
        if ($cpu) {
            $out .= '<tr><td>CPU</td><td>' . dol_escape_htmltag($cpu) . '</td></tr>';
        }
        $out .= '</table></div></div>';

        $this->resprints = $out;
        return 0;
    }
}
