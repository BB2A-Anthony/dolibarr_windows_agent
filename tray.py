import json
import logging
import os
import threading

from agent.sender import load_config, send_report, test_connection

CONFIG_PATH = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), "config.json"
)

DEFAULT_INITIAL_DELAY = 30
DEFAULT_POLL_INTERVAL = 14400


class AgentController:
    def __init__(self, config_path=CONFIG_PATH):
        self.config_path = config_path
        self.config = load_config(config_path)
        self.loop_thread = None
        self.stop_event = threading.Event()

    def start(self):
        if self.loop_thread and self.loop_thread.is_alive():
            return
        self.stop_event.clear()
        self.loop_thread = threading.Thread(target=self._loop, daemon=True)
        self.loop_thread.start()

    def stop(self):
        self.stop_event.set()

    def is_running(self):
        return bool(self.loop_thread and self.loop_thread.is_alive())

    def _loop(self):
        initial_delay = self.config.get("initial_delay_seconds", DEFAULT_INITIAL_DELAY)
        interval = self.config.get("poll_interval_seconds", DEFAULT_POLL_INTERVAL)
        if self.stop_event.wait(initial_delay):
            return
        while not self.stop_event.is_set():
            self.send_once()
            if self.stop_event.wait(interval):
                return

    def send_once(self):
        try:
            send_report(self.config)
        except Exception as exc:
            logging.getLogger("tray").error("Envoi échoué: %s", exc)

    def test(self):
        return test_connection(self.config)

    def save(self, api_url):
        self.config["api_url"] = api_url
        with open(self.config_path, "w", encoding="utf-8") as f:
            json.dump(self.config, f, indent=4)


def open_settings(controller, on_saved=None):
    import tkinter as tk
    from tkinter import messagebox, ttk

    root = tk.Tk()
    root.title("Dolibarr Agent - Paramètres")
    root.resizable(False, False)
    root.attributes("-topmost", True)

    frame = ttk.Frame(root, padding=16)
    frame.grid(row=0, column=0)

    ttk.Label(frame, text="URL Dolibarr :").grid(row=0, column=0, sticky="w", pady=4)
    url_var = tk.StringVar(value=controller.config.get("api_url", ""))
    ttk.Entry(frame, textvariable=url_var, width=50).grid(row=0, column=1, pady=4)

    ttk.Label(frame, text="Machine (guid) :", anchor="w").grid(row=1, column=0, sticky="w", pady=4)
    ttk.Label(frame, text=controller.config.get("guid", ""), foreground="gray").grid(row=1, column=1, sticky="w", pady=4)
    status_var = tk.StringVar()
    ttk.Label(frame, textvariable=status_var, foreground="gray").grid(
        row=2, column=0, columnspan=2, sticky="w", pady=(8, 0)
    )

    def do_test():
        controller.config["api_url"] = url_var.get().strip()
        status_var.set("Test en cours...")
        root.update_idletasks()
        ok, message = controller.test()
        status_var.set(("OK - " if ok else "Échec - ") + message)

    def save_and_close():
        api_url = url_var.get().strip()
        if not api_url:
            messagebox.showwarning("Champ requis", "L'URL est obligatoire.", parent=root)
            return
        controller.save(api_url)
        if on_saved:
            on_saved()
        messagebox.showinfo("Enregistré", "Paramètres enregistrés.", parent=root)
        root.destroy()

    buttons = ttk.Frame(frame)
    buttons.grid(row=4, column=0, columnspan=2, pady=(12, 0), sticky="e")
    ttk.Button(buttons, text="Tester la connexion", command=do_test).pack(side="left", padx=4)
    ttk.Button(buttons, text="Enregistrer", command=save_and_close).pack(side="left", padx=4)

    root.mainloop()


def main():
    import pystray
    from PIL import Image, ImageDraw

    logging.basicConfig(level=logging.INFO)

    controller = AgentController()
    controller.start()

    def make_image():
        img = Image.new("RGB", (64, 64), (28, 107, 160))
        draw = ImageDraw.Draw(img)
        draw.ellipse((16, 16, 48, 48), fill=(255, 255, 255))
        draw.rectangle((29, 22, 35, 38), fill=(28, 107, 160))
        draw.rectangle((29, 42, 35, 46), fill=(28, 107, 160))
        return img

    def on_settings(icon, item):
        open_settings(controller)

    def on_send_now(icon, item):
        controller.send_once()

    def on_test(icon, item):
        ok, message = controller.test()
        log = logging.getLogger("tray")
        (log.info if ok else log.error)("Test connexion: %s", message)

    def on_quit(icon, item):
        controller.stop()
        icon.stop()

    menu = pystray.Menu(
        pystray.MenuItem("Envoyer maintenant", on_send_now),
        pystray.MenuItem("Tester la connexion", on_test),
        pystray.MenuItem("Paramètres (URL / Clé API / ID tiers)...", on_settings),
        pystray.Menu.SEPARATOR,
        pystray.MenuItem("Quitter", on_quit),
    )

    icon = pystray.Icon(
        "DolibarrAgent",
        icon=make_image(),
        title="Dolibarr Windows Agent",
        menu=menu,
    )
    icon.run()


if __name__ == "__main__":
    main()
