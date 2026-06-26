# Unraid LAN Mirror Plugin Notes

## Goal

Build an Unraid plugin that can be installed on two Unraid servers and keep selected shares mirrored across the local network.

The first version should be LAN-only. Remote networking, Tailscale-style pairing, or WireGuard support can be added later, but the initial design should not depend on any cloud service or outside relay.

The plugin should be faster and more efficient than general-purpose sync tools like Syncthing for this specific use case: two Unraid servers, selected shares, local network, event-driven mirroring, and a conservative safety model.

## Core Idea

Each Unraid server runs the plugin and a background mirror daemon.

The daemons pair over the local network, watch selected shares for changes, exchange change events, and sync only the changed paths using an efficient transfer method such as `rsync` over SSH.

The system should support true two-way sync, but with clear rules for conflicts, deletes, trash retention, and primary-server authority.

## First Version Scope

- Installable Unraid plugin package.
- Plugin UI inside the Unraid web interface.
- Background daemon/service on each server.
- Manual LAN pairing using IP address or hostname.
- Selected share sync.
- True two-way sync.
- Event-driven change detection.
- Batched sync queue.
- Scheduled sync option.
- Trash/version retention before overwrite or delete.
- Conflict quarantine.
- SQLite journal for local sync state.
- Periodic reconciliation scan to catch missed events.
- Logs and basic status page.

## Later Scope

- Tailscale-style remote pairing.
- WireGuard/Tailscale integration.
- Bandwidth limits.
- Per-file transfer progress.
- Email, webhook, or mobile notifications.
- Conflict resolution UI.
- ZFS/Btrfs snapshot integration.
- More advanced verification modes.
- Multi-peer support.

## Non-Goals For The First Version

- No cloud relay.
- No high-availability virtual share failover.
- No attempt to make two Unraid servers act like a clustered filesystem.
- No blind newest-timestamp-wins behavior by default.
- No automatic merging for binary files.
- No syncing of dangerous paths without warnings, such as live VM images, databases, or appdata services that are actively writing.

## Sync Modes

### Instant

Watch filesystem events and queue changes immediately.

Use a short debounce window so file bursts are grouped together instead of syncing every event separately.

Example debounce: 2 to 10 seconds.

### Batched

Collect changes for a configured interval before syncing.

Example intervals:

- 1 minute
- 5 minutes
- 15 minutes
- 60 minutes

### Scheduled

Run sync during configured windows.

Examples:

- Hourly
- Daily
- Custom cron-style schedule
- Overnight-only

## Speed And Efficiency Criteria

The plugin should be designed around speed from the start.

Key requirements:

- Use filesystem events for normal operation.
- Avoid full-share scans during ordinary sync.
- Maintain a local SQLite journal of known file state.
- Sync exact changed paths instead of whole shares.
- Use `rsync --files-from` for batches of changed paths.
- Use lazy hashing.
- Prefer size and modified time for the normal fast path.
- Hash only when needed for conflict detection, verification, or high-integrity mode.
- Coalesce repeated events for the same path.
- Wait for files to become stable before copying.
- Use separate queues per share where useful.
- Support limited parallel workers without overwhelming disks.
- Run periodic reconciliation scans on a schedule instead of constantly scanning.
- Provide a resumable initial indexing process.

## Safety Criteria

The plugin must avoid silent data loss.

Required safety behavior:

- Before overwriting a file, save the old copy to plugin-managed trash/version storage.
- Before mirroring a delete, save the deleted file to trash/version storage where possible.
- Keep conflict copies instead of overwriting when the correct winner is unclear.
- Keep a sync journal so the daemon can tell whether a file changed since the last known synced version.
- Log all destructive actions.
- Make delete propagation configurable per share.
- Exclude or warn heavily for risky live data paths.

## Trash And Version Retention

The plugin should keep changed or deleted files in a managed archive.

Retention should be configurable:

- Keep for X days.
- Keep up to X GB.
- Purge oldest files first when over the size limit.
- Retention should be configurable globally and per share.

Default idea:

- 30 days retention.
- User-configurable size cap.
- Trash enabled by default.

Possible trash layout:

```text
/mnt/user/.unraid-lan-mirror-trash/
  share-name/
    server-name/
      deleted/
      overwritten/
      conflicts/
```

## Authority Model

Each synced share should have an authority setting.

Options:

- No primary / equal peers.
- Server A preferred.
- Server B preferred.

For the user's preferred default, Server A can be the main copy.

This allows rules like:

- If Server B deletes a file but Server A still has it unchanged, copy Server A back to Server B.
- If Server A edits a file and Server B deletes it, copy Server A back to Server B.
- If Server A is the authority and a delete conflict happens, Server A's state can win depending on the exact rule.

## Conflict And Delete Rules

The daemon needs a baseline record for each file:

- Last known synced state.
- Server A state.
- Server B state.
- Whether either side changed since baseline.
- Whether either side deleted since baseline.

Suggested default rules when Server A is preferred:

```text
A changed, B unchanged:
  Copy A to B.

B changed, A unchanged:
  Copy B to A.

A unchanged, B deleted:
  Restore A to B.

A changed, B deleted:
  Copy A to B.

A deleted, B unchanged:
  Delete B only if delete propagation is enabled, saving B to trash first.

A deleted, B changed:
  Treat as conflict unless the user explicitly configures A delete wins.

A changed, B changed:
  Attempt safe text merge if enabled. Otherwise create a conflict.

A deleted, B deleted:
  Mark deleted in journal.
```

