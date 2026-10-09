<?php
/**
 * REST API endpoint for the systeminfo module (Dolibarr API Explorer).
 *
 * POST /api/index.php/systeminfo/machine
 */
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

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
     * Receive system info from the Windows agent.
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

        $thirdparty = new Societe($this->db);
        $result = $thirdparty->fetch('', '', $payload['unique_id']);

        $hostname = isset($payload['hostname']) ? $payload['hostname'] : '';
        $os = isset($payload['os_details']['ProductName'])
            ? $payload['os_details']['ProductName'] : '';

        if ($result > 0 && $thirdparty->id > 0) {
            $note = "Rapport agent du " . dol_print_date(dol_now(), 'dayhour') . "\n";
            $note .= "Hostname: " . $hostname . "\n";
            $note .= "OS: " . $os . "\n";
            if (isset($payload['memory']['total_bytes'])) {
                $note .= "Memoire totale: " . round($payload['memory']['total_bytes'] / 1073741824, 1) . " Go\n";
            }
            if (isset($payload['cpu']['percent_used'])) {
                $note .= "CPU utilise: " . $payload['cpu']['percent_used'] . " %\n";
            }

            $thirdparty->note_private = ($thirdparty->note_private ? $thirdparty->note_private . "\n" : '') . $note;
            $thirdparty->update($thirdparty->id, $user);

            dol_syslog(
                'systeminfo: rapport enregistre pour le tiers #' . $thirdparty->id,
                LOG_INFO
            );
            return array(
                'success' => true,
                'thirdparty_id' => $thirdparty->id,
            );
        }

        dol_syslog(
            'systeminfo: aucun tiers trouve pour unique_id=' . $payload['unique_id'],
            LOG_WARNING
        );
        return array(
            'success' => true,
            'thirdparty_id' => null,
            'message' => 'Aucun tiers correspondant; rapport journalise.',
        );
    }
}
