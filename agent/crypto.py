"""Encryption helpers for storing the API key at rest.

On Windows the key is encrypted with DPAPI (CryptProtectData), which is
bound to the machine and the Windows account running the agent. The
config.json only ever stores "dpapi:<base64 blob>", never the plaintext.

Off Windows (dev/testing) encryption is not available and the plaintext
key is used as-is.
"""
import base64
import json
import logging
import os

logger = logging.getLogger("agent.crypto")

_PREFIX = "dpapi:"


def encrypt(value):
    """Encrypt a secret with DPAPI. Returns 'dpapi:<base64>' or None."""
    if os.name != "nt":
        return None
    try:
        import ctypes
        from ctypes import wintypes

        class DATA_BLOB(ctypes.Structure):
            _fields_ = [
                ("cbData", wintypes.DWORD),
                ("pbData", ctypes.POINTER(ctypes.c_char)),
            ]

        def _blob(data):
            buf = ctypes.create_string_buffer(data, len(data))
            return DATA_BLOB(len(data), ctypes.cast(buf, ctypes.POINTER(ctypes.c_char)))

        in_blob = _blob(value.encode("utf-8"))
        out_blob = DATA_BLOB()
        if not ctypes.windll.crypt32.CryptProtectData(
            ctypes.byref(in_blob), None, None, None, None, 0, ctypes.byref(out_blob)
        ):
            return None
        try:
            encrypted = ctypes.string_at(out_blob.pbData, out_blob.cbData)
        finally:
            ctypes.windll.kernel32.LocalFree(out_blob.pbData)
        return _PREFIX + base64.b64encode(encrypted).decode("ascii")
    except Exception:
        logger.exception("DPAPI encryption failed")
        return None


def decrypt(stored):
    """Decrypt a 'dpapi:<base64>' value back to plaintext, or None."""
    if not stored or not stored.startswith(_PREFIX):
        return None
    try:
        import ctypes
        from ctypes import wintypes

        class DATA_BLOB(ctypes.Structure):
            _fields_ = [
                ("cbData", wintypes.DWORD),
                ("pbData", ctypes.POINTER(ctypes.c_char)),
            ]

        def _blob(data):
            buf = ctypes.create_string_buffer(data, len(data))
            return DATA_BLOB(len(data), ctypes.cast(buf, ctypes.POINTER(ctypes.c_char)))

        encrypted = base64.b64decode(stored[len(_PREFIX):].encode("ascii"))
        in_blob = _blob(encrypted)
        out_blob = DATA_BLOB()
        if not ctypes.windll.crypt32.CryptUnprotectData(
            ctypes.byref(in_blob), None, None, None, None, 0, ctypes.byref(out_blob)
        ):
            return None
        try:
            return ctypes.string_at(out_blob.pbData, out_blob.cbData).decode("utf-8")
        finally:
            ctypes.windll.kernel32.LocalFree(out_blob.pbData)
    except Exception:
        logger.exception("DPAPI decryption failed")
        return None


def migrate_config(config, path):
    """Encrypt a plaintext api_key and remove it from the file.

    Called after load_config: if config contains a plaintext api_key and
    encryption is available, replace it with api_key_encrypted on disk.
    """
    plaintext = config.get("api_key")
    if not plaintext:
        return config
    encrypted = encrypt(plaintext)
    if not encrypted:
        return config
    config["api_key_encrypted"] = encrypted
    config.pop("api_key", None)
    try:
        with open(path, "r", encoding="utf-8") as f:
            on_disk = json.load(f)
        on_disk["api_key_encrypted"] = encrypted
        on_disk.pop("api_key", None)
        with open(path, "w", encoding="utf-8") as f:
            json.dump(on_disk, f, indent=4)
        logger.info("Cle API migree: stockage chiffre (DPAPI), Clair supprime")
    except OSError:
        logger.warning("Cle API chiffree en memoire mais non persistee")
    return config
