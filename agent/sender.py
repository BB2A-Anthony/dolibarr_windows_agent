import json
import logging
import sys
import uuid

import requests

from agent.collector import collect_system_info

logger = logging.getLogger("agent.sender")

DOLIBARR_STATUS_PATH = "/status"
DOLIBARR_API_ROOT = "/api/index.php"
ENDPOINT_PATH = "/systeminfo/machine"


def load_config(path):
    with open(path, "r", encoding="utf-8") as f:
        config = json.load(f)
    config["unique_id"] = config.get("unique_id") or _machine_id()
    return config


def auth_headers(config):
    """Return Dolibarr-style auth headers.

    Dolibarr REST API expects the user's API key in the DOLIBARR_API_KEY
    header (not Bearer tokens).
    """
    headers = {"Content-Type": "application/json", "Accept": "application/json"}
    api_key = config.get("api_key")
    if api_key:
        headers["DOLIBARR_API_KEY"] = api_key
    return headers


def dolibarr_url(config, path):
    """Build a full Dolibarr API URL from base URL and endpoint path.

    api_url can be either a complete URL (legacy/custom endpoint) or a
    Dolibarr base URL (e.g. https://dolibarr.example.com), in which case
    the endpoint is appended under /api/index.php/.
    """
    api_url = config["api_url"].rstrip("/")
    if DOLIBARR_API_ROOT in api_url:
        return api_url + path
    return api_url + DOLIBARR_API_ROOT + path


def test_connection(config):
    """Ping the Dolibarr status endpoint. Returns (ok, message)."""
    try:
        resp = requests.get(
            dolibarr_url(config, DOLIBARR_STATUS_PATH),
            headers=auth_headers(config),
            timeout=config.get("timeout_seconds", 30),
            verify=config.get("verify_ssl", True),
        )
        if resp.status_code == 200:
            return True, "Connexion réussie : API Dolibarr accessible."
        if resp.status_code in (401, 403):
            return False, "Authentification refusée : vérifiez la clé API."
        return False, "HTTP {}: {}".format(resp.status_code, resp.reason)
    except requests.exceptions.SSLError:
        return False, "Erreur de certificat SSL."
    except requests.exceptions.ConnectionError:
        return False, "Impossible de joindre le serveur (URL incorrecte ?)."
    except requests.exceptions.Timeout:
        return False, "Délai d'attente dépassé."


def send_report(config):
    """Collect system info and POST it to the Dolibarr API."""
    payload = collect_system_info(config)
    url = dolibarr_url(config, ENDPOINT_PATH)
    resp = requests.post(
        url,
        json=payload,
        headers=auth_headers(config),
        timeout=config.get("timeout_seconds", 30),
        verify=config.get("verify_ssl", True),
    )
    if resp.status_code == 404:
        logger.warning(
            "Endpoint %s introuvable : le module Dolibarr 'systeminfo' "
            "doit être installé côté serveur.", url
        )
    resp.raise_for_status()
    logger.info("Report sent successfully (status %s)", resp.status_code)
    return payload


def _machine_id():
    try:
        import winreg

        with winreg.OpenKey(
            winreg.HKEY_LOCAL_MACHINE,
            "SOFTWARE\\Microsoft\\Cryptography",
            0,
            winreg.KEY_READ | winreg.KEY_WOW64_64KEY,
        ) as key:
            return winreg.QueryValueEx(key, "MachineGuid")[0]
    except Exception:
        return str(uuid.uuid4())


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO)
    config = load_config(sys.argv[1] if len(sys.argv) > 1 else "config.json")
    print(json.dumps(send_report(config), indent=2, default=str))
