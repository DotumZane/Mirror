#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "${ROOT}/VERSION")"
PLUGIN_NAME="mirror"
REPO_OWNER="DotumZane"
REPO_NAME="Mirror"
PACKAGE_NAME="${PLUGIN_NAME}-${VERSION}.txz"
PACKAGE_PATH="${ROOT}/packages/${PACKAGE_NAME}"
SOURCE_DIR="${ROOT}/plugin/source"
EMHTTP_DIR="${SOURCE_DIR}/usr/local/emhttp/plugins/${PLUGIN_NAME}"

mkdir -p "${ROOT}/packages" "${EMHTTP_DIR}"

rm -rf "${EMHTTP_DIR}/mirror_app"
cp "${ROOT}/VERSION" "${EMHTTP_DIR}/VERSION"

chmod +x "${SOURCE_DIR}/usr/local/sbin/mirrorctl"

rm -f "${PACKAGE_PATH}"
(
  cd "${SOURCE_DIR}"
  tar --owner=0 --group=0 -cJf "${PACKAGE_PATH}" .
)

MD5="$(md5 -q "${PACKAGE_PATH}" 2>/dev/null || md5sum "${PACKAGE_PATH}" | awk '{print $1}')"
RAW_BASE="https://raw.githubusercontent.com/${REPO_OWNER}/${REPO_NAME}/main"

cat > "${ROOT}/mirror.plg" <<PLG
<?xml version="1.0" standalone="yes"?>
<!DOCTYPE PLUGIN [
  <!ENTITY name       "${PLUGIN_NAME}">
  <!ENTITY author     "${REPO_OWNER}">
  <!ENTITY version    "${VERSION}">
  <!ENTITY plugin     "/boot/config/plugins/&name;">
  <!ENTITY emhttp     "/usr/local/emhttp/plugins/&name;">
  <!ENTITY packageURL "${RAW_BASE}/packages/${PACKAGE_NAME}">
  <!ENTITY pluginURL  "${RAW_BASE}/mirror.plg">
  <!ENTITY md5        "${MD5}">
]>
<PLUGIN name="&name;" author="&author;" version="&version;"
        launch="Settings/Mirror" pluginURL="&pluginURL;"
        min="6.12.0" support="https://github.com/${REPO_OWNER}/${REPO_NAME}">
  <CHANGES>
### ${VERSION}
- Allows Remote LAN mirror mode to be saved before peer details are complete.
- Keeps the Local/Remote switch from snapping back after saving draft settings.
- Not safe for important shares yet.
  </CHANGES>

  <FILE Run="/bin/bash">
    <INLINE>
      mkdir -p &plugin;
      find &plugin; -maxdepth 1 -type f -name '&name;-*.txz' ! -name '&name;-&version;.txz' -delete 2>/dev/null || true
    </INLINE>
  </FILE>

  <FILE Name="/boot/config/plugins/&name;/&name;-&version;.txz" Run="upgradepkg --install-new">
    <URL>&packageURL;</URL>
    <MD5>&md5;</MD5>
  </FILE>

  <FILE Run="/bin/bash">
    <INLINE>
      mkdir -p &plugin;
      chmod +x /usr/local/sbin/mirrorctl 2>/dev/null || true;
      if [ ! -f &plugin;/config.json ]; then
        cp &emhttp;/default-config.json &plugin;/config.json;
      fi;
      if [ -f &emhttp;/Mirror.page ] &amp;&amp; [ -x /usr/local/sbin/mirrorctl ]; then
        echo "";
        echo "===================================================================";
        echo "  Mirror &version; installed successfully.";
        echo "";
        echo "  Open it at: Settings - User Utilities - Mirror";
        echo "  This is an early test build. Use disposable test shares only.";
        echo "===================================================================";
        echo "";
      else
        echo "Mirror install failed: expected files are missing.";
        exit 1;
      fi;
    </INLINE>
  </FILE>

  <FILE Run="/bin/bash" Method="remove">
    <INLINE>
      /usr/local/sbin/mirrorctl stop 2>/dev/null || true;
      removepkg &name;-&version; 2>/dev/null || true;
      rm -rf &emhttp;;
      rm -f /usr/local/sbin/mirrorctl;
      echo "Mirror uninstalled. Persistent config remains at &plugin;.";
    </INLINE>
  </FILE>
</PLUGIN>
PLG

echo "Built ${PACKAGE_PATH}"
echo "MD5 ${MD5}"
echo "Generated ${ROOT}/mirror.plg"
