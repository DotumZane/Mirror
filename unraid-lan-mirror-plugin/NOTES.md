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
0.8.0
```

Version source of truth:

- Keep the current project version in the root `VERSION` file.
- The README should show the current version.
- Future `.plg` manifests and release artifacts should use the same version number.
- Git tags should match releases, using the format `vX.Y.Z`, for example `v0.0.2`.

Version meaning:

- `0.0.x`: planning notes, scaffolding, and very early prototypes.
- `0.1.0`: first two-peer LAN prototype.
- `0.2.0`: first remote peer setup and update flow hardening.
- `0.3.0`: guided LAN peer discovery and invite/accept setup.
- `0.4.0`: clearer LAN pairing diagnostics and stale discovery handling.
- `0.4.x`: safer conflict/trash management for peer sync.
- `0.5.0`: first broader UI-managed test build.
- `0.8.0`: first large-share baseline sync and tabbed control UI.
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
- `0.2.4` changes update output to an Unraid-style in-page modal overlay.
- `0.2.5` fixes duplicate plugin entries caused by installing `/tmp/mirror-latest.plg`.
- `0.3.0` adds the first guided LAN peer discovery, invite, and accept workflow.
- `0.3.1` adds a dedicated pairing responder on TCP port `23891` and direct peer IP fallback.
- `0.3.2` adds scan diagnostics and stronger pairing responder startup checks.
- `0.3.3` improves invite/accept diagnostics and makes pending invites more visible.
- `0.3.4` splits peer linking from share selection in guided pairing.
- `0.3.5` keeps Pending Invites visible and adds a check button plus invite ID feedback.
- `0.3.6` refreshes settings actions in-place and logs received invites on the peer.
- `0.3.7` prevents stuck disabled AJAX buttons and warns about peer version mismatch.
- `0.3.8` restarts the pairing responder on install/update so it cannot keep serving stale code.
- `0.3.9` clears stale/orphaned responder processes still owning TCP `23891`.
- `0.4.0` clears cached discovery at scan start and labels stale peer cards.
- `0.4.1` hides stale peer cards from the active list and makes Find Mirror Servers a normal submit.
- `0.4.2` reports direct-host scan failures and adds a pairing responder restart button.
- `0.4.3` treats same-version update checks as already current instead of failed.
- `0.4.4` uses normal Unraid submits for pairing actions and reloads on AJAX failure.
- `0.4.5` auto-refreshes running scans and shortens deep scan timeout.
- `0.4.6` keeps discovered peers active for 30 minutes instead of hiding them after two.
- `0.4.7` adds Factory Reset Plugin and Master / Managed remote role mode.
- `0.4.8` simplifies the Managed remote page to update, LAN peer setup, and control.
- `0.4.9` moves Update Plugin into the Version status box.
- `0.5.0` makes plugin self-update use the GitHub API before raw GitHub to avoid stale branch-cache manifests.
- `0.5.1` restores compact equal-height status boxes and makes the Version box update button smaller.
- `0.5.2` fixes all status boxes at the same compact height and moves Update Plugin to the Version box top-right.
- `0.5.3` improves quick LAN discovery by scanning nearby/common IPs in addition to ARP/neighborhood entries.
- `0.5.4` prevents cached pairing responder version responses and reports responder version mismatches in scan results.
- `0.5.5` adds responder identity diagnostics to reveal which host/process is reporting an old version.
- `0.5.6` adds Check Local Responder and Clear Peer Results controls for stale discovery debugging.
- `0.5.7` simplifies LAN pairing to direct peer IP invite instead of automatic discovery cards.
- `0.5.8` adds a direct-invite hello check and query-parameter fallback when JSON POST returns no peer response.
- `0.5.9` adds a Peer Link status tile and clearer connected-state UI.
- `0.6.0` hides completed invite/accept detail messages once a peer is linked.
- `0.6.1` loads remote shares from the linked peer and uses a remote share dropdown.
- `0.6.2` hides remote host, SSH user, and SSH port fields when a linked peer is available.
- `0.6.3` keeps Remote LAN mirror selected after refreshing remote shares.
- `0.6.4` uses normal page submit for Save Settings so the button cannot stay grayed out.
- `0.6.5` removes the manual SSH key setup sections from the Mirror page.
- `0.6.6` auto-creates the internal transfer key and reports daemon startup failures.
- `0.6.7` keeps the daemon running when a remote sync attempt fails.
- `0.6.8` removes the fragile immediate daemon-start health gate.
- `0.6.9` runs the daemon as a shell retry loop around `run-once`.
- `0.7.0` hardens daemon launch and adds startup diagnostics.
- `0.7.1` uses normal page submit for Control actions.
- `0.7.2` automatically prepares SSH on the linked peer before remote sync.
- `0.7.3` adds query fallback and diagnostics for automatic peer SSH setup.
- `0.7.4` hardens automatic sshd startup and reports command output.
- `0.7.5` sends Master Start and Stop to the linked remote.
- `0.7.6` verifies daemon stop and reports remote control status.
- `0.7.7` stops scans from recreating deleted shares and skips sync daemon start on Managed remote.
- `0.8.0` adds background Initial Sync for large shares and reorganizes the UI into tabs.
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
- Current version: 0.8.0.
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
- Current version is `0.8.0`.
- The root `VERSION` file is the source of truth for the current version.
- Future release tags should use the format `vX.Y.Z`, for example `v0.0.2`.
- The Python sync engine remains for local development tests only.
- The installed Unraid plugin uses shell/PHP and does not require Python.
- The current Unraid plugin is installable as a LAN peer prototype, but it is not production-safe.
- The first install URL is `https://raw.githubusercontent.com/DotumZane/Mirror/main/mirror.plg`.

### Next Suggested Task