## Merge Behavior

Automatic merge should only be attempted for safe text-like files.

Examples of maybe-mergeable files:

- `.txt`
- `.md`
- `.json`
- `.yaml`
- `.yml`
- `.xml`
- `.csv`
- source code files
- simple config files

Examples of files that should not be auto-merged:

- `.docx`
- `.xlsx`
- `.pdf`
- photos
- videos
- archives
- databases
- VM images
- application state files

To do proper merging, the daemon needs a base version, meaning the last version that both servers agreed on.

Merge inputs:

```text
base copy
server A copy
server B copy
```

If a clean 3-way merge succeeds:

- Write the merged file to both servers.
- Save previous versions to trash/version storage.
- Update journal.

If merge fails:

- Keep both copies.
- Create a conflict record.
- Do not silently overwrite either side.

Conflict filename example:

```text
budget.conflict.ServerB.2026-06-25-143000.xlsx
```

## Initial Indexing

The initial index can be expensive for large Unraid shares, so it needs to be resumable and visible.

Requirements:

- Index one share at a time or with limited concurrency.
- Record progress.
- Allow pause and resume.
- Default to size and modified time instead of hashing every file.
- Offer optional high-integrity mode that hashes files.
- Support a "trust existing mirror" mode for users who already copied the data manually.

## Periodic Reconciliation

Filesystem events can be missed during restarts, outages, crashes, or network failures.

The plugin should periodically reconcile state.

Suggested options:

- Every hour.
- Every 6 hours.
- Daily.
- Weekly.
- Manual only.

The reconciliation scan should compare journal state against actual filesystem state and queue only paths that need attention.

## Architecture

```text
Unraid Web UI
  |
Plugin config
  |
Mirror daemon
  |
SQLite journal
  |
Filesystem watcher
  |
Sync queue
  |
rsync over SSH on LAN
  |
Remote mirror daemon
```

## Packaging Decision

This should be an Unraid plugin, not a Docker app.

Reasoning:

- It needs to integrate with the Unraid web UI.
- It needs to manage an Unraid-friendly background service.
- It needs access to selected shares under `/mnt/user`.
- It may need access to Unraid config paths, logs, notifications, and service controls.
- It should feel like a storage feature, not a separate app users have to wire up manually.

Recommended structure:

- Unraid plugin package installs UI files, config defaults, service scripts, and the daemon binary/script.
- The daemon runs directly on Unraid as a managed service.
- Development tests should use local folders, scripts, VMs, or spare Unraid systems instead of making Docker part of the product plan.

## GitHub Test Install Flow

The plugin should be installable on a test Unraid server from GitHub before it is submitted anywhere public like Community Applications.

Expected test install model:

- Create a GitHub repository for the plugin.
- Keep the install manifest as a `.plg` file in the repo or attach it to GitHub Releases.
- Build release artifacts for the daemon and UI files.
- The `.plg` file downloads and installs the correct release artifact.
- On the test Unraid server, go to Plugins -> Install Plugin.
- Paste the raw `.plg` URL or latest-release `.plg` URL.
- Click Install.
- After install, configure the plugin from its Unraid Settings/Tools page.

Example install URL shapes:

```text
https://raw.githubusercontent.com/<owner>/<repo>/main/unraid-lan-mirror.plg
https://github.com/<owner>/<repo>/releases/latest/download/unraid-lan-mirror.plg
```

The release-download URL is preferred once releases exist because it gives test servers a stable install URL.

Possible manual install command for testing:

```text
installplg https://github.com/<owner>/<repo>/releases/latest/download/unraid-lan-mirror.plg
```

Test server safety rules:

- Use a test Unraid server, Unraid VM, or spare machine first.
- Use disposable test shares only.
- Do not point the plugin at important shares until the sync rules and trash behavior have been tested.
- Keep trash/versioning enabled.
- Start with delete propagation disabled or heavily warned until delete behavior is proven.
- Verify uninstall removes the service and UI cleanly without touching synced data.

## Versioning

Use semantic versioning-style numbers from the beginning.

Current version:

```text
0.2.3
```

Version source of truth:

- Keep the current project version in the root `VERSION` file.
- The README should show the current version.
- Future `.plg` manifests and release artifacts should use the same version number.
- Git tags should match releases, using the format `vX.Y.Z`, for example `v0.0.2`.

Version meaning:

- `0.0.x`: planning notes, scaffolding, and very early prototypes.
- `0.1.0`: first two-peer LAN prototype.
- `0.2.0`: remote share discovery and improved peer setup.
- `0.3.0`: safer conflict/trash management for peer sync.
- `0.4.0`: first broader UI-managed test build.
- `1.0.0`: first version considered safe enough for careful real-world use.

Bump rules:

- Patch bump: docs, notes, small fixes, or internal cleanup.
- Minor bump: new working feature or testable milestone.
- Major bump: breaking change after `1.0.0`.

Current release status:

