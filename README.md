# Mirror

Planning repository for an Unraid LAN mirror plugin.

The goal is an Unraid plugin installed on two servers that can pair over the local network, watch selected shares, and keep them mirrored with true two-way sync, trash/version retention, conflict protection, and fast event-driven transfers.

Current status: planning and specification.

Current version: `0.0.2`

## Test Install On Unraid

This is the first installable scaffold. It is still an early test build and
should only be used with disposable test shares.

Install from the Unraid web UI:

```text
Plugins -> Install Plugin
```

Paste this URL:

```text
https://raw.githubusercontent.com/DotumZane/Mirror/main/mirror.plg
```

Or install from an Unraid terminal:

```sh
installplg https://raw.githubusercontent.com/DotumZane/Mirror/main/mirror.plg
```

After install, open:

```text
Settings -> User Utilities -> Mirror
```

## Local Prototype

Run the current local prototype from this folder:

```sh
python3 -m mirror_app version
python3 -m mirror_app run-once --config config/dev.local.json
```

The development config uses disposable folders under `sandbox/`:

```text
sandbox/server-a
sandbox/server-b
sandbox/trash
```

Do not point this prototype at real Unraid shares yet.

See [NOTES.md](unraid-lan-mirror-plugin/NOTES.md) for the working project notes, decisions, and tracker.