- Push to GitHub, update both Unraid servers to `0.8.0`, and test Initial Sync plus the new tabs.

### Chat Handoff

Use this section when continuing the project in a new chat.

Current repo state:

- Workspace: `/Users/zane/Documents/Unraid`
- GitHub repository: `https://github.com/DotumZane/Mirror`
- Install URL: `https://raw.githubusercontent.com/DotumZane/Mirror/main/mirror.plg`
- Current version: `0.8.0`
- Current branch: `main`
- Push workflow: User normally pushes from GitHub Desktop.
- Important: If `git status` says `main` is ahead of `origin/main`, remind the user to push before testing updates in Unraid.

Current product direction:

- Mirror is an Unraid plugin, not Docker.
- It is still an early LAN-only disposable-share prototype.
- The goal is two Unraid servers with selected shares mirrored both ways.
- The user wants true two-way sync, trash/version safety, fast operation, and eventually Tailscale-style remote support.
- One server should be configurable as Master.
- The other server can be set as Managed remote.
- Managed remote should behave like a receiver and should not expose local share-pair settings.

What currently works:

- Plugin installs from `mirror.plg`.
- Versioned package build exists at `packages/mirror-0.8.0.txz`.
- Local same-server share sync has been confirmed by the user on disposable shares.
- Equal-peer delete behavior was fixed in earlier builds.
- Settings saves restart the daemon when needed.
- In-page plugin update works through a popup command window and uses the GitHub API before raw GitHub to avoid stale branch-cache manifests.
- Factory Reset Plugin exists in Control and clears Mirror state without touching user shares.
- LAN Pair Setup can scan/restart responder/check pending invites.
- Managed remote view now hides local configuration sections, and Update Plugin lives in the Version status box.

Known weak spots / likely next pain:

- Pairing has been unreliable during rapid iteration, especially stale responder state and cached discovery.
- The managed remote mode is currently a UI/config guardrail, not a full master-pushed configuration system.
- Master does not yet push selected share pairs, authority rules, delete behavior, or daemon settings to the managed remote.
- The plugin is not production-safe and should only be pointed at disposable test shares.
- PHP linting was not available in the Mac workspace during recent commits because local `php` was not installed.

Recommended next implementation:

1. Add a master-to-managed-remote configuration API to the pairing responder.
2. Let the master send the selected remote share, authority rule, delete behavior, and sync interval to the managed remote after pairing.
3. Make the managed remote accept that config only from the linked peer.
4. Add a clear Paired With / Managed By status card on both servers.
5. Keep Factory Reset as the escape hatch for broken pairing state.

Recommended test flow:

1. Push local commits to GitHub from GitHub Desktop.
2. On both Unraid servers, update Mirror to `0.8.0`.
3. Factory reset both plugins if pairing state looks stale.
4. Set the intended receiver server to Managed remote.
5. Confirm the managed server shows Update Plugin in the Version status box, plus LAN Peer Setup and Control.
6. Leave the controlling server as Master.
7. Build and test the next master-controlled config push using disposable shares only.

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

#### 2026-06-25 - Update Output Modal

- Task completed: Changed update command output from a separate popup window to an Unraid-style modal overlay.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.2.4.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The Update Plugin button now opens a centered modal over the Settings page with dimmed background, command output, and a Done button that closes the modal and reloads the page.
- New decisions: Plugin update output should follow the normal Unraid modal pattern rather than opening a separate browser window.
- Open questions: Confirm on Unraid that `0.2.4` update output visually matches the built-in plugin update dialog.
- Next suggested task: Push to GitHub and use the existing Update Plugin button to update to `0.2.4`.

#### 2026-06-25 - Duplicate Plugin Entry Fix

- Task completed: Fixed the self-update path creating a second Mirror plugin entry.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.2.5.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The updater now downloads the manifest to `/tmp/mirror.plg` instead of `/tmp/mirror-latest.plg`, and the installer removes stale `/boot/config/plugins/mirror-latest.plg`, `/var/log/plugins/mirror-latest.plg`, and `/tmp/mirror-latest.plg` files.
- New decisions: Temporary plugin manifests must keep the canonical plugin filename so Unraid does not track them as separate installed plugins.
- Open questions: Confirm on Unraid that updating to `0.2.5` removes the duplicate Mirror entry from the Plugins page.
- Next suggested task: Push to GitHub, update to `0.2.5`, then refresh the Plugins page and confirm only one Mirror entry remains.

#### 2026-06-25 - Guided LAN Pairing Prototype

- Task completed: Added the first scan, invite, and accept workflow for easier two-server configuration.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.3.0.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/include/lan.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Added a LAN Peer Setup section with known-host/deep subnet scan, discovered peer cards, invite form, pending invite list, accept/reject actions, LAN discovery endpoint, invite endpoint, SSH key exchange, and automatic remote config writing.
- New decisions: Pairing must require manual approval on the invited server; discovery can expose only basic server metadata and share names on trusted LAN.
- Open questions: Confirm whether Unraid Connect/local access allows `http://peer/plugins/mirror/include/lan.php` between servers, and whether a future build should support HTTPS/custom ports.
- Next suggested task: Push to GitHub, update both servers to `0.3.0`, run Find Mirror Servers, invite from one server, accept on the other, then test peer connection.

#### 2026-06-25 - Pairing Responder Fix

- Task completed: Fixed Find Mirror Servers not discovering peers through Unraid's authenticated web plugin path.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.3.1.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_pairing_server.php`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Added a dedicated pairing responder served by `php -S 0.0.0.0:23891`, taught `mirrorctl` to start/stop/restart it, starts it during install and before scans, changed discovery/invites to use port `23891`, and added a direct peer IP fallback field.
- New decisions: Guided pairing should not depend on Unraid's authenticated web UI routes; use a tiny LAN-only responder for discovery and invite handoff.
- Open questions: Confirm that both servers can reach each other on TCP port `23891`; if not, add custom pairing port settings.
- Next suggested task: Push to GitHub, update both servers to `0.3.1`, confirm Pairing says `Listening on 23891`, then scan using the peer IP if automatic discovery is empty.