- `0.0.1` was the first planning/prototype version.
- `0.0.2` is the first installable Unraid plugin scaffold.
- `0.0.3` fixes the Unraid Settings page placement.
- `0.0.4` moves the Settings tile to the correct User Utilities section.
- `0.0.5` replaces the raw config display with a form-based settings page.
- `0.0.6` changes share paths to dropdowns populated from current `/mnt/user` shares.
- `0.0.7` removes the runtime Python dependency and uses a PHP runner on Unraid.
- `0.1.0` adds the first LAN peer prototype over SSH/rsync.
- `0.1.1` fixes equal-peer delete propagation when deleting from Server B.
- `0.1.2` adds clearer Local/Remote mirror mode and delete behavior controls.
- `0.1.3` changes Local/Remote mode to a switch and hides remote-only fields in local mode.
- `0.1.4` shows trash path and restarts a running daemon after settings save.
- `0.1.5` adds an Accept Peer Key workflow to install a peer public key from the UI.
- `0.1.6` allows Remote LAN mirror mode to save before all peer details are filled in.
- `0.1.7` preserves the Local/Remote switch during key actions.
- `0.1.8` adds an Update Plugin button to the settings page.
- `0.1.9` fixes remote-only actions preserving Local mode by mistake.
- `0.1.10` fixes the Update Plugin button by using full `installplg` paths.
- `0.1.11` fixes the Update Plugin button by downloading `mirror.plg` to `/tmp` before running `installplg`.
- `0.1.12` republishes the update fix as a newer version so Unraid will not reject it as same/older.
- `0.2.0` jumps past Unraid's string-style `0.1.x` comparison so it updates from `0.1.8`.
- `0.2.1` fixes the update button by using Unraid's `plugin install` CLI when `installplg` is unavailable.
- `0.2.2` adds cache busting to the update button's GitHub manifest download.
- `0.2.3` moves update command output into a popup window instead of the main settings page.
- It now includes the first installable Unraid plugin scaffold.
- It should not be used on real shares.

## Main Components

### Plugin UI

- Pair server.
- Show peer status.
- Select shares.
- Configure sync mode.
- Configure authority rules.
- Configure trash retention.
- Configure delete behavior.
- Show sync queue.
- Show conflicts.
- Show logs and recent actions.

### Mirror Daemon

- Runs as a background service.
- Watches shares.
- Maintains sync journal.
- Talks to peer daemon.
- Builds sync batches.
- Runs transfers.
- Applies conflict/delete rules.
- Manages trash/version retention.

### Test Harness

Testing should happen in layers so real Unraid shares are not used until the sync engine is proven.

Layer 1: local folder simulation.

- Run two daemon instances on one development machine.
- Use two test directories as fake servers.
- Test creates, edits, deletes, conflicts, trash, and reconciliation.

Layer 2: VM or spare Unraid test server.

- Install the plugin package on a non-production Unraid instance.
- Use disposable test shares.
- Validate plugin install, service start/stop, UI, logs, and Unraid paths.

Layer 3: two real Unraid servers with disposable shares.

- Pair over LAN.
- Sync test shares only.
- Verify instant, batched, and scheduled behavior.
- Verify trash retention and conflict behavior.

Layer 4: limited production trial.

- Start with one low-risk share.
- Disable delete propagation at first if desired.
- Keep trash/versioning enabled.
- Watch logs and reconciliation results before expanding.

### Pairing

First version:

- Manual IP or hostname.
- Shared pairing token or generated secret.
- Local network only.
- SSH key setup for transfer.
- Configurable encryption mode.

Later:

- Tailscale/WireGuard-aware pairing.
- Remote peer discovery.
- Better identity management.

### Encryption

Encryption should be configurable because some users may prefer maximum speed on a trusted local network, while others may want stronger protection even on LAN.

Options:

- None: no plugin-level encryption. This should be allowed for trusted LAN-only setups.
- SSH transport encryption: use `rsync` over SSH.
- Future daemon-to-daemon encryption: encrypted peer API and transfer channel without relying only on SSH.

Default idea:

- Default to encrypted transfer when practical.
- Allow `none` as an explicit advanced option.
- Show a warning when encryption is disabled.
- Keep the setting per peer or per sync profile, not per individual file.

### Transfer Engine

Preferred first implementation:

- `rsync` over SSH.
- Use `--files-from` for exact path batches.
- Optional bandwidth limit.
- Optional dry run.
- Preserve ownership, permissions, timestamps, and extended attributes where appropriate for Unraid.
- Optional unencrypted local transfer mode may be explored later for users who explicitly choose speed over transport encryption on trusted LANs.

## Risky Paths And Exclusions

Default exclusions or warnings should cover:

- Docker appdata that is actively running.
- Databases.
- VM images.
- Temporary download files.
- Incomplete torrent/download files.
- Lock files.
- Cache directories.
- Thumbnail directories.
- Plugin trash folder itself.
- The plugin journal/config directory.

## Open Questions

- Should delete propagation be enabled by default?
- Should Server A preferred be the global default, or configured per share?
- Should initial MVP include automatic text merge, or should both-changed always conflict at first?
- Where should base versions be stored for merge support?
- Should trash live inside each share or in one central plugin share?
- Should the daemon be written in Go, Python, or shell plus helper tools?
- How much should the UI expose in the first version?
- Should we support more than two servers later?
- Which encryption modes should be available in the MVP: SSH-only, none, or both?
- Should disabling encryption require an advanced-mode confirmation?
- Should the first real Unraid test use a VM or a spare physical server?
- Should the first GitHub test install use a raw `main` `.plg` URL or a GitHub Releases `.plg` URL?

