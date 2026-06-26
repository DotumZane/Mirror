# Mirror

Planning repository for an Unraid LAN mirror plugin.

The goal is an Unraid plugin installed on two servers that can pair over the local network, watch selected shares, and keep them mirrored with true two-way sync, trash/version retention, conflict protection, and fast event-driven transfers.

Current status: planning and specification.

Current version: `0.8.0`

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

If Unraid reports an older/same version after a fresh push, use a cache-busted install URL:

```text
https://raw.githubusercontent.com/DotumZane/Mirror/main/mirror.plg?mirror_cache_bust=1
```

Or install from an Unraid terminal:

```sh
installplg https://raw.githubusercontent.com/DotumZane/Mirror/main/mirror.plg
```

After install, open:

```text
Settings -> User Utilities -> Mirror
```

The current test page lets you set:

- Server A share from a dropdown of current shares
- Server B as either a local share dropdown or a remote LAN peer share
- Server A preferred or equal peer mode
- explicit delete behavior: restore missing files or mirror deletes
- daemon scan interval

## LAN Peer Prototype

Install the plugin on both Unraid servers, enter the other server's LAN IP in
LAN Peer Setup, send an invite, then accept it on the peer. After the peer is
linked, use **Refresh Remote Shares** and choose the remote share from the
loaded dropdown.

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
