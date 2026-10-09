# Dolibarr Windows Agent

Service Windows qui collecte les informations systèmes de la machine et les envoie périodiquement à une API (par exemple un module Dolibarr) via HTTP POST (JSON). Chaque machine est identifiée par un `unique_id` configurable, ce qui permet de rattacher les informations au bon tiers.

## Informations collectées

- Hostname, FQDN, nom et version de Windows (via registre), architecture, processeur
- CPU (cœurs logiques/physiques, utilisation)
- Mémoire (totale, disponible, utilisée, %)
- Disques (par partition : total, utilisé, libre, %)
- Interfaces réseau (adresses IPv4/IPv6) et adresses MAC
- Temps de démarrage et uptime

## Configuration (`config.json`)

```json
{
    "api_url": "https://example.com/api/systeminfo",
    "unique_id": "REPLACE-WITH-UNIQUE-ID",
    "api_key": "",
    "initial_delay_seconds": 30,
    "poll_interval_seconds": 14400,
    "timeout_seconds": 30,
    "verify_ssl": true
}
```

| Clé | Rôle |
|---|---|
| `api_url` | URL de l'API qui reçoit les informations |
| `fk_soc` | ID du tiers Dolibarr (fk_soc) auquel rattacher la machine — à renseigner dans l'agent |
| `unique_id` | Identifiant de la machine, généré automatiquement (MachineGuid Windows) — ne se configure normalement pas |
| `api_key` | Clé API Dolibarr de l'utilisateur, envoyée en en-tête `DOLIBARR_API_KEY` (fiche utilisateur Dolibarr > onglet « Interface API » > générer une clé) |
| `initial_delay_seconds` | Délai avant le premier envoi après démarrage (par défaut 30 s) |
| `poll_interval_seconds` | Intervalle entre deux envois (par défaut 14400 s = 4 h) |
| `timeout_seconds` | Timeout HTTP (par défaut 30 s) |
| `verify_ssl` | Vérification du certificat TLS (par défaut true) |

## Payload envoyé

```json
{
    "unique_id": "TIERS-001",
    "hostname": "PC-ATLAS",
    "fqdn": "pc-atlas.local",
    "platform": "Windows",
    "platform_release": "10",
    "platform_version": "10.0.19045",
    "architecture": "AMD64",
    "processor": "...",
    "os_details": {"ProductName": "Windows 10 Pro", "CurrentBuild": "19045", "...": "..."},
    "memory": {"total_bytes": 17179869184, "available_bytes": 8589934592, "used_bytes": 8589934592, "percent_used": 50.0},
    "disks": [{"device": "C:\\", "mountpoint": "C:\\", "total_bytes": 0, "used_bytes": 0, "free_bytes": 0, "percent_used": 0.0}],
    "network_interfaces": [{"name": "Ethernet", "addresses": [{"family": "2", "address": "192.168.1.10", "netmask": "255.255.255.0"}]}],
    "mac_addresses": ["00-11-22-33-44-55"],
    "boot_time": 1699000000.0,
    "uptime_seconds": 3600,
    "cpu": {"count_logical": 8, "count_physical": 4, "percent_used": 12.5}
}
```

## Utilisation en ligne de commande

```bat
REM Envoi unique
python run.py --config config.json

REM Envoi en boucle (toutes les poll_interval_seconds)
python run.py --config config.json --loop
```

## Icône barre des tâches (system tray)

Lancer `tray.py` (ou l'exécutable `DolibarrAgentTray.exe`) pour afficher une icône dans la barre des tâches :

- **Envoyer maintenant** : envoie immédiatement un rapport
- **Paramètres (URL / Clé API / Identifiant)...** : fenêtre pour modifier l'URL de l'API, la clé API Dolibarr et l'identifiant unique (enregistrés dans `config.json` et pris en compte sans redémarrage), avec bouton « Tester la connexion »
- **Tester la connexion** : vérifie que l'API Dolibarr est joignable et que la clé est acceptée
- **Quitter** : arrête l'agent et l'icône

Pour un lancement automatique au démarrage de Windows, placer un raccourci de `DolibarrAgentTray.exe` dans le dossier Démarrage (`shell:startup`).

## Installation comme service Windows

1. Installer les dépendances :

    ```bat
    pip install -r requirements.txt
    ```

2. Compiler l'exécutable (PyInstaller) :

    ```bat
    build_exe.bat
    ```

3. Placer `dist\DolibarrWindowsAgent.exe` et `config.json` dans le même dossier (ex. `C:\Agent\`), adapter `config.json` (URL de l'API + `unique_id`).

4. Installer, démarrer, arrêter, désinstaller le service (en administrateur) :

    ```bat
    DolibarrWindowsAgent.exe install
    DolibarrWindowsAgent.exe start
    DolibarrWindowsAgent.exe stop
    DolibarrWindowsAgent.exe remove
    ```

Le service journalise dans `agent_service.log` à côté de l'exécutable.

## Connexion à une instance Dolibarr

1. **Côté Dolibarr** : activer l'API (**Configuration > API**, module REST) et générer une clé API pour un utilisateur (fiche utilisateur > « Interface API »).
2. **Côté agent** : renseigner dans `config.json` (ou via l'icône > Paramètres) :
   - `api_url` : URL de base de votre Dolibarr (ex. `https://dolibarr.mondomaine.com`) — l'agent construit automatiquement les URLs `/api/index.php/...`
   - `api_key` : la clé API Dolibarr
   - `fk_soc` : l'**ID du tiers** Dolibarr à rattacher (visible dans l'URL de la fiche tiers : `socid=...`)
3. **Endpoint de réception** : installer le module fourni dans [`server_module/systeminfo`](server_module/systeminfo/README.md), qui accepte `POST /api/index.php/systeminfo/machine` et rattache les informations au tiers correspondant au `unique_id`.
4. **Tester** : bouton « Tester la connexion » dans les paramètres de l'icône (appelle `GET /api/index.php/status`).

L'authentification Dolibarr utilise l'en-tête `DOLIBARR_API_KEY` (pas de Bearer).

## Côté API (exemple de réception)

L'API doit accepter un `POST` JSON sur `api_url`. Le champ `unique_id` permet de retrouver le tiers correspondant (ex. `GET/POST` sur un module Dolibarr personnalisé). Exemple minimal en PHP :

```php
// api/systeminfo.php
$payload = json_decode(file_get_contents('php://input'), true);
$uniqueId = $payload['unique_id']; // clé de correspondance avec le tiers
// ...enregistrement en base...
http_response_code(200);
```