## Proposed MVP Plan

### Phase 1: Specification

- Write exact sync rules.
- Define journal schema.
- Define config schema.
- Define plugin folder layout.
- Define UI screens.
- Define daemon responsibilities.

### Phase 2: Prototype Daemon

- Watch one local folder.
- Record changes in SQLite.
- Batch changes.
- Sync changed paths to a second local test folder.
- Implement trash before overwrite/delete.
- Implement simple conflict detection.
- Build a local two-folder test harness.

### Phase 3: Two-Server LAN Prototype

- Add peer config.
- Add SSH/rsync transfer.
- Exchange change events between daemons.
- Test selected Unraid shares.
- Add reconnect/retry behavior.

### Phase 4: Unraid Plugin Wrapper

- Package daemon with plugin install files.
- Add Unraid web UI pages.
- Add service start/stop controls.
- Add persistent config.
- Add logs and status.
- Test install on a VM or spare Unraid machine before real shares.

### Phase 5: Safety And Performance

- Add initial indexer.
- Add reconciliation scans.
- Add retention cleanup.
- Add exclusion presets.
- Add file stability checks.
- Add queue coalescing.
- Add basic benchmark tests.

### Phase 6: Advanced Sync Behavior

- Add optional text 3-way merge.
- Add conflict browser.
- Add per-share authority rules.
- Add batch and scheduled modes.
- Add high-integrity verification mode.

### Phase 7: Future Remote Support

- Explore Tailscale/WireGuard support.
- Add remote pairing without changing the core sync engine.
- Keep LAN-only mode as the default.

## Success Criteria

- User can install plugin on two Unraid servers.
- User can pair servers over LAN.
- User can pick shares to sync.
- Changes on either server appear on the other.
- Server A preferred delete/edit rules work.
- Old overwritten/deleted files are retained.
- Conflicts do not cause silent data loss.
- Normal sync does not require full-share rescans.
- Initial indexing is resumable.
- Periodic reconciliation catches missed changes.
- The system feels noticeably faster than Syncthing for selected Unraid share mirroring.

## Project Tracker

This section should be updated at the end of every project task so the notes always show where the project currently stands.

### Current Status

- Status: Two-server LAN prototype.
- Current phase: Phase 3 - Two-Server LAN Prototype.
- Current version: 0.2.3.
- Code started: Yes.
- Plugin package started: Yes.
- Daemon started: PHP-based Unraid prototype.
- UI started: Basic Unraid Settings page with peer settings.

### Completed So Far

- Created project folder.
- Created initial project notes.
- Captured core goal: LAN-first Unraid plugin for selected share mirroring.
- Captured desired sync model: true two-way sync with safety rules.
- Captured Server A preferred authority behavior.
- Captured trash/version retention requirement.
- Captured instant, batched, and scheduled sync mode requirements.
- Captured performance requirement: faster and more efficient than Syncthing for this Unraid-specific use case.
- Added this project tracker.
- Started local Python prototype daemon/CLI.
- Added local two-folder test config.
- Added SQLite-backed sync journal.
- Added first unit tests for local sync behavior.
- Added first installable Unraid plugin scaffold.
- Added `mirror.plg` installer.
- Added `packages/mirror-0.0.2.txz` package.
- Added basic Unraid Settings page.
- Added `mirrorctl` command for status, run-once, start, and stop.
- Confirmed local two-share sync works on Unraid.
- Added first LAN peer settings and SSH/rsync prototype.

### Current Decisions

- First version should be LAN-only.
- Tailscale-style remote support is a later goal.
- Sync should be true two-way.
- Server A can be configured as the preferred/main copy.
- If Server B deletes a file and Server A still has it, Server A should restore it to Server B.
- If Server A edits a file and Server B deletes it, Server A should copy back to Server B.
- Both-changed files should only be auto-merged when safe.
- Binary/document/media files should not be blindly merged.
- Old overwritten/deleted files should be kept in trash/version storage.
- Sync should be event-driven and journal-based, not constant full rescans.
- Encryption should be configurable.
- No encryption should be allowed as an explicit option for trusted LAN-only setups.
- Disabling encryption should show a warning.
- Delivery should be an Unraid plugin, not a Docker app.
- Docker is not part of the product plan.
- Testing should start with local/disposable folders before using real Unraid shares.
- Test installs should be done from GitHub using an Unraid `.plg` install URL.
- GitHub Releases should eventually provide the preferred stable test install URL.
- GitHub repository target: `https://github.com/DotumZane/Mirror`
- Version numbers should be tracked from the beginning.
- Current version is `0.2.3`.
- The root `VERSION` file is the source of truth for the current version.
- Future release tags should use the format `vX.Y.Z`, for example `v0.0.2`.
- The Python sync engine remains for local development tests only.
- The installed Unraid plugin uses shell/PHP and does not require Python.
- The current Unraid plugin is installable as a LAN peer prototype, but it is not production-safe.
- The first install URL is `https://raw.githubusercontent.com/DotumZane/Mirror/main/mirror.plg`.