#### 2026-06-25 - Pairing Scan Diagnostics

- Task completed: Added diagnostics for the one-sided Find Mirror Servers failure.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.3.2.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Find Mirror Servers now restarts the local pairing responder, self-tests `127.0.0.1:23891`, reports hosts checked, direct host used, and responder output. `mirrorctl pair-start` and `pair-restart` now detect failed startup and stale PID files more clearly.
- New decisions: Discovery actions should be self-diagnosing so server-to-server network or responder problems can be seen directly in the Mirror page.
- Open questions: Use the new scan output to determine whether the failing server has a local responder problem, a port conflict, or blocked peer reachability.
- Next suggested task: Push to GitHub, update both servers to `0.3.2`, click Find Mirror Servers on the failing server, and inspect the blue diagnostic output.

#### 2026-06-25 - Pairing Invite Feedback

- Task completed: Improved feedback for guided pairing when peers are found but invite/accept is unclear.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.3.3.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Invite calls now expose peer JSON errors instead of hiding them behind curl failures, invite success explains that the peer must accept, accept success lists remote host/share, the page shows Pending Invites count, and the LAN Peer Setup section explains to invite one direction only.
- New decisions: Pairing UX should report the next required step after every action.
- Open questions: Confirm whether pending invites appear after sending one invite from only one server.
- Next suggested task: Push to GitHub, update both servers to `0.3.3`, invite from one server, then check the peer's Pending Invites count and Accept card.

#### 2026-06-25 - Link First Pairing Flow

- Task completed: Changed guided pairing so Invite/Accept only establishes the peer link, without choosing shares.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.3.4.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_pairing_server.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Discovered peer cards now show only Invite, pending invites show only Accept/Reject, peer trust is stored in `/boot/config/plugins/mirror/peer.json`, and Share Pair gets a Use Linked Peer button that fills the remote host after pairing.
- New decisions: Pairing/trust and share mapping should be separate steps.
- Open questions: Confirm the link-first flow feels clearer, then decide whether Share Pair should fetch peer shares live from the linked peer.
- Next suggested task: Push to GitHub, update both servers to `0.3.4`, invite/accept to establish link, then use Share Pair to choose shares.

#### 2026-06-25 - Pending Invite Visibility

- Task completed: Made the accept location visible even when there are no pending invites.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.3.5.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Pending Invites is now always shown, includes a Check Pending Invites button, shows a no-invites hint when empty, and Invite success now shows the receiving host and invite ID.
- New decisions: The Accept location should never disappear just because no pending invite is currently stored.
- Open questions: Confirm whether invite success shows an invite ID and whether the receiving server's Pending Invites count increments.
- Next suggested task: Push to GitHub, update both servers to `0.3.5`, send one invite, then check Pending Invites on the receiving server.

#### 2026-06-25 - No-Flash Pairing Refresh

- Task completed: Reduced settings-page flashing and improved invite arrival visibility.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.3.6.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_pairing_server.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Normal Mirror forms now submit with AJAX and refresh only the Mirror panel, the update modal refreshes the panel without a hard page reload, invite failures include HTTP/body details, and the receiving pairing responder writes a visible pending-invite status message when it stores an invite.
- New decisions: Settings actions should update the Mirror panel in place whenever possible, while plugin update output should keep using the Unraid-style modal.
- Open questions: Confirm both servers are updated to the same version before retesting invite/accept; the screenshot showed one peer still on `0.3.2`.
- Next suggested task: Push to GitHub, update both servers to `0.3.6`, then send one invite and watch the receiving server's Pending Invites area update.

#### 2026-06-25 - AJAX Button Recovery

- Task completed: Fixed Invite and other no-flash actions getting stuck grayed out when the browser request hangs or panel refresh fails.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.3.7.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: AJAX form submits now have browser-side timeouts, restore disabled buttons on failure, show an inline client-side message instead of staying stuck, and discovered peer cards warn when the peer is on a different Mirror version.
- New decisions: No-flash page actions must always have an escape path that restores controls.
- Open questions: Confirm both servers show the same Mirror version before retrying invite/accept.
- Next suggested task: Push to GitHub, update both servers to `0.3.7`, click Find Mirror Servers, confirm both peer cards show `0.3.7`, then invite one direction.

#### 2026-06-25 - Pairing Responder Restart On Update

- Task completed: Fixed updated servers still advertising an old Mirror version through the LAN pairing responder.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.3.8.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The installer now restarts the pairing responder after every install/update instead of leaving an already-running responder alone, and the peer-card warning now explains that the reported version comes from the responder.
- New decisions: Any package update that changes pairing code must restart the pairing responder so discovery does not report stale versions.
- Open questions: Confirm that updating both servers to `0.3.8` makes Find Mirror Servers show the peer responder as `0.3.8`.
- Next suggested task: Push to GitHub, update both servers to `0.3.8`, click Find Mirror Servers, then retry Invite once both peer cards report `0.3.8`.

#### 2026-06-25 - Orphaned Pairing Responder Cleanup

