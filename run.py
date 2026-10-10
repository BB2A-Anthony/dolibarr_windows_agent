import argparse
import json
import logging
import os

from agent.sender import load_config, send_report, set_api_key


def main():
    parser = argparse.ArgumentParser(
        description="Send Windows system information to a remote API."
    )
    parser.add_argument(
        "--config",
        default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "config.json"),
        help="Path to config.json",
    )
    parser.add_argument("--loop", action="store_true", help="Send continuously")
    parser.add_argument(
        "--set-api-key",
        metavar="KEY",
        help="Store the Dolibarr API key encrypted (DPAPI) in config.json",
    )
    args = parser.parse_args()

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    config = load_config(args.config)

    if args.set_api_key is not None:
        set_api_key(config, args.config, args.set_api_key)
        print("Cle API stockee chiffree dans", args.config)
        return

    if args.loop:
        import time
        from agent.updater import maybe_update_and_restart

        time.sleep(config.get("initial_delay_seconds", 30))
        while True:
            try:
                send_report(config)
            except Exception as exc:
                logging.getLogger("run").error("Report failed: %s", exc)
            if config.get("auto_update", True):
                try:
                    if maybe_update_and_restart(config):
                        logging.getLogger("run").info("Mise a jour planifiee, arret.")
                        return
                except Exception as exc:
                    logging.getLogger("run").error("Update failed: %s", exc)
            time.sleep(config.get("poll_interval_seconds", 14400))
    else:
        payload = send_report(config)
        print(json.dumps(payload, indent=2, default=str))


if __name__ == "__main__":
    main()
