from __future__ import annotations

import os
import shutil
import sqlite3
import time
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Endpoint:
    name: str
    root: Path


@dataclass(frozen=True)
class MirrorConfig:
    server_a: Endpoint
    server_b: Endpoint
    state_db: Path
    trash_root: Path
    delete_propagation: bool
    authority: str

    @classmethod
    def from_dict(cls, raw: dict, base_dir: Path) -> "MirrorConfig":
        def resolve(value: str) -> Path:
            path = Path(value)
            return path if path.is_absolute() else (base_dir / path).resolve()

        return cls(
            server_a=Endpoint(
                name=raw.get("server_a", {}).get("name", "server-a"),
                root=resolve(raw["server_a"]["root"]),
            ),
            server_b=Endpoint(
                name=raw.get("server_b", {}).get("name", "server-b"),
                root=resolve(raw["server_b"]["root"]),
            ),
            state_db=resolve(raw.get("state_db", "mirror.sqlite3")),
            trash_root=resolve(raw.get("trash_root", "trash")),
            delete_propagation=bool(raw.get("delete_propagation", False)),
            authority=raw.get("authority", "server_a_preferred"),
        )


@dataclass(frozen=True)
class FileState:
    exists: bool
    sig: str | None = None
    is_file: bool = False


@dataclass
class SyncSummary:
    copied: int = 0
    deleted: int = 0
    trashed: int = 0
    conflicts: int = 0
    unchanged: int = 0

    def to_line(self) -> str:
        return (
            "sync complete: "
            f"copied={self.copied} "
            f"deleted={self.deleted} "
            f"trashed={self.trashed} "
            f"conflicts={self.conflicts} "
            f"unchanged={self.unchanged}"
        )


