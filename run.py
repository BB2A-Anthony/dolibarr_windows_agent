import argparse
import json
import logging
import os

from agent.sender import load_config, send_report


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
    args = parser.parse_args()

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    config = load_config(args.config)

    if args.loop:
        import time

        while True:
            try:
                send_report(config)
            except Exception as exc:
                logging.getLogger("run").error("Report failed: %s", exc)
            time.sleep(config.get("poll_interval_seconds", 300))
    else:
        payload = send_report(config)
        print(json.dumps(payload, indent=2, default=str))


if __name__ == "__main__":
    main()
