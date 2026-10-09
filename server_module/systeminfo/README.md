# Module Dolibarr « systeminfo »

Ce module reçoit les informations systèmes de l'agent Windows et permet de les **interroger depuis Dolibarr** (API REST + fiche tiers).

## Endpoints

| Méthode | URL | Rôle |
|---|---|---|
| `POST` | `/api/index.php/systeminfo/machine` | L'agent y envoie son rapport (authentifié par `DOLIBARR_API_KEY`) |
| `GET` | `/api/index.php/systeminfo/machine` | Dernier rapport de **chaque machine** connue |
| `GET` | `/api/index.php/systeminfo/machine/{guid}` | Dernier rapport d'**une machine** par identifiant unique |

## Installation

1. Copier le dossier `systeminfo` dans `htdocs/custom/` de votre instance Dolibarr.
2. Créer la table en exécutant `sql/llx_systeminfo_reports.sql` (via phpMyAdmin ou l'outil SQL de Dolibarr).
3. Activer le module : **Accueil > Configuration > Modules/Boxes > Modules externes** (activer « SYSTEMINFO »).
4. Vérifier que l'API est active (**Configuration > API**, module REST) et qu'un utilisateur possède une **clé API** (fiche utilisateur > « Interface API »).

## Interroger depuis Dolibarr

Dernier rapport d'une machine :

```bash
curl -H "DOLIBARR_API_KEY: <cle>" \
  https://votre-dolibarr/api/index.php/systeminfo/machine/TIERS-001
```

Tous les derniers rapports :

```bash
curl -H "DOLIBARR_API_KEY: <cle>" \
  https://votre-dolibarr/api/index.php/systeminfo/machine
```

Exemple de réponse :

```json
{
    "guid": "TIERS-001",
    "thirdparty_id": 42,
    "hostname": "PC-ATLAS",
    "os": "Windows 10 Pro",
    "date_creation": 1710000000,
    "report": { "memory": {"total_bytes": 17179869184, "...": "..."} }
}
```

Ces endpoints sont également visibles et testables dans l'**API Explorer** de Dolibarr (`/api/index.php/explorer`).

### Fiche tiers

Un hook affiche automatiquement un bloc « Informations systeme (agent Windows) » sur la fiche du tiers : date du dernier rapport, hostname, OS, mémoire, CPU. La correspondance se fait entre `guid` de l'agent et le **code client** du tiers.

## Stockage

**Une seule ligne par machine** dans la table `llx_systeminfo_reports` : à chaque envoi (30 s après le démarrage puis toutes les 4 h), le module met à jour l'enregistrement existant de la machine (`guid`) — `fk_soc`, hostname, OS, rapport JSON et date — ou le crée s'il s'agit du premier rapport. La colonne `guid` est `UNIQUE` pour garantir l'unicité.