### Next Suggested Task

- Push to GitHub, install/update on both Unraid servers, generate/copy SSH key, test peer, and run a disposable remote share sync.

### Tracker Update Template

Use this format at the end of each future task:

```text
Date:
Task completed:
Files changed:
Current phase:
What changed:
New decisions:
Open questions:
Next suggested task:
```

### Task Log

#### 2026-06-25 - Initial Notes And Tracker

- Task completed: Created the project folder and initial notes document, then added this tracker section.
- Files changed: `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 1 - Specification.
- What changed: Added project goals, criteria, architecture notes, sync behavior, safety rules, performance requirements, MVP plan, success criteria, and progress tracker.
- New decisions: The notes file will track project status after every task.
- Open questions: Exact sync rules, journal schema, config schema, daemon language, UI scope, and trash storage location still need to be defined.
- Next suggested task: Define the exact sync rules matrix and journal schema.

#### 2026-06-25 - Encryption Option Added

- Task completed: Added configurable encryption requirement.
- Files changed: `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 1 - Specification.
- What changed: Added an encryption section, updated pairing and transfer notes, and added open questions about MVP encryption modes.
- New decisions: Encryption should be configurable, and `none` should be allowed as an explicit option for trusted LAN-only setups.
- Open questions: Decide whether MVP supports SSH-only, none-only for prototype, or both; decide what warning/confirmation is required when encryption is disabled.
- Next suggested task: Define the exact sync rules matrix and journal schema.

#### 2026-06-25 - Packaging And Testing Direction

- Superseded by: 2026-06-25 - Plugin Only Decision.
- Task completed: Added plugin-vs-Docker packaging direction and layered testing plan.
- Files changed: `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 1 - Specification.
- What changed: Added a Packaging Decision section, Test Harness section, updates to MVP phases, and tracker updates.
- New decisions: Superseded. The current decision is plugin-only.
- Open questions: Superseded. Docker is no longer part of the product plan.
- Next suggested task: Define the exact sync rules matrix and journal schema.

#### 2026-06-25 - Plugin Only Decision

- Task completed: Removed Docker as a product direction.
- Files changed: `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 1 - Specification.
- What changed: Updated packaging decision, test harness layers, open questions, MVP phase notes, and tracker decisions to keep the project as an Unraid plugin only.
- New decisions: This project should be delivered as an Unraid plugin, not a Docker app. Testing should use local folders, VMs, spare Unraid systems, and disposable real shares.
- Open questions: Decide whether the first real Unraid test should use a VM or spare physical server.
- Next suggested task: Define the exact sync rules matrix and journal schema.

#### 2026-06-25 - GitHub Test Install Flow

- Task completed: Added GitHub-based plugin test install plan.
- Files changed: `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 1 - Specification.
- What changed: Added a GitHub Test Install Flow section with `.plg` URL options, `installplg` command option, and test server safety rules.
- New decisions: Test installs should use an Unraid `.plg` file hosted through GitHub. GitHub Releases should eventually provide the preferred stable install URL.
- Open questions: Decide whether early testing should use a raw `main` `.plg` URL or a GitHub Releases `.plg` URL first.
- Next suggested task: Define the exact sync rules matrix and journal schema.

#### 2026-06-25 - Local Git Repository Prepared

- Task completed: Created the first local commit and configured the GitHub remote.
- Files changed: `README.md`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 1 - Specification.
- What changed: Added a GitHub README, committed the project notes, and set `origin` to `https://github.com/DotumZane/Mirror.git`.
- New decisions: Use `DotumZane/Mirror` as the GitHub repository for this project.
- Open questions: Push is blocked until GitHub authentication is available in the local shell.
- Next suggested task: Authenticate GitHub locally, push `main`, then continue with exact sync rules matrix and journal schema.

#### 2026-06-25 - GitHub Push Confirmed

