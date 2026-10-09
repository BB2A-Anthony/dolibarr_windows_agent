import json
import logging
import sys
import uuid

import requests

from agent.collector import collect_system_info

logger = logging.getLogger("agent.sender")


def load_config(path):
    with open(path, "r", encoding="utf-8") as f:
        config = json.load(f)
    config["unique_id"] = config.get("unique_id") or _machine_id()
    return config


def send_report(config):
    payload = collect_system_info(config)
    headers = {"Content-Type": "application/json"}
    if config.get("api_key"):
        headers["Authorization"] = "Bearer {}".format(config["api_key"])

    resp = requests.post(
        config["api_url"],
        json=payload,
        headers=headers,
        timeout=config.get("timeout_seconds", 30),
        verify=config.get("verify_ssl", True),
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
