import json
import sqlite3
import time
import unittest
from pathlib import Path
from tempfile import TemporaryDirectory

from mirror_app.sync import MirrorConfig, MirrorEngine


class MirrorEngineTests(unittest.TestCase):
    def make_engine(self, delete_propagation=False):
        temp = TemporaryDirectory()
        root = Path(temp.name)
        config_path = root / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "server_a": {"name": "a", "root": "a"},
                    "server_b": {"name": "b", "root": "b"},
                    "state_db": "state/mirror.sqlite3",
                    "trash_root": "trash",
                    "authority": "server_a_preferred",
                    "delete_propagation": delete_propagation,
                }
            ),
            encoding="utf-8",
        )
        config = MirrorConfig.from_dict(json.loads(config_path.read_text()), root)
        engine = MirrorEngine(config)
        return temp, root, engine

    def test_copies_new_file_from_a_to_b(self):
        temp, root, engine = self.make_engine()
        self.addCleanup(temp.cleanup)
        (root / "a" / "hello.txt").write_text("hello", encoding="utf-8")

        summary = engine.sync_once()

        self.assertEqual(summary.copied, 1)
        self.assertEqual((root / "b" / "hello.txt").read_text(encoding="utf-8"), "hello")

    def test_copies_new_file_from_b_to_a(self):
        temp, root, engine = self.make_engine()
        self.addCleanup(temp.cleanup)
        (root / "b" / "note.txt").write_text("note", encoding="utf-8")

        summary = engine.sync_once()

        self.assertEqual(summary.copied, 1)
        self.assertEqual((root / "a" / "note.txt").read_text(encoding="utf-8"), "note")

    def test_server_a_edit_restores_file_deleted_on_b(self):
        temp, root, engine = self.make_engine()
        self.addCleanup(temp.cleanup)
        (root / "a" / "plan.txt").write_text("v1", encoding="utf-8")
        engine.sync_once()

        time.sleep(0.001)
        (root / "a" / "plan.txt").write_text("v2", encoding="utf-8")
        (root / "b" / "plan.txt").unlink()
        summary = engine.sync_once()

        self.assertEqual(summary.copied, 1)
        self.assertEqual((root / "b" / "plan.txt").read_text(encoding="utf-8"), "v2")

    def test_both_changed_creates_conflict(self):
        temp, root, engine = self.make_engine()
        self.addCleanup(temp.cleanup)
        (root / "a" / "same.txt").write_text("base", encoding="utf-8")
        engine.sync_once()

        time.sleep(0.001)
        (root / "a" / "same.txt").write_text("a-change", encoding="utf-8")
        (root / "b" / "same.txt").write_text("b-change", encoding="utf-8")
        summary = engine.sync_once()

        self.assertEqual(summary.conflicts, 1)
        self.assertEqual((root / "a" / "same.txt").read_text(encoding="utf-8"), "a-change")
        self.assertEqual((root / "b" / "same.txt").read_text(encoding="utf-8"), "b-change")

        rows = sqlite3.connect(root / "state" / "mirror.sqlite3").execute(
            "select path, reason from conflicts"
        ).fetchall()
        self.assertEqual(rows[0], ("same.txt", "both_changed"))

    def test_unchanged_conflict_is_not_recorded_again(self):
        temp, root, engine = self.make_engine()
        self.addCleanup(temp.cleanup)
        (root / "a" / "same.txt").write_text("base", encoding="utf-8")
        engine.sync_once()

        time.sleep(0.001)
        (root / "a" / "same.txt").write_text("a-change", encoding="utf-8")
        (root / "b" / "same.txt").write_text("b-change", encoding="utf-8")
        engine.sync_once()
        summary = engine.sync_once()

        self.assertEqual(summary.conflicts, 0)
        self.assertEqual(summary.unchanged, 1)


if __name__ == "__main__":
    unittest.main()
