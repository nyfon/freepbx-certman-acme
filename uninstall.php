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

// Remove local secure signature
$sigFile = '/etc/freepbx.secure/certmanacme.sig';
if (file_exists($sigFile)) {
	@unlink($sigFile);
}