- Task completed: Fixed stale pairing responders that keep advertising an old version even after plugin update.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.3.9.txz`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: `mirrorctl pair-stop` and `pair-restart` now kill the recorded responder PID and any process actually listening on TCP `23891`, using `ss` and `fuser` when available, then start a fresh responder.
- New decisions: The pairing responder port belongs to Mirror, so restart/stop commands are allowed to clear stale owners on that port.
- Open questions: Confirm that updating both servers to `0.3.9` makes Find Mirror Servers report the peer responder as `0.3.9`.
- Next suggested task: Push to GitHub, update both servers to `0.3.9`, run Find Mirror Servers, and retry Invite only after both responders report `0.3.9`.

#### 2026-06-25 - Stale Discovery Handling

- Task completed: Stopped cached LAN discovery data from looking like a fresh peer result.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.4.0.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Find Mirror Servers now clears saved peer cards at scan start, scan requests include a nonce, peer cards show when they were last seen, stale cached peer cards are labeled, and Invite is disabled for stale cached results.
- New decisions: The UI must distinguish cached discovery results from live scan results before pairing actions are allowed.
- Open questions: Confirm whether the stale `0.3.2` peer card disappears or updates after installing `0.4.0` and running Find Mirror Servers.
- Next suggested task: Push to GitHub, update both servers to `0.4.0`, run Find Mirror Servers, and check the Last scan/seen timestamps before inviting.

#### 2026-06-25 - Active Peer List Cleanup

- Task completed: Removed stale discovery cards from the active invite flow.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.4.1.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Peer cards older than two minutes are hidden from the active peer list, the page shows a warning when only stale cached results exist, and Find Mirror Servers now uses a full submit instead of AJAX so long scans cannot leave the button disabled.
- New decisions: Long-running scan actions should use normal Unraid page submit behavior until the scanner becomes an actual background job.
- Open questions: Confirm that the old `0.3.2` cached card no longer appears as an active invite target after updating to `0.4.1`.
- Next suggested task: Push to GitHub, update both servers to `0.4.1`, click Find Mirror Servers, and verify only fresh peer results appear.

#### 2026-06-25 - Direct Host Scan Diagnostics

- Task completed: Made zero-result LAN scans explain direct-host failures.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.4.2.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Scan results now keep the typed direct host, record direct-host failure details in the action message, and LAN Peer Setup includes a Restart Pairing Responder button.
- New decisions: Pairing troubleshooting needs direct action buttons and exact failure text in the UI, not only hidden log details.
- Open questions: After updating to `0.4.2`, run Restart Pairing Responder on both servers, then Find Mirror Servers with the direct IP and read the direct-host failure line if no peer appears.
- Next suggested task: Push to GitHub, update both servers to `0.4.2`, restart pairing responders on both servers, then run a direct-host scan.

#### 2026-06-25 - Same-Version Update Message

- Task completed: Made repeated Update Plugin clicks explain same-version results clearly.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.4.3.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The updater now parses the downloaded manifest version before running Unraid's installer. If the downloaded version matches the installed version, the popup reports installed/downloaded versions and says no update was installed instead of calling it a failed plugin command.
- New decisions: Same-version update checks are not failures; they usually mean the latest commit has not been pushed yet or GitHub has not served the new manifest yet.
- Open questions: Confirm the next repeated update attempt shows "No update installed" instead of Unraid's "not reinstalling same version" failure.
- Next suggested task: Push to GitHub, then update both servers to `0.4.3` so future repeated clicks give the clearer message.

#### 2026-06-25 - Pairing Form Reliability

- Task completed: Stopped LAN pairing actions from using the flaky no-flash AJAX path.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.4.4.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Find Mirror Servers, Restart Pairing Responder, Invite, Check Pending Invites, Accept, and Reject now use normal Unraid form submits. Any remaining no-flash AJAX action that fails in the browser now reloads the page to recover instead of leaving a yellow warning stuck on the page.
- New decisions: Pairing actions should prefer reliability over no-flash UI until the plugin has a real background job/status endpoint.
- Open questions: Confirm the yellow "Load failed" browser warning disappears after updating to `0.4.4`.
- Next suggested task: Push to GitHub, update both servers to `0.4.4`, then retry Restart Pairing Responder and Find Mirror Servers.

#### 2026-06-25 - Running Scan Feedback

- Task completed: Made long LAN scans visibly progress instead of looking stuck.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.4.5.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The Mirror page now warns and auto-refreshes while a scan is marked running, deep /24 scan per-host timeout is shorter, and a completed empty deep scan tells the user to try a direct-host scan.
- New decisions: Deep scanning should remain a fallback; direct-host scanning is the preferred troubleshooting path for two known servers.
- Open questions: Confirm the scan no longer appears stuck on `running`, then use direct host if deep scan finds no peers.
- Next suggested task: Push to GitHub, update both servers to `0.4.5`, run a direct-host scan with the other server's IP, and inspect the blue diagnostic if it finds no peers.

#### 2026-06-25 - Peer Result Visibility Window

- Task completed: Fixed found peers disappearing from the invite area too quickly.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.4.6.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Discovered peer cards now remain active for 30 minutes instead of two minutes, while still showing when each peer was last seen.
- New decisions: Pairing discovery results should remain usable long enough for a human to read scan output, scroll, and click Invite.
- Open questions: Confirm the Invite card remains visible after a successful scan.
- Next suggested task: Push to GitHub, update both servers to `0.4.6`, run Find Mirror Servers, then use the visible Invite button.

#### 2026-06-25 - Factory Reset And Managed Remote Mode

- Task completed: Added a clean reset path and a role setting for master/managed-remote operation.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.4.7.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/default-config.json`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Control now includes Factory Reset Plugin with confirmation, which stops Mirror, clears plugin config/pairing/scan/key/db/trash/log state, recreates default config, and restarts pairing without touching user shares. The settings page also has a Server Role section with Master and Managed remote modes; managed remotes are blocked from saving share-pair settings locally.
- New decisions: One server should be able to act as a managed remote so share-pair and sync-rule decisions are made from the master server.
- Open questions: The next step is to make the master push share-pair settings to a managed remote automatically after pairing.
- Next suggested task: Push to GitHub, update both servers to `0.4.7`, factory reset both plugins if needed, set one server to Managed remote and the other to Master, then retry pairing from the master.

