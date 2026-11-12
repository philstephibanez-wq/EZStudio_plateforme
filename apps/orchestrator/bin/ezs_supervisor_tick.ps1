[CmdletBinding()]
param([switch]$DevOnly, [switch]$RootOnly)
$ErrorActionPreference = 'Stop'
# Historical Caddy/PHP-CGI entrypoint removed. The native FrankenPHP
# lifecycle is owned by the dedicated ROOT watchdog and native-site controllers.
# Refuse any stale Windows task rather than report false successful supervision.
throw 'EZS_LEGACY_SUPERVISOR_REMOVED_USE_NATIVE_FRANKENPHP'
