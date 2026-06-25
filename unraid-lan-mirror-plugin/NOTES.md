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

- Status: Planning/specification.
- Current phase: Phase 1 - Specification.
- Code started: No.
- Plugin package started: No.
- Daemon started: No.
- UI started: No.

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

### Next Suggested Task

- Turn the high-level behavior into an exact sync rules matrix and journal schema.

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
