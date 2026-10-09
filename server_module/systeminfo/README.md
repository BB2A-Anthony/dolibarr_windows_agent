# Module Dolibarr « systeminfo »

Ce module expose l'endpoint REST qui reçoit les informations systèmes de l'agent Windows :

```
POST /api/index.php/systeminfo/machine
```

## Installation

1. Copier le dossier `systeminfo` dans `htdocs/custom/` de votre instance Dolibarr.
2. Activer le module : **Accueil > Configuration > Modules/Boxes > Modules externes** (activer « SYSTEMINFO »).
3. Vérifier que l'API est active : **Configuration > API** (module REST activé), et qu'un utilisateur possède une **clé API** (fiche utilisateur > « Interface API » > générer une clé).
4. Tester :

```bash
curl -X POST https://votre-dolibarr/api/index.php/systeminfo/machine \
  -H "DOLIBARR_API_KEY: <cle>" -H "Content-Type: application/json" \
  -d '{"unique_id":"TIERS-001","hostname":"PC-TEST"}'
```

## Fonctionnement

- L'agent envoie un JSON avec un champ `unique_id` qui identifie la machine.
- Le module cherche un tiers dont le **code client** (`code_client`) correspond à `unique_id`.
- S'il existe, un résumé du rapport (hostname, OS, mémoire, CPU) est ajouté à la **note privée** du tiers ; sinon l'événement est journalisé dans `dolibarr_main.log`.

## Personnalisation

Le point d'entrée est `class/api_systeminfo.class.php` (méthode `postMachine`). Adaptez-y le stockage selon vos besoins : table dédiée, extrafields du tiers, création automatique de tiers, etc.