- Task completed: Confirmed the first project files are visible on GitHub.
- Files changed: `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 1 - Specification.
- What changed: Verified local `main` is tracking `origin/main` and recorded that the GitHub repository now has the initial files.
- New decisions: Continue using GitHub Desktop for pushes when shell authentication is unavailable.
- Open questions: None for repository setup.
- Next suggested task: Define the exact sync rules matrix and journal schema.

#### 2026-06-25 - Version Tracking Added

- Task completed: Added project version tracking.
- Files changed: `VERSION`, `README.md`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 1 - Specification.
- What changed: Added root `VERSION` file, displayed the version in README, added versioning rules to notes, and updated the project tracker.
- New decisions: Start at version `0.0.1`, use the root `VERSION` file as the source of truth, and use release tags like `v0.0.1`.
- Open questions: Decide when to tag `v0.0.1` on GitHub.
- Next suggested task: Define the exact sync rules matrix and journal schema.

#### 2026-06-25 - Local Prototype Started

- Task completed: Added the first runnable local sync prototype.
- Files changed: `.gitignore`, `README.md`, `config/dev.local.json`, `mirror_app/__init__.py`, `mirror_app/__main__.py`, `mirror_app/cli.py`, `mirror_app/sync.py`, `sandbox/.gitkeep`, `tests/test_sync.py`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 2 - Prototype Daemon.
- What changed: Added a Python CLI with `version`, `run-once`, and `daemon` commands; added local two-folder config; added SQLite journal; added trash-before-overwrite/delete support; added first Server A preferred sync rules; added unit tests; verified manual sandbox sync.
- New decisions: Use Python for the first local prototype so behavior can be tested immediately in this workspace. Keep real Unraid shares out of scope until the prototype is safer.
- Open questions: Decide whether the production daemon stays Python or moves to a single compiled binary later.
- Next suggested task: Expand the sync rules matrix and add tests for delete propagation, trash retention cleanup, and conflict files.

#### 2026-06-25 - Installable Unraid Scaffold Added

- Task completed: Added the first installable Unraid plugin scaffold.
- Files changed: `.gitignore`, `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.0.2.txz`, `plugin/source/install/slack-desc`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/README.txt`, `plugin/source/usr/local/emhttp/plugins/mirror/default-config.json`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 4 - Unraid Plugin Wrapper.
- What changed: Added a GitHub-installable `.plg`, Slackware-style `.txz` package, basic Settings page, control command, default config, and build script.
- New decisions: The first test install will use the raw GitHub `main` URL until release assets are added. The installable scaffold version is `0.0.2` because `0.0.1` was already used for the first prototype.
- Open questions: Test on Unraid and confirm whether stock Python is available or whether the daemon should be bundled/ported before service start is enabled by default.
- Next suggested task: Push to GitHub, install on a disposable Unraid test server, and capture install output.

#### 2026-06-25 - Settings Placement Fix

- Task completed: Fixed the Mirror page showing inline on the main Settings page.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.0.3.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 4 - Unraid Plugin Wrapper.
- What changed: Changed the `.page` header to `Menu="OtherSettings"` with `Type="xmenu"`, matching the User Utilities pattern used by working Unraid plugins, rebuilt the package, and bumped the version to `0.0.3`.
- New decisions: Use `OtherSettings` for the Settings -> User Utilities tile.
- Open questions: Confirm on Unraid after upgrade that only the tile appears on the Settings index and the full Mirror page opens after clicking the tile.
- Next suggested task: Push to GitHub, update/install the plugin on Unraid, and verify page placement.

#### 2026-06-25 - User Utilities Placement Fix

- Task completed: Moved the Mirror tile to the intended User Utilities section.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.0.4.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 4 - Unraid Plugin Wrapper.
- What changed: Changed the `.page` header to `Menu="Utilities"`, matching GPU Statistics' page header, rebuilt the package, and bumped the version to `0.0.4`.
- New decisions: Use `Menu="Utilities"` for the Settings -> User Utilities tile on Unraid 7.3.
- Open questions: Confirm on Unraid after upgrade that the Mirror tile appears under User Utilities and not System Settings.
- Next suggested task: Push to GitHub, update/install the plugin on Unraid, and verify page placement.

#### 2026-06-25 - Settings UI Improved

- Task completed: Replaced the raw JSON settings panel with a form-based settings page.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.0.5.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/default-config.json`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 4 - Unraid Plugin Wrapper.
- What changed: Added editable fields for Server A path, Server B path, authority rule, delete mirroring, and daemon scan interval; actions now redirect back to the page and show the last result.
- New decisions: Keep the UI focused on disposable test share setup until the sync engine is safer.
- Open questions: Confirm the Save Settings and action buttons work on Unraid, since PHP is not installed in the local Mac environment for linting.
- Next suggested task: Push to GitHub, update/install the plugin on Unraid, and test saving settings with disposable shares.

#### 2026-06-25 - Share Dropdowns Added

- Task completed: Replaced manual share path fields with dropdowns populated from current Unraid shares.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.0.6.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 4 - Unraid Plugin Wrapper.
- What changed: The settings page now scans `/mnt/user` for current shares, shows Server A and Server B share dropdowns, and the save action validates selected shares before writing full `/mnt/user/<share>` paths to config.
- New decisions: For local test mode, users should select shares from dropdowns instead of typing paths.
- Open questions: Later two-server mode will need local share dropdown plus remote peer share discovery.
- Next suggested task: Push to GitHub, update/install the plugin on Unraid, and verify the dropdowns list current shares.

#### 2026-06-25 - Python Dependency Removed From Plugin

- Task completed: Fixed daemon start failure caused by missing `python3` on Unraid.
- Files changed: `.gitignore`, `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.0.7.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_runner.php`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 4 - Unraid Plugin Wrapper.
- What changed: Added a PHP runner for run-once and daemon mode, changed `mirrorctl` to call PHP instead of Python, and stopped packaging the Python prototype into the Unraid install package.
- New decisions: Installed Unraid plugin code should rely on PHP/shell for now because those are available on Unraid by default.
- Open questions: Confirm on Unraid that `Start` launches the daemon and `Run Once` syncs disposable test shares.
- Next suggested task: Push to GitHub, update/install the plugin on Unraid, and test Start/Run Once again.

#### 2026-06-25 - LAN Peer Prototype Added

- Task completed: Added first two-server LAN peer prototype.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.1.0.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/default-config.json`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_runner.php`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Added LAN peer settings, SSH key generation, peer test action, remote Server B config, SSH-based remote scan, and rsync copy support in both directions.
- New decisions: First peer implementation uses SSH and rsync because they are native tools for LAN Unraid-to-Unraid transfer.
- Open questions: Confirm remote scan works on the peer Unraid version and add remote share dropdown/discovery after basic connection is proven.
- Next suggested task: Push to GitHub, install/update on both Unraid servers, generate/copy SSH key, test peer, and run a disposable remote share sync.

#### 2026-06-25 - Equal Peer B Delete Fix

- Task completed: Fixed delete propagation when deleting from Server B in equal-peer mode.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.1.1.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_runner.php`, `tests/test_sync.py`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: When authority is `equal_peers` and delete mirroring is enabled, deleting an unchanged file from Server B now deletes the matching file on Server A instead of restoring it from A.
- New decisions: Equal-peer delete propagation should be symmetric. Server A preferred mode can still restore A to B for B-side deletes.
- Open questions: Confirm the fix on Unraid using disposable shares.
- Next suggested task: Push to GitHub, update/install the plugin, and retest deleting from Server B in equal-peer mode.