#### 2026-06-25 - Simplified Managed Remote View

- Task completed: Hid local sync configuration from servers set to Managed remote.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.4.8.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Managed remote now shows only Plugin Update, LAN Peer Setup, and Control sections. Share Pair, SSH key entry, trash, current config, and recent log are hidden while the server is managed. The Control section includes Switch Back To Master so the server is not trapped in managed mode.
- New decisions: Managed remote should behave like a receiver and avoid exposing local sync configuration.
- Open questions: The next step is still the master-controlled remote configuration push after pairing.
- Next suggested task: Push to GitHub, update both servers to `0.4.8`, set the remote server to Managed remote, and confirm only Update, LAN Peer Setup, and Control remain visible.

#### 2026-06-26 - Version Box Update Button

- Task completed: Moved the plugin update action into the Version status box and bumped the build version.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.4.9.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The top Version card now shows the installed version and the Update Plugin button. The standalone Plugin Update panel was removed so the page starts with status, warnings, role, and LAN setup.
- New decisions: Every code or packaged UI change must include a version bump before rebuilding so Unraid sees it as an installable update.
- Open questions: Confirm the updated page layout on Unraid after installing `0.4.9`.
- Next suggested task: Push to GitHub, update both servers to `0.4.9`, confirm Update Plugin appears in the Version status box, then continue with master-controlled remote configuration.

#### 2026-06-26 - GitHub API Update Manifest

- Task completed: Hardened the plugin self-update manifest download path after raw GitHub served a stale `0.4.8` branch manifest.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.5.0.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Update Plugin now downloads `mirror.plg` through the GitHub contents API first, decodes and validates the manifest, and only falls back to raw GitHub if the API path fails. The update popup now reports the manifest source.
- New decisions: Use `0.5.0` instead of `0.4.10` because earlier Unraid testing showed patch numbers above `9` can compare poorly in some plugin update paths.
- Open questions: Confirm install/update to `0.5.0` using the commit-specific manifest URL while the branch raw URL cache catches up.
- Next suggested task: Push to GitHub, update both servers to `0.5.0`, then use future Update Plugin clicks normally.

#### 2026-06-26 - Compact Status Box Layout

- Task completed: Restored compact equal-height status boxes after moving Update Plugin into the Version box.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.5.1.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Status cards now have a fixed compact minimum height, the Version card keeps the update form out of normal layout flow, and the Update Plugin button is styled smaller inside the card.
- New decisions: Compact status-card actions should be visually smaller and must not change the grid row height.
- Open questions: Confirm the `0.5.1` page on Unraid shows all status boxes back at the original compact size.
- Next suggested task: Push to GitHub, update both servers to `0.5.1`, verify the status box layout, then continue with master-controlled remote configuration.

#### 2026-06-26 - Fixed Status Box Height

- Task completed: Matched the top status boxes to the compact bottom-row box height.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.5.2.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Status boxes now use an explicit compact height, and the Version box update button is smaller and positioned at the top-right so it does not affect the grid row height.
- New decisions: Status cards should use fixed dimensions when they contain compact controls.
- Open questions: Confirm on Unraid that all six status boxes are now the same compact height.
- Next suggested task: Push to GitHub, update both servers to `0.5.2`, verify the status box layout, then continue with master-controlled remote configuration.

#### 2026-06-26 - Broader Quick LAN Discovery

- Task completed: Improved Find Mirror Servers when the peer is not already in the local ARP/neighborhood table.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.5.3.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Quick LAN scan now checks known LAN neighbors plus nearby addresses around the local server IP and common LAN host endings. Empty quick scans now tell the user to enter the direct peer IP or use Deep /24 scan.
- New decisions: Find Mirror Servers should not depend only on cached ARP/neighborhood entries.
- Open questions: Confirm whether `0.5.3` finds the peer without using direct host, and if not, use the direct peer IP to capture the exact failure.
- Next suggested task: Push to GitHub, update both servers to `0.5.3`, retry Find Mirror Servers, then enter the other server IP if the quick scan still finds no peers.

#### 2026-06-26 - Pairing Responder Version Freshness

- Task completed: Reduced stale pairing responder version reports during LAN discovery.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.5.4.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_pairing_server.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Pairing responder JSON now sends no-cache headers and clears PHP stat cache before reading the installed VERSION file. The scanner also requests no-cache responses and reports version mismatches in the scan result message.
- New decisions: Discovery responses should be treated as volatile status data and should never be cached.
- Open questions: Confirm whether both peer cards report `0.5.4` after updating both servers and restarting pairing responders if needed.
- Next suggested task: Push to GitHub, update both servers to `0.5.4`, run Restart Pairing Responder on both if a peer still reports an older version, then retry Find Mirror Servers.

#### 2026-06-26 - Responder Identity Diagnostics

- Task completed: Added responder identity diagnostics for version mismatch debugging.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.5.5.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_pairing_server.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Pairing responder hello responses now include responder PID, responder host IP, version file path, version file mtime, and script mtime. Peer cards and scan messages show those details when a responder reports a different Mirror version.
- New decisions: Version mismatch debugging should identify the exact responder process and host that answered discovery.
- Open questions: Use the `0.5.5` peer card details to determine whether the old `0.5.2` response is coming from the intended peer, a stale responder process, or a different LAN server.
- Next suggested task: Push to GitHub, update both servers to `0.5.5`, retry Find Mirror Servers, and read the responder host/pid/mtime details if a card still reports `0.5.2`.

#### 2026-06-26 - Local Responder Debug Controls

