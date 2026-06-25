import argparse
import json
import time
from pathlib import Path
from typing import Optional

from .sync import MirrorConfig, MirrorEngine


def load_config(path: Path) -> MirrorConfig:
    with path.open("r", encoding="utf-8") as handle:
        raw = json.load(handle)
    return MirrorConfig.from_dict(raw, base_dir=path.parent)


def cmd_version(_args: argparse.Namespace) -> int:
    version_path = Path(__file__).resolve().parents[1] / "VERSION"
    print(version_path.read_text(encoding="utf-8").strip())
    return 0


def cmd_run_once(args: argparse.Namespace) -> int:
    config = load_config(Path(args.config))
    engine = MirrorEngine(config)
    summary = engine.sync_once()
    print(summary.to_line())
    return 1 if summary.conflicts else 0


def cmd_daemon(args: argparse.Namespace) -> int:
    config = load_config(Path(args.config))
    engine = MirrorEngine(config)
    interval = max(1, args.interval)
    print(f"mirror daemon started: interval={interval}s")
    while True:
        summary = engine.sync_once()
        print(summary.to_line())
        time.sleep(interval)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="mirror-app",
        description="Local prototype for the Unraid LAN Mirror daemon.",
    )
    subcommands = parser.add_subparsers(dest="command", required=True)

    version = subcommands.add_parser("version", help="Print the project version.")
    version.set_defaults(func=cmd_version)

    run_once = subcommands.add_parser("run-once", help="Scan and sync once.")
    run_once.add_argument(
        "--config",
        default="config/dev.local.json",
        help="Path to mirror config JSON.",
    )
    run_once.set_defaults(func=cmd_run_once)

    daemon = subcommands.add_parser("daemon", help="Run repeated sync scans.")
    daemon.add_argument(
        "--config",
        default="config/dev.local.json",
        help="Path to mirror config JSON.",
    )
    daemon.add_argument(
        "--interval",
        type=int,
        default=10,
        help="Seconds between prototype sync scans.",
    )
    daemon.set_defaults(func=cmd_daemon)

    return parser


def main(argv: Optional[list[str]] = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    return args.func(args)
