<?php
/**
 * ACME Certificate Manager - Installation Script
 *
 * Downloads acme.sh on install and registers a renewal cron job.
 * Wrapped in try/catch to ensure module installation always completes.
 */

try {
	$acmeDataDir = '/var/lib/asterisk/certman_acme';
	$acmeScriptDir = $acmeDataDir . '/acme.sh';
	$acmeHomeDir = $acmeDataDir . '/data';

	// Create working directories
	foreach ([$acmeDataDir, $acmeScriptDir, $acmeHomeDir] as $dir) {
		if (!is_dir($dir)) {
			@mkdir($dir, 0750, true);
		}
	}

	// Download acme.sh if not already present
	$acmeScript = $acmeScriptDir . '/acme.sh';
	if (!file_exists($acmeScript)) {
		$tarball = $acmeDataDir . '/acme.sh.tar.gz';
		$downloadCmd = sprintf(
			'curl -sSL -o %s https://github.com/acmesh-official/acme.sh/archive/refs/heads/master.tar.gz 2>&1',
			escapeshellarg($tarball)
		);
		$output = [];
		$exitCode = -1;
		exec($downloadCmd, $output, $exitCode);

		if ($exitCode === 0 && file_exists($tarball)) {
			exec(sprintf(
				'tar -xzf %s -C %s --strip-components=1 2>&1',
				escapeshellarg($tarball),
				escapeshellarg($acmeScriptDir)
			), $output, $exitCode);
			@unlink($tarball);

			if (file_exists($acmeScript)) {
				chmod($acmeScript, 0750);
			}
		}
	}

	// Set up renewal cron job
	$ampsbin = FreePBX::Config()->get("AMPSBIN");

	foreach (FreePBX::Cron()->getAll() as $cron) {
		if (preg_match("/fwconsole certacme/i", $cron)) {
			FreePBX::Cron()->remove($cron);
		}
	}

	FreePBX::Cron()->add(array(
		"command" => $ampsbin . "/fwconsole certacme --renew -q 2>&1 >/dev/null",
		"hour" => rand(1, 4),
		"minute" => rand(0, 59),
	));

	// Store default settings
	$pdo = FreePBX::Database();
	$defaults = [
		'acme_script_dir' => $acmeScriptDir,
		'acme_home_dir' => $acmeHomeDir,
		'default_ca' => 'letsencrypt',
		'email' => '',
	];

	$stmt = $pdo->prepare("INSERT IGNORE INTO certman_acme_settings (`key`, `value`) VALUES (:key, :value)");
	foreach ($defaults as $key => $value) {
		$stmt->execute([':key' => $key, ':value' => $value]);
	}
} catch (Exception $e) {
	// Log but don't fail the install
	freepbx_log(FPBX_LOG_ERROR, "certmanacme install warning: " . $e->getMessage());
}
