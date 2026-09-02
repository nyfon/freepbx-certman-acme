<?php
/**
 * ACME Certificate Manager - Uninstall Script
 *
 * Removes cron jobs. Does NOT remove issued certificates or acme.sh data
 * to avoid accidentally breaking active TLS configurations.
 */

// Remove cron entries
foreach (FreePBX::Cron()->getAll() as $cron) {
	if (preg_match("/fwconsole certacme/i", $cron)) {
		FreePBX::Cron()->remove($cron);
	}
}

// Remove notifications
FreePBX::Notifications()->delete('certmanacme', 'ACME_DOWNLOAD_FAILED');

// Remove any local signature left behind, including the one the 17.0.2/17.0.3
// pre-signed packaging installed. Harmless if absent.
foreach ([__DIR__ . '/module.sig', '/etc/freepbx.secure/certmanacme.sig'] as $sigFile) {
	if (file_exists($sigFile)) {
		@unlink($sigFile);
	}
}
