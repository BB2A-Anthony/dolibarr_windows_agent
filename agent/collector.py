import platform
import socket
import uuid

import psutil


def collect_system_info(config):
    """Collect Windows system information to report to the API."""
    info = {
        "unique_id": config["unique_id"],
        "hostname": socket.gethostname(),
        "fqdn": socket.getfqdn(),
        "platform": platform.system(),
        "platform_release": platform.release(),
        "platform_version": platform.version(),
        "architecture": platform.machine(),
        "processor": platform.processor(),
        "python_version": platform.python_version(),
    }

    try:
        import winreg
    except ImportError:
        winreg = None

    if winreg is not None:
        info["os_details"] = _read_windows_details(winreg)
        info["windows_edition"] = _windows_edition(winreg)

    mem = psutil.virtual_memory()
    info["memory"] = {
        "total_bytes": mem.total,
        "available_bytes": mem.available,
        "used_bytes": mem.used,
        "percent_used": mem.percent,
    }

    disks = []
    for part in psutil.disk_partitions(all=False):
        try:
            usage = psutil.disk_usage(part.mountpoint)
        except (PermissionError, OSError):
            continue
        disks.append(
            {
                "device": part.device,
                "mountpoint": part.mountpoint,
                "fstype": part.fstype,
                "total_bytes": usage.total,
                "used_bytes": usage.used,
                "free_bytes": usage.free,
                "percent_used": usage.percent,
            }
        )
    info["disks"] = disks

    nics = []
    for name, addrs in psutil.net_if_addrs().items():
        addresses = [
            {"family": str(a.family), "address": a.address, "netmask": a.netmask}
            for a in addrs
            if a.family in (socket.AF_INET, socket.AF_INET6)
        ]
        if addresses:
            nics.append({"name": name, "addresses": addresses})
    info["network_interfaces"] = nics

    boot_time = psutil.boot_time()
    info["boot_time"] = boot_time
    info["uptime_seconds"] = psutil.time.time() - boot_time

    info["cpu"] = {
        "count_logical": psutil.cpu_count(logical=True),
        "count_physical": psutil.cpu_count(logical=False),
        "percent_used": psutil.cpu_percent(interval=1),
    }

    info["mac_addresses"] = _mac_addresses()

    if winreg is not None:
        info["firewall"] = _firewall_status(winreg)
        info["updates"] = _update_status()

    return info


def _update_status():
    """Query Windows Update status via the COM API."""
    status = {"available": False}
    try:
        import pythoncom
        import win32com.client
    except ImportError:
        return status

    try:
        pythoncom.CoInitialize()
        session = win32com.client.Dispatch("Microsoft.Update.Session")
        searcher = session.CreateUpdateSearcher()

        status["available"] = True

        total = searcher.GetTotalHistoryCount()
        if total > 0:
            entries = searcher.QueryHistory(0, 1)
            if entries.Count > 0:
                last = entries.Item(0)
                status["last_update"] = {
                    "title": last.Title,
                    "date": str(last.Date),
                    "result_code": last.ResultCode,
                }
        status["history_count"] = total

        result = searcher.Search("IsInstalled=0 and IsHidden=0")
        status["pending_count"] = result.Updates.Count
        pending = []
        for i in range(min(result.Updates.Count, 10)):
            pending.append(result.Updates.Item(i).Title)
        status["pending_updates"] = pending

        return status
    except Exception:
        return status
    finally:
        try:
            pythoncom.CoUninitialize()
        except Exception:
            pass


def _firewall_status(winreg):
    """Read Windows Firewall state for each profile from the registry."""
    profiles = {}
    for profile in ("DomainProfile", "StandardProfile", "PublicProfile"):
        key_path = (
            "SYSTEM\\CurrentControlSet\\Services\\SharedAccess"
            "\\Parameters\\FirewallPolicy\\" + profile
        )
        enabled = None
        try:
            with winreg.OpenKey(
                winreg.HKEY_LOCAL_MACHINE, key_path, 0, winreg.KEY_READ | winreg.KEY_WOW64_64KEY
            ) as key:
                enabled = bool(winreg.QueryValueEx(key, "EnableFirewall")[0])
        except OSError:
            continue
        if enabled is not None:
            profiles[profile] = enabled

    if not profiles:
        return {"available": False}
    return {
        "available": True,
        "enabled": all(profiles.values()),
        "profiles": profiles,
    }


def _read_windows_details(winreg):
    try:
        with winreg.OpenKey(
            winreg.HKEY_LOCAL_MACHINE,
            "SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion",
            0,
            winreg.KEY_READ | winreg.KEY_WOW64_64KEY,
        ) as key:
            details = {}
            for value_name in (
                "ProductName",
                "EditionID",
                "ReleaseId",
                "Build",
                "CurrentBuild",
                "UBR",
                "DisplayVersion",
            ):
                try:
                    details[value_name] = winreg.QueryValueEx(key, value_name)[0]
                except OSError:
                    continue
            return details
    except OSError:
        return {}


def _windows_edition(winreg):
    return _read_windows_details(winreg).get("ProductName", platform.platform())


def _mac_addresses():
    macs = []
    for name, addrs in psutil.net_if_addrs().items():
        for addr in addrs:
            if addr.family == getattr(psutil, "AF_LINK", None):
                if addr.address and addr.address not in macs:
                    macs.append(addr.address)
    return macs
