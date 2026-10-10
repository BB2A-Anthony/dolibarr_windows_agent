import logging
import os
import socket
import sys
import threading
import time
import traceback

import servicemanager
import win32serviceutil

from agent.sender import load_config, send_report


class DolibarrAgentService(win32serviceutil.ServiceFramework):
    _svc_name_ = "DolibarrAgent"
    _svc_display_name_ = "Dolibarr Agent"
    _svc_description_ = (
        "Envoie périodiquement les informations systèmes de la machine "
        "vers l'API Dolibarr configurée."
    )

    def __init__(self, args):
        super().__init__(args)
        self.stop_event = threading.Event()
        logging.basicConfig(
            filename=os.path.join(os.path.dirname(sys.executable), "agent_service.log"),
            level=logging.INFO,
            format="%(asctime)s %(levelname)s: %(message)s",
        )
        self.log = logging.getLogger("service")

    def SvcStop(self):
        self.ReportServiceStatus(win32serviceutil.SERVICE_STOP_PENDING)
        self.stop_event.set()

    def SvcDoRun(self):
        servicemanager.LogMsg(
            servicemanager.EVENTLOG_INFORMATION_TYPE,
            servicemanager.PYS_SERVICE_STARTED,
            (self._svc_name_, ""),
        )
        self.main()

    def main(self):
        base_dir = os.path.dirname(os.path.abspath(sys.argv[0]))
        config_path = os.path.join(base_dir, "config.json")
        try:
            self.config = load_config(config_path)
        except Exception:
            self.log.error("Impossible de charger %s\n%s", config_path, traceback.format_exc())
            return

        initial_delay = self.config.get("initial_delay_seconds", 30)
        interval = self.config.get("poll_interval_seconds", 14400)
        if self.stop_event.wait(initial_delay):
            return
        while not self.stop_event.is_set():
            try:
                send_report(self.config)
            except Exception:
                self.log.error("Envoi échoué:\n%s", traceback.format_exc())
            if self._check_update():
                return
            self.stop_event.wait(interval)

    def _check_update(self):
        """Apply a pending agent update (auto-update). Returns True when an
        update was scheduled and the service loop must stop."""
        if not self.config.get("auto_update", True):
            return False
        try:
            from agent.updater import maybe_update_and_restart
            return maybe_update_and_restart(self.config)
        except Exception:
            self.log.error("Mise a jour echouee:\n%s", traceback.format_exc())
            return False


if __name__ == "__main__":
    if len(sys.argv) == 1:
        servicemanager.Initialize()
        servicemanager.PrepareToHostSingle(DolibarrAgentService)
        servicemanager.StartServiceCtrlDispatcher()
    else:
        config_dir = os.path.dirname(os.path.abspath(sys.argv[0]))
        os.chdir(config_dir)
        win32serviceutil.HandleCommandLine(DolibarrAgentService)
