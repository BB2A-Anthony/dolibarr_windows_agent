"""Auto-update of the agent binary.

The agent periodically asks the Dolibarr module for its configuration
(GET /api/index.php/systeminfo/config, authenticated with the API key),
which exposes the latest published agent version
(SYSTEMINFO_AGENT_WINDOWS_VERSION, defined in the module descriptor).
When the server exposes a newer version, the agent downloads the
package, verifies its SHA-256 checksum, and replaces itself via a
detached helper script.

The packaged binary must follow the build layout (PyInstaller --onefile):

  DolibarrAgent.zip
  +- DolibarrAgent.exe
  +- DolibarrAgentTray.exe

Security: the download URL and the SHA-256 checksum both come from the
Dolibarr server over the same TLS channel; the checksum is mandatory and
the downloaded archive is rejected on mismatch. A failed update never
breaks the running agent: staging happens in a temp dir and the swap is
done by the helper script only after a successful verification.
"""

import hashlib
import json
import logging
import os
import shutil
import subprocess
import tempfile
import zipfile

import sys

import requests

from agent import AGENT_VERSION

logger = logging.getLogger("agent.updater")

UPDATE_PATH = "/systeminfo/config"


def _update_url(config):
    from agent.sender import dolibarr_url

    return dolibarr_url(config, UPDATE_PATH)


def check_update(config):
    """Ask the server (API getConfig) if a newer version exists.

    Returns (update_available, info) where info contains 'version' and,
    if the admin configured a package, 'download_url' and 'sha256'.
    """
    from agent.sender import auth_headers

    try:
        resp = requests.get(
            _update_url(config),
            headers=auth_headers(config),
            timeout=config.get("timeout_seconds", 30),
            verify=config.get("verify_ssl", True),
        )
    except requests.exceptions.RequestException as exc:
        logger.warning("Verification de mise a jour impossible : %s", exc)
        return False, {}

    if resp.status_code != 200:
        logger.info(
            "Pas de mise a jour disponible (HTTP %s)", resp.status_code
        )
        return False, {}

    try:
        info = resp.json()
    except ValueError:
        logger.warning("Reponse de mise a jour invalide (JSON illisible)")
        return False, {}

    version = info.get("agent_windows_version")
    if not version:
        return False, info
    if _version_tuple(version) <= _version_tuple(AGENT_VERSION):
        return False, info
    # Security: the update package must be served by the configured
    # Dolibarr server (same host), never an arbitrary third-party URL.
    from agent.sender import dolibarr_url

    base = dolibarr_url(config, "").split("/api/index.php")[0]
    download_url = info.get("download_url") or ""
    if download_url and not download_url.startswith(base):
        logger.warning(
            "Mise a jour ignoree : download_url (%s) hors du serveur Dolibarr (%s)",
            download_url,
            base,
        )
        return False, info
    return True, info


def _version_tuple(version):
    parts = []
    for chunk in str(version).split("."):
        digits = "".join(ch for ch in chunk if ch.isdigit())
        parts.append(int(digits) if digits else 0)
    while len(parts) < 3:
        parts.append(0)
    return tuple(parts)


def download_update(config, info):
    """Download the update package to a temp dir and verify its checksum.

    Returns the local path of the verified zip, or None on failure.
    """
    download_url = info.get("download_url")
    expected_sha256 = str(info.get("sha256") or "").lower()
    if not download_url:
        logger.warning(
            "Mise a jour %s disponible mais aucun download_url configure : "
            "l'agent ne se met pas a jour automatiquement",
            info.get("agent_windows_version"),
        )
        return None
    if not expected_sha256:
        logger.warning(
            "Mise a jour %s ignoree : sha256 manquant", info.get("agent_windows_version")
        )
        return None

    # Force TLS unless the admin explicitly disabled verification.
    verify_ssl = config.get("verify_ssl", True)
    if not verify_ssl and download_url.startswith("http://"):
        logger.warning(
            "Telechargement de la mise a jour en HTTP non chiffre "
            "(verify_ssl=false) : risque d'interception."
        )

    try:
        resp = requests.get(
            download_url,
            stream=True,
            timeout=config.get("timeout_seconds", 30) * 2,
            verify=verify_ssl,
        )
        resp.raise_for_status()
    except requests.exceptions.RequestException as exc:
        logger.error("Telechargement de la mise a jour echoue : %s", exc)
        return None

    tmp_dir = tempfile.mkdtemp(prefix="dolibarr_agent_update_")
    zip_path = os.path.join(tmp_dir, "update.zip")
    hasher = hashlib.sha256()
    try:
        with open(zip_path, "wb") as f:
            for chunk in resp.iter_content(chunk_size=65536):
                if chunk:
                    f.write(chunk)
                    hasher.update(chunk)
    except OSError as exc:
        logger.error("Ecriture de la mise a jour echouee : %s", exc)
        shutil.rmtree(tmp_dir, ignore_errors=True)
        return None

    actual = hasher.hexdigest()
    if actual != expected_sha256:
        logger.error(
            "Checksum de la mise a jour invalide (attendu %s, obtenu %s) : "
            "paquet rejete",
            expected_sha256,
            actual,
        )
        shutil.rmtree(tmp_dir, ignore_errors=True)
        return None

    logger.info(
        "Mise a jour %s telechargee et verifiee (sha256 ok)",
        info.get("agent_windows_version"),
    )
    return zip_path