class MirrorEngine:
    def __init__(self, config: MirrorConfig) -> None:
        self.config = config
        self.config.server_a.root.mkdir(parents=True, exist_ok=True)
        self.config.server_b.root.mkdir(parents=True, exist_ok=True)
        self.config.state_db.parent.mkdir(parents=True, exist_ok=True)
        self.config.trash_root.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(self.config.state_db)
        self.db.row_factory = sqlite3.Row
        self._init_db()

    def sync_once(self) -> SyncSummary:
        summary = SyncSummary()
        scan_a = self._scan(self.config.server_a.root)
        scan_b = self._scan(self.config.server_b.root)
        paths = sorted(set(scan_a) | set(scan_b) | set(self._journal_paths()))

        for rel_path in paths:
            a_state = scan_a.get(rel_path, FileState(False))
            b_state = scan_b.get(rel_path, FileState(False))
            previous = self._get_journal(rel_path)
            self._sync_path(rel_path, a_state, b_state, previous, summary)

        return summary

    def _sync_path(
        self,
        rel_path: str,
        a_state: FileState,
        b_state: FileState,
        previous: sqlite3.Row | None,
        summary: SyncSummary,
    ) -> None:
        previous_a = previous["a_sig"] if previous else None
        previous_b = previous["b_sig"] if previous else None
        a_changed = a_state.sig != previous_a
        b_changed = b_state.sig != previous_b

        if previous and previous["status"] == "conflict" and not a_changed and not b_changed:
            summary.unchanged += 1
            return

        if a_state.exists and b_state.exists and a_state.sig == b_state.sig:
            self._save_journal(rel_path, a_state, b_state, "synced")
            summary.unchanged += 1
            return

        if not previous:
            self._handle_new_path(rel_path, a_state, b_state, summary)
            return

        if a_state.exists and b_state.exists:
            if a_changed and not b_changed:
                self._copy_between(self.config.server_a, self.config.server_b, rel_path, summary)
            elif b_changed and not a_changed:
                self._copy_between(self.config.server_b, self.config.server_a, rel_path, summary)
            elif a_changed and b_changed:
                self._record_conflict(rel_path, "both_changed", summary)
            else:
                self._record_conflict(rel_path, "state_mismatch_without_change", summary)
            return

        if a_state.exists and not b_state.exists:
            if a_changed:
                self._copy_between(self.config.server_a, self.config.server_b, rel_path, summary)
            else:
                self._restore_or_delete_from_a(rel_path, summary)
            return

        if b_state.exists and not a_state.exists:
            if b_changed:
                self._record_conflict(rel_path, "server_a_deleted_server_b_changed", summary)
            else:
                self._handle_a_deleted_b_unchanged(rel_path, summary)
            return

        self._save_journal(rel_path, a_state, b_state, "deleted")
        summary.unchanged += 1

    def _handle_new_path(
        self,
        rel_path: str,
        a_state: FileState,
        b_state: FileState,
        summary: SyncSummary,
    ) -> None:
        if a_state.exists and not b_state.exists:
            self._copy_between(self.config.server_a, self.config.server_b, rel_path, summary)
            return
        if b_state.exists and not a_state.exists:
            self._copy_between(self.config.server_b, self.config.server_a, rel_path, summary)
            return
        if a_state.exists and b_state.exists:
            self._record_conflict(rel_path, "new_path_differs_on_both_servers", summary)
            return
        self._save_journal(rel_path, a_state, b_state, "deleted")

    def _restore_or_delete_from_a(self, rel_path: str, summary: SyncSummary) -> None:
        if self.config.authority == "server_a_preferred":
            self._copy_between(self.config.server_a, self.config.server_b, rel_path, summary)
        elif self.config.delete_propagation:
            self._delete_path(self.config.server_a, rel_path, summary)
        else:
            self._copy_between(self.config.server_a, self.config.server_b, rel_path, summary)

    def _handle_a_deleted_b_unchanged(self, rel_path: str, summary: SyncSummary) -> None:
        if self.config.delete_propagation:
            self._delete_path(self.config.server_b, rel_path, summary)
            self._save_pair_journal(rel_path, "deleted")
        else:
            self._copy_between(self.config.server_b, self.config.server_a, rel_path, summary)

    def _copy_between(
        self,
        source: Endpoint,
        target: Endpoint,
        rel_path: str,
        summary: SyncSummary,
    ) -> None:
        source_path = source.root / rel_path
        target_path = target.root / rel_path
        if not source_path.is_file():
            self._record_conflict(rel_path, "unsupported_non_file_path", summary)
            return

        if target_path.exists():
            self._trash_path(target, rel_path, "overwritten")
            summary.trashed += 1

        target_path.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source_path, target_path)
        summary.copied += 1
        self._save_pair_journal(rel_path, "synced")

    def _delete_path(self, endpoint: Endpoint, rel_path: str, summary: SyncSummary) -> None:
        path = endpoint.root / rel_path
        if not path.exists():
            return
        self._trash_path(endpoint, rel_path, "deleted")
        summary.trashed += 1
        if path.is_file():
            path.unlink()
        else:
            shutil.rmtree(path)
        summary.deleted += 1

    def _trash_path(self, endpoint: Endpoint, rel_path: str, reason: str) -> None:
        source = endpoint.root / rel_path
        if not source.exists():
            return
        stamp = time.strftime("%Y%m%d-%H%M%S")
        destination = (
            self.config.trash_root
            / endpoint.name
            / reason
            / f"{rel_path}.{stamp}"
        )
        destination.parent.mkdir(parents=True, exist_ok=True)
        if source.is_file():
            shutil.copy2(source, destination)
        else:
            shutil.copytree(source, destination)

    def _record_conflict(self, rel_path: str, reason: str, summary: SyncSummary) -> None:
        self.db.execute(
            """
            insert into conflicts (path, reason, created_at)
            values (?, ?, ?)
            """,
            (rel_path, reason, int(time.time())),
        )
        self.db.commit()
        self._save_pair_journal(rel_path, "conflict")
        summary.conflicts += 1

    def _scan(self, root: Path) -> dict[str, FileState]:
        result: dict[str, FileState] = {}
        for dirpath, dirnames, filenames in os.walk(root):
            dirnames[:] = [name for name in dirnames if not name.startswith(".mirror")]
            for filename in filenames:
                path = Path(dirpath) / filename
                rel_path = path.relative_to(root).as_posix()
                stat = path.stat()
                result[rel_path] = FileState(
                    exists=True,
                    sig=f"{stat.st_size}:{stat.st_mtime_ns}",
                    is_file=True,
                )
        return result

    def _init_db(self) -> None:
        self.db.executescript(
            """
            create table if not exists files (
                path text primary key,
                a_sig text,
                b_sig text,
                status text not null,
                updated_at integer not null
            );

            create table if not exists conflicts (
                id integer primary key autoincrement,
                path text not null,
                reason text not null,
                created_at integer not null
            );
            """
        )
        self.db.commit()

    def _journal_paths(self) -> list[str]:
        rows = self.db.execute("select path from files").fetchall()
        return [row["path"] for row in rows]

    def _get_journal(self, rel_path: str) -> sqlite3.Row | None:
        return self.db.execute(
            "select * from files where path = ?",
            (rel_path,),
        ).fetchone()

    def _save_pair_journal(self, rel_path: str, status: str) -> None:
        self._save_journal(
            rel_path,
            self._state_for(self.config.server_a.root / rel_path),
            self._state_for(self.config.server_b.root / rel_path),
            status,
        )

    def _save_journal(
        self,
        rel_path: str,
        a_state: FileState,
        b_state: FileState,
        status: str,
    ) -> None:
        self.db.execute(
            """
            insert into files (path, a_sig, b_sig, status, updated_at)
            values (?, ?, ?, ?, ?)
            on conflict(path) do update set
                a_sig = excluded.a_sig,
                b_sig = excluded.b_sig,
                status = excluded.status,
                updated_at = excluded.updated_at
            """,
            (rel_path, a_state.sig, b_state.sig, status, int(time.time())),
        )
        self.db.commit()

    def _state_for(self, path: Path) -> FileState:
        if not path.exists():
            return FileState(False)
        if not path.is_file():
            return FileState(True, "directory", False)
        stat = path.stat()
        return FileState(True, f"{stat.st_size}:{stat.st_mtime_ns}", True)