- Task completed: Added UI controls to separate stale discovery cards from live responder mismatches.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.5.6.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: LAN Peer Setup now has Check Local Responder and Clear Peer Results buttons. Check Local Responder reports the local installed version, responder version, responder name/host/pid, and version/script mtimes. Clear Peer Results wipes cached discovery cards before a new scan.
- New decisions: Pairing troubleshooting needs explicit local responder checks before interpreting peer discovery cards.
- Open questions: After updating both servers to `0.5.6`, does Check Local Responder report `0.5.6` on both servers?
- Next suggested task: Push to GitHub, update both servers to `0.5.6`, click Clear Peer Results, click Check Local Responder on both servers, then run Find Mirror Servers again.

#### 2026-06-26 - Direct IP Invite Flow

- Task completed: Simplified LAN Peer Setup to direct peer IP invites.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.5.7.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Removed automatic discovery cards, scan subnet, deep scan, Find Mirror Servers, and Clear Peer Results from the main LAN pairing UI. LAN Peer Setup now asks for a Peer IP address and sends the invite directly to that server. The direct invite clears old discovery cards and remembers the entered IP.
- New decisions: Use direct peer IP pairing for the prototype until the automatic discovery flow is worth revisiting.
- Open questions: Confirm direct invite from the master reaches the managed remote and appears under Pending Invites.
- Next suggested task: Push to GitHub, update both servers to `0.5.7`, enter the other server IP, send the invite, then accept it on the peer.

#### 2026-06-26 - Direct Invite Fallback

- Task completed: Made direct peer invites more tolerant of responder request handling differences.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.5.8.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_pairing_server.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Send Invite now checks the peer responder with `hello` before inviting. If JSON POST invite returns no usable peer response, it retries using query parameters. The pairing responder accepts invite fields from JSON POST or query parameters.
- New decisions: Direct IP invite should have a fallback path while the tiny PHP responder is still under active testing.
- Open questions: Confirm whether `0.5.8` sends the invite successfully to `10.68.1.10`.
- Next suggested task: Push to GitHub, update both servers to `0.5.8`, enter the peer IP, send the invite, then accept it on the peer server.

#### 2026-06-26 - Connected Peer Status UI

- Task completed: Made successful pairing visible as a first-class status.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.5.9.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Added a Peer Link tile to the top status grid showing connected, pending, or not linked. LAN Peer Setup now shows the linked peer as a green success message with peer version instead of a warning-style note.
- New decisions: Pair status should be visible in the top summary area, not only in the latest action message.
- Open questions: Confirm both servers show the connected peer clearly after updating to `0.5.9`.
- Next suggested task: Push to GitHub, update both servers to `0.5.9`, verify Peer Link status on both, then implement master-pushed remote configuration.

#### 2026-06-26 - Hide Completed Invite Details

- Task completed: Removed noisy completed invite/accept action details once pairing is established.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.6.0.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The Mirror page now suppresses successful "Invite sent" and "Invite accepted" last-action messages when a linked peer exists, leaving the Peer Link tile and connected peer success message as the main status.
- New decisions: Successful one-time pairing messages should not persist after connected state is available elsewhere.
- Open questions: Confirm both servers no longer show the blue invite detail box after updating to `0.6.0`.
- Next suggested task: Push to GitHub, update both servers to `0.6.0`, verify the cleaner connected view, then implement master-pushed remote configuration.

#### 2026-06-26 - Remote Share Dropdown

- Task completed: Loaded remote share choices from the linked peer for master-side remote mirror setup.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.6.1.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Share Pair now includes Refresh Remote Shares when a linked peer exists. The action calls the peer responder, stores its current share list in the peer profile, and the Remote peer share field becomes a dropdown when shares are available. Saving remote config validates selected remote shares against the linked peer's loaded shares.
- New decisions: Master-side share setup should use peer-provided share lists instead of free-form remote share text when possible.
- Open questions: Confirm Refresh Remote Shares populates the dropdown from the managed remote.
- Next suggested task: Push to GitHub, update both servers to `0.6.1`, refresh remote shares, select local/remote shares, and test saving the remote share pair.

#### 2026-06-26 - Hide Linked Remote Connection Fields

- Task completed: Removed unnecessary remote connection fields from linked-peer remote share setup.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.6.2.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: When setting up Remote LAN mirror, Peer host/IP, SSH user, and SSH port are no longer shown in Share Pair. The form sends hidden values and save-config prefers the linked peer's host automatically.
- New decisions: After pairing, the linked peer is the source of remote connection details; the user should only choose shares and sync rules.
- Open questions: Confirm Remote LAN mirror now only exposes the expected share/rule fields.
- Next suggested task: Push to GitHub, update both servers to `0.6.2`, refresh remote shares, select the local and remote shares, then save the remote share pair.

#### 2026-06-26 - Remote Share Refresh Preserves Remote Mode

- Task completed: Fixed Refresh Remote Shares switching the Share Pair UI back to Local mirror.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.6.3.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Added a helper to apply the linked peer to config as Remote LAN mirror. Use Linked Peer and Refresh Remote Shares both call it, so refreshing remote shares keeps remote mode selected and uses the linked peer host automatically.
- New decisions: Actions that prepare remote share setup should persist Remote LAN mirror mode immediately.
- Open questions: Confirm Refresh Remote Shares now populates the dropdown and leaves Remote LAN mirror selected.
- Next suggested task: Push to GitHub, update both servers to `0.6.3`, click Refresh Remote Shares, select the local and remote shares, then save settings.

#### 2026-06-26 - Save Settings Full Submit