def apply_update(zip_path, restart=True):
    """Extract the package and spawn a detached helper that swaps files.

    The helper waits for the current process (and the service) to exit,
    replaces the executables, then optionally restarts the service.
    Returns True if the helper was launched.
    """
    extract_dir = os.path.join(os.path.dirname(zip_path), "extracted")
    try:
        with zipfile.ZipFile(zip_path) as zf:
            zf.extractall(extract_dir)
    except (zipfile.BadZipFile, OSError) as exc:
        logger.error("Extraction de la mise a jour echouee : %s", exc)
        return False

    install_dir = _install_dir()
    helper_path = os.path.join(os.path.dirname(zip_path), "apply_update.cmd")
    with open(helper_path, "w", encoding="utf-8") as f:
        f.write(_helper_script(install_dir, extract_dir, restart))
    logger.info(
        "Mise a jour : installation dans %s depuis %s", install_dir, extract_dir
    )

    creationflags = 0
    try:
        creationflags = subprocess.DETACHED_PROCESS | subprocess.CREATE_NEW_PROCESS_GROUP
    except AttributeError:
        creationflags = 0
    try:
        subprocess.Popen(
            ["cmd", "/c", helper_path],
            creationflags=creationflags,
            close_fds=True,
            cwd=os.path.dirname(helper_path),
        )
    except OSError as exc:
        logger.error("Lancement du script de mise a jour echoue : %s", exc)
        return False

    logger.info("Mise a jour planifiee : elle s'appliquera a l'arret de l'agent")
    return True


def _install_dir():
    """Directory where the running executable (or script) lives."""
    return os.path.dirname(os.path.abspath(sys.argv[0]))


def _helper_script(install_dir, extract_dir, restart):
    """Build the cmd helper that waits and swaps the executables."""
    lines = [
        "@echo off",
        "setlocal",
        "set INSTALL_DIR={}".format(install_dir),
        "set EXTRACT_DIR={}".format(extract_dir),
        "rem Wait up to 120 s for the running executables to unlock.",
        "set /a tries=0",
        ":waitloop",
        "set /a tries+=1",
        "if %tries% gtr 60 goto end",
        "copy /y \"%EXTRACT_DIR%\\DolibarrAgent.exe\" \"%INSTALL_DIR%\\DolibarrAgent.exe.new\" >nul 2>&1",
        "if errorlevel 1 (",
        "    timeout /t 2 /nobreak >nul",
        "    goto waitloop",
        ")",
        "del \"%INSTALL_DIR%\\DolibarrAgent.exe.new\" >nul 2>&1",
        "goto replace",
        ":replace",
    ]
    for name in ("DolibarrAgent", "DolibarrAgentTray"):
        lines.append(
            "copy /y \"%EXTRACT_DIR%\\{n}.exe\" \"{d}\\{n}.exe\" >nul 2>&1".format(
                n=name, d=install_dir
            )
        )
    if restart:
        lines.append("net stop DolibarrAgent >nul 2>&1")
        lines.append("net start DolibarrAgent >nul 2>&1")
    lines.append("rmdir /s /q \"%EXTRACT_DIR%\" >nul 2>&1")
    lines.append(":end")
    lines.append("endlocal")
    lines.append("exit")
    return "\r\n".join(lines) + "\r\n"


def maybe_update_and_restart(config):
    """Full update cycle: check, download, apply. Returns True when the
    update was scheduled (the caller should then exit)."""
    available, info = check_update(config)
    if not available:
        return False
    zip_path = download_update(config, info)
    if not zip_path:
        return False
    return apply_update(zip_path, restart=True)


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO)
    with open("config.json", "r", encoding="utf-8") as f:
        cfg = json.load(f)
    if maybe_update_and_restart(cfg):
        print("Mise a jour planifiee.")
    else:
        print("Aucune mise a jour appliquee.")
