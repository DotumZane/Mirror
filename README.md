# Mirror

Planning repository for an Unraid LAN mirror plugin.

The goal is an Unraid plugin installed on two servers that can pair over the local network, watch selected shares, and keep them mirrored with true two-way sync, trash/version retention, conflict protection, and fast event-driven transfers.

Current status: planning and specification.

Current version: `0.0.1`

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