- Task completed: Prevented Save Settings from getting stuck grayed out during long save/restart operations.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.6.4.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Save Settings now uses a normal page submit instead of the AJAX panel-submit path, so the browser does not leave the button disabled while the backend saves config and restarts services.
- New decisions: Long-running configuration saves should use full submit behavior for reliability.
- Open questions: Confirm Save Settings completes and reloads normally on Unraid after updating to `0.6.4`.
- Next suggested task: Push to GitHub, update both servers to `0.6.4`, save the remote share pair, then implement master-pushed remote configuration.

#### 2026-06-26 - Remove Manual SSH Key UI

- Task completed: Removed the manual SSH key setup and accept-key sections from the Mirror page.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.6.5.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: LAN peer setup now stays focused on direct peer IP invite, accepted pairing, remote share refresh, and share selection. The old Generate SSH Key, Test Peer, Accept Peer Key, public-key textarea, and accepted-key counter are no longer shown.
- New decisions: Pairing internals should stay behind the invite flow instead of requiring manual key management in the UI.
- Open questions: Confirm both servers show the cleaner setup page after updating to `0.6.5`.
- Next suggested task: Push to GitHub, update both servers to `0.6.5`, refresh remote shares, and save the remote share pair.

#### 2026-06-26 - Remote Daemon Startup Fix

- Task completed: Fixed remote-share daemon startup after removing the manual SSH key workflow.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.6.6.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_runner.php`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The remote sync runner now auto-creates the internal transfer key if missing, and `mirrorctl start/restart` verifies the daemon stays alive before reporting success. If remote sync exits immediately, the action output includes the recent daemon log.
- New decisions: Hidden pairing/transfer setup should self-heal where possible, and daemon start should fail loudly when remote sync cannot connect.
- Open questions: Confirm whether `0.6.6` starts remote sync or reports a useful connection/permission error.
- Next suggested task: Push to GitHub, update both servers to `0.6.6`, start the daemon with a remote share selected, and inspect the returned log if it still fails.

#### 2026-06-26 - Remote Daemon Error Retry

- Task completed: Kept the daemon alive when remote sync hits a connection or transfer error.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.6.7.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_runner.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Daemon mode now catches per-interval sync errors, writes them to the daemon log, flushes output, sleeps, and retries. `Run Once` still returns errors directly for manual testing.
- New decisions: Remote connection errors should not stop the background daemon; they should be visible in Recent Log while the daemon keeps retrying.
- Open questions: Confirm whether `0.6.7` shows the daemon as Running and logs the actual remote transfer error.
- Next suggested task: Push to GitHub, update both servers to `0.6.7`, start the daemon, then read Recent Log for the next remote-sync error if files still do not copy.

#### 2026-06-26 - Relax Daemon Start Gate

- Task completed: Removed the immediate daemon-start health gate from `mirrorctl`.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.6.8.txz`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Start and restart now report the launched daemon PID without failing the action during the first half-second. Remote sync errors are expected to appear in Recent Log from the daemon retry loop.
- New decisions: During remote prototype work, startup should not be blocked by first-sync connectivity checks.
- Open questions: Confirm whether `0.6.8` changes the Daemon tile to Running after Start.
- Next suggested task: Push to GitHub, update both servers to `0.6.8`, click Start, then inspect Recent Log for any sync error.

#### 2026-06-26 - Shell Daemon Retry Loop

- Task completed: Moved the long-running daemon process into the shell wrapper.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.6.9.txz`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: `mirrorctl start` and `restart` now launch a hidden `daemon-loop` action. The shell loop stays alive, calls PHP `run-once` each interval, logs non-zero exits, and retries.
- New decisions: The service PID should belong to a resilient wrapper while the remote sync path is still unstable.
- Open questions: Confirm whether `0.6.9` finally shows Daemon as Running and logs `run-once` errors instead of stopping.
- Next suggested task: Push to GitHub, update both servers to `0.6.9`, click Start, and check Recent Log.

#### 2026-06-26 - Daemon Launch Diagnostics

- Task completed: Hardened daemon launch and added startup diagnostics.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.7.0.txz`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: `mirrorctl start` and `restart` now launch `/usr/local/sbin/mirrorctl daemon-loop` by absolute path, write start/restart markers to `/var/log/mirror.log`, write a daemon-loop boot marker, and return the recent log tail if the wrapper itself fails to launch.
- New decisions: Move from `0.6.9` to `0.7.0` to avoid possible Unraid version comparison trouble with two-digit patch numbers.
- Open questions: If daemon still does not start, the Start action output should now include the exact launch failure or recent log tail.
- Next suggested task: Push to GitHub, update both servers to `0.7.0`, click Start, and capture the action output if it still says failed.

#### 2026-06-26 - Control Actions Full Submit

- Task completed: Moved Control actions out of the AJAX refresh path.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.7.1.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The Status, Run Once, Start, and Stop form now uses normal page submit, matching the Save Settings and pairing actions.
- New decisions: Long-running or service-control actions should not use AJAX while the remote prototype is unstable.
- Open questions: Confirm whether Start now returns a clear action box instead of staying stuck.
- Next suggested task: Push to GitHub, update both servers to `0.7.1`, click Start on the Master, and capture the action output if it still fails.

#### 2026-06-26 - Automatic SSH Peer Setup

- Task completed: Made linked peer SSH setup happen in the background.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.7.2.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_pairing_server.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_runner.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The pairing responder now has an `ensure-ssh` endpoint that accepts the linked peer's transfer key and starts Unraid's SSH daemon. The master runner calls that endpoint before every first SSH transfer attempt to repair already-linked peers automatically.
- New decisions: SSH should be background plumbing after linking, not a user-managed setup step.
- Open questions: Confirm whether the next run changes the error from connection refused to either success or a more specific SSH authentication/path issue.
- Next suggested task: Push to GitHub, update both servers to `0.7.2`, click Start on the Master, and check Recent Log.