#### 2026-06-25 - Mirror Mode And Delete Behavior Controls

- Task completed: Added clearer mode and delete behavior controls.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.1.2.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/default-config.json`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_runner.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Added an explicit Local mirror / Remote LAN mirror toggle and replaced the delete checkbox with a Delete behavior dropdown.
- New decisions: Delete behavior should be explicit: `Restore missing files` or `Mirror deletes after saving trash`.
- Open questions: Confirm on Unraid that selecting Equal peers plus Mirror deletes allows deletion from either side.
- Next suggested task: Push to GitHub, update/install the plugin, select Equal peers plus Mirror deletes, and retest deleting from Server B.

#### 2026-06-25 - Local Remote Switch UI

- Task completed: Improved Local/Remote mode UI.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.1.3.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Replaced Local/Remote radio buttons with a segmented switch and hides peer IP/user/port/key controls while Local mirror is selected.
- New decisions: Remote-only fields should stay hidden unless Remote LAN mirror mode is active.
- Open questions: Confirm the visibility toggle works in Unraid's web UI.
- Next suggested task: Push to GitHub, update/install the plugin, and verify Local mode only shows local settings.

#### 2026-06-25 - Trash Path And Save Restart

- Task completed: Made trash location visible and save settings restart the daemon when needed.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.1.4.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Added a Trash section that shows the configured trash path and added automatic daemon restart after Save Settings if the daemon was already running.
- New decisions: Saving settings should not start a stopped daemon, but should restart a running daemon so changes take effect.
- Open questions: Confirm on Unraid that save/restart reports cleanly in the action message.
- Next suggested task: Push to GitHub, update/install the plugin, and verify trash path display plus save restart behavior.

#### 2026-06-25 - Accept Peer Key Workflow

- Task completed: Added UI workflow to accept a peer SSH public key.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.1.5.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Added an Accept Peer Key section that validates ssh-ed25519 keys and writes them to `/root/.ssh/authorized_keys` with safe permissions.
- New decisions: Pairing should be possible from the plugin UI by copying a generated public key from one server into the peer's Accept Peer Key box.
- Open questions: Confirm on Unraid that accepting the key enables Test Peer from the other server.
- Next suggested task: Push to GitHub, update/install both servers, generate key on Server A, accept it on Server B, then run Test Peer on Server A.

#### 2026-06-25 - Remote Mode Save Fix

- Task completed: Fixed Local/Remote mode snapping back to Local after save.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.1.6.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Remote LAN mirror mode can now be saved as a draft before peer host/share details are complete.
- New decisions: Save Settings should persist the selected mode; peer validation should happen when testing or running the peer connection.
- Open questions: Confirm on Unraid that the switch stays on Remote after saving.
- Next suggested task: Push to GitHub, update/install, switch to Remote LAN mirror, save, and confirm it remains selected.

#### 2026-06-25 - Key Action Mode Preservation

- Task completed: Fixed Generate SSH Key and Accept Peer Key switching the page back to Local mode.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.1.7.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Key-related forms now preserve the current Local/Remote mode and the action handler writes that mode back into config before redirecting.
- New decisions: Any secondary action from the Remote-only panel should preserve Remote mode.
- Open questions: Confirm on Unraid that Generate SSH Key no longer snaps the switch back to Local.
- Next suggested task: Push to GitHub, update/install, switch to Remote, generate key, and verify Remote remains selected.

#### 2026-06-25 - Update Button Added

- Task completed: Added an Update Plugin button to the settings page.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.1.8.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Added a Plugin Update section that runs `installplg https://raw.githubusercontent.com/DotumZane/Mirror/main/mirror.plg` and shows the command output on the page.
- New decisions: During early testing, the in-page update button should use the GitHub main branch `.plg` URL.
- Open questions: Confirm on Unraid that the update button runs and returns install output cleanly.
- Next suggested task: Push to GitHub, update once manually, then test future updates with the in-page Update Plugin button.

#### 2026-06-25 - Remote Action Mode Fix

- Task completed: Fixed Accept Peer Key switching the page back to Local mode.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.1.9.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Remote-only forms now post `preserve_mirror_mode=remote` directly instead of reusing the saved page mode.
- New decisions: Any action shown only in the remote section should force-preserve Remote LAN mirror mode.
- Open questions: Confirm on Unraid that Accept Peer Key leaves Remote mode selected.
- Next suggested task: Push to GitHub, update/install, switch to Remote, accept a key, and verify Remote remains selected.

#### 2026-06-25 - Update Button Path Fix

- Task completed: Fixed Update Plugin button failing because `installplg` was not in the web action PATH.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.1.10.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Update action now searches known Unraid paths for `installplg` and runs the executable by full path.
- New decisions: Web UI actions should not rely on shell PATH for Unraid system commands.
- Open questions: Confirm on Unraid that the Update Plugin button now runs successfully.
- Next suggested task: Push to GitHub, update manually once, then test the in-page Update Plugin button.

#### 2026-06-25 - Update Button Local Manifest Fix

- Task completed: Fixed Update Plugin button passing the wrong value into Unraid's plugin installer flow.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.1.11.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Update action now downloads the GitHub `mirror.plg` manifest to `/tmp/mirror-latest.plg`, verifies it looks like a plugin manifest, and runs `installplg` against that local `.plg` file.
- New decisions: Plugin self-update should avoid handing a remote URL directly to the web action; use a local manifest file for clearer Unraid behavior.
- Open questions: Confirm on Unraid that the Update Plugin button now installs the pushed version without the `installplg is not a plg file` message.
- Next suggested task: Push to GitHub, update manually once to `0.1.11`, then test the in-page Update Plugin button for the next version.

#### 2026-06-25 - Same-Version Install Blocker Fix

- Task completed: Bumped the plugin package to a newer version after Unraid rejected reinstalling the same-version manifest.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.1.12.txz`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Rebuilt the same local-manifest update fix as version `0.1.12` so Unraid sees it as newer than the installed package.
- New decisions: When testing installer/update fixes, always bump the version because Unraid will reject same-version installs as older/same.
- Open questions: Confirm on Unraid that installing the GitHub URL now updates to `0.1.12`.
- Next suggested task: Push to GitHub, install/update with the raw `mirror.plg` URL, then confirm the Settings page shows version `0.1.12`.

#### 2026-06-25 - Unraid Version Compare Fix

- Task completed: Bumped the plugin to `0.2.0` after Unraid kept treating `0.1.12` as older than installed `0.1.8`.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.2.0.txz`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Moved the test build from `0.1.x` to `0.2.0` so Unraid's apparent string-style version comparison accepts the update.
- New decisions: Avoid patch numbers above `9` in the same minor line for Unraid plugin tests unless the version format is proven to compare numerically.
- Open questions: Confirm on Unraid that installing the GitHub URL updates from `0.1.8` to `0.2.0`.
- Next suggested task: Push to GitHub, install/update with the raw `mirror.plg` URL, then confirm the Settings page shows version `0.2.0`.

#### 2026-06-25 - Update Button Plugin CLI Fix

- Task completed: Fixed the Update Plugin button for Unraid builds where `installplg` is not available.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.2.1.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The update action now downloads `mirror.plg` to `/tmp/mirror-latest.plg`, then prefers `plugin install /tmp/mirror-latest.plg` and only falls back to `installplg` if the `plugin` CLI is unavailable.
- New decisions: Use Unraid's `plugin` CLI for self-update behavior on current Unraid versions.
- Open questions: Confirm on Unraid that the in-page Update Plugin button can update from `0.2.0` to `0.2.1`.
- Next suggested task: Push to GitHub, manually install/update to `0.2.1`, then use the in-page button for the next patch test.

#### 2026-06-25 - GitHub Raw Cache Bust Fix

- Task completed: Fixed stale GitHub raw manifest downloads causing Unraid to see an older plugin version.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.2.2.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The update action now appends a timestamp cache-buster to the GitHub `mirror.plg` download URL before installing the local `/tmp/mirror-latest.plg` file.
- New decisions: Manual test installs can use `https://raw.githubusercontent.com/DotumZane/Mirror/main/mirror.plg?mirror_cache_bust=1` if Unraid reports an older/same version after a fresh push.
- Open questions: Confirm on Unraid that the cache-busted manual URL updates to `0.2.2`.
- Next suggested task: Push to GitHub, install/update with the cache-busted URL if needed, then confirm Settings shows version `0.2.2`.

#### 2026-06-25 - Update Output Popup

- Task completed: Moved update command output out of the main settings page and into a popup window.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.2.3.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The Update Plugin button now opens a separate command-style output window, and the main Settings page suppresses old update command logs from the blue status area.
- New decisions: Long command output should live in a popup/log window; the main page should only show short status messages.
- Open questions: Confirm on Unraid that update output opens in a popup and the main Mirror settings page stays clean.
- Next suggested task: Push to GitHub and use the existing `0.2.2` Update Plugin button to update to `0.2.3`.

#### 2026-06-25 - Local Unraid Share Sync Confirmed

- Task completed: Confirmed the plugin can sync two selected local Unraid shares.
- Files changed: `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 4 - Unraid Plugin Wrapper.
- What changed: Recorded successful user test that the two selected shares sync.
- New decisions: The local same-server share sync path is working enough to use for disposable test shares.
- Open questions: Superseded by the first LAN peer prototype in `0.1.0`.
- Next suggested task: Test the LAN peer prototype on two disposable shares.