#### 2026-06-26 - Peer SSH Setup Fallback

- Task completed: Made automatic peer SSH setup tolerate empty or invalid POST responses.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.7.3.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_runner.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: The master now retries `ensure-ssh` using query parameters if the JSON POST path returns invalid or empty JSON. Invalid response errors now include HTTP status, byte count, and a response snippet.
- New decisions: Peer setup calls should use the same fallback pattern as direct invites while the responder is still changing quickly.
- Open questions: Confirm whether `0.7.3` gets past `peer setup returned invalid JSON` or reports a clearer peer setup error.
- Next suggested task: Push to GitHub, update both servers to `0.7.3`, click Start on the Master, and check Recent Log.

#### 2026-06-26 - Harden Peer SSHD Startup

- Task completed: Made automatic peer SSH service startup more robust and diagnosable.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.7.4.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_pairing_server.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Peer SSH setup now checks for an active SSH listener, generates host keys with `ssh-keygen -A`, tries Unraid's `rc.sshd`, tries direct `sshd`, and reports each command's exit code/output if port 22 still is not listening.
- New decisions: Automatic SSH setup should expose detailed startup diagnostics instead of a generic failure.
- Open questions: Confirm whether `0.7.4` starts SSH on the remote or reports the exact blocker.
- Next suggested task: Push to GitHub, update both servers to `0.7.4`, click Start on the Master, and check Recent Log.

#### 2026-06-26 - Master Controls Remote Daemon

- Task completed: Master Start and Stop now send the same daemon control command to the linked remote.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.7.5.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_pairing_server.php`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Added a responder `control` action with a small command allowlist and taught the master action path to call it after local Start/Stop.
- New decisions: Master should orchestrate remote daemon start/stop for linked managed remotes.
- Open questions: Confirm remote Start/Stop action output appears in the Master action result.
- Next suggested task: Push to GitHub, update both servers to `0.7.5`, click Start/Stop on the Master, and check both daemon tiles/logs.

#### 2026-06-26 - Verify Remote Stop

- Task completed: Hardened daemon stop and made remote Stop results visible.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.7.6.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_pairing_server.php`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: `mirrorctl stop` now verifies the daemon pid exits, force-stops lingering daemon/child processes if needed, and remote control responses include status after the command. The remote peer also records a visible action message when it receives Start or Stop from the master.
- New decisions: Remote daemon control needs explicit post-command status in the UI while testing.
- Open questions: Confirm Master Stop shows the linked peer as stopped on both servers.
- Next suggested task: Push to GitHub, update both servers to `0.7.6`, stop from the Master, and read the action box on both servers.

#### 2026-06-26 - Stop Scan-Created Shares

- Task completed: Prevented Mirror from recreating deleted share roots during scan/start.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.7.7.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_runner.php`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Local and remote scans now return an empty file list when the configured share root is missing instead of creating it. Managed remote servers also skip the sync daemon start so stale/default local config cannot recreate a master-named share.
- New decisions: Scanning must be read-only. Only an actual copy operation should create target directories.
- Open questions: Confirm deleting `mirror-share-a` on Server B no longer comes back after Start/Stop or Status.
- Next suggested task: Push to GitHub, update both servers to `0.7.7`, delete the mistaken test share on Server B, then start from the Master and confirm it stays deleted unless it is the selected remote target.

#### 2026-06-26 - Large Share Initial Sync And Tabs

- Task completed: Added a background Initial Sync path for large shares and reorganized the Mirror page into tabs.
- Files changed: `README.md`, `VERSION`, `mirror.plg`, `packages/mirror-0.8.0.txz`, `plugin/source/usr/local/emhttp/plugins/mirror/Mirror.page`, `plugin/source/usr/local/emhttp/plugins/mirror/include/action.php`, `plugin/source/usr/local/emhttp/plugins/mirror/scripts/mirror_runner.php`, `plugin/source/usr/local/sbin/mirrorctl`, `tools/build-unraid-plugin.sh`, `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Added `initial-sync` to the runner and `mirrorctl`, using rsync as a background baseline copy from the master share to the selected target share. The UI now has Status/Control, LAN Setup, Shares, Config, and Log tabs.
- New decisions: Very large shares should use an explicit baseline sync before relying on normal incremental daemon behavior.
- Open questions: Confirm Initial Sync starts cleanly and writes progress to Recent Log on Unraid.
- Next suggested task: Push to GitHub, update both servers to `0.8.0`, save the share pair, click Initial Sync on the master, and watch the Log tab.

#### 2026-06-25 - Local Unraid Share Sync Confirmed

- Task completed: Confirmed the plugin can sync two selected local Unraid shares.
- Files changed: `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 4 - Unraid Plugin Wrapper.
- What changed: Recorded successful user test that the two selected shares sync.
- New decisions: The local same-server share sync path is working enough to use for disposable test shares.
- Open questions: Superseded by the first LAN peer prototype in `0.1.0`.
- Next suggested task: Test the LAN peer prototype on two disposable shares.

#### 2026-06-26 - Chat Handoff Added

- Task completed: Added a handoff section so the project can continue cleanly in a new chat.
- Files changed: `unraid-lan-mirror-plugin/NOTES.md`
- Current phase: Phase 3 - Two-Server LAN Prototype.
- What changed: Recorded current repo/version/install state, product direction, working pieces, known weak spots, recommended next implementation, and test flow.
- New decisions: Managed remote is treated as a receiver UI mode until master-controlled remote configuration is implemented.
- Open questions: The master-to-managed-remote configuration API still needs to be designed and built.
- Next suggested task: Push local commits to GitHub, update both Unraid servers to `0.8.0`, save the share pair, click Initial Sync on the master, and watch the Log tab.
