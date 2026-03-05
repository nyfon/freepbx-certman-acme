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

	// Deploy pre-signed module signature
	$secureDir = '/etc/freepbx.secure';
	$moduleDir = __DIR__;
	$sigSource = $moduleDir . '/signing/certmanacme.sig';
	$keySource = $moduleDir . '/signing/signing-key.pub';

	if (file_exists($sigSource)) {
		if (!is_dir($secureDir)) {
			@mkdir($secureDir, 0755, true);
		}

		// Deploy the secure sig file (must be root-owned)
		@copy($sigSource, $secureDir . '/certmanacme.sig');
		@chmod($secureDir . '/certmanacme.sig', 0644);
		@chown($secureDir, 0);
		@chgrp($secureDir, 0);
		@chown($secureDir . '/certmanacme.sig', 0);
		@chgrp($secureDir . '/certmanacme.sig', 0);
	}

	// Import signing GPG key into FreePBX keyring
	// fwconsole runs as root, so we import directly then fix ownership
	if (file_exists($keySource)) {
		$webuser = FreePBX::Config()->get('AMPASTERISKWEBUSER');
		if ($webuser) {
			$web = posix_getpwnam($webuser);
			if ($web) {
				$gpgHome = rtrim($web['dir'], '/') . '/.gnupg';
				if (!is_dir($gpgHome)) {
					@mkdir($gpgHome, 0700, true);
				}

				// Import public key into the web user's GPG keyring
				exec(sprintf(
					'gpg --homedir %s --batch --import %s 2>&1',
					escapeshellarg($gpgHome),
					escapeshellarg($keySource)
				));

				// Extract fingerprint and set ultimate trust
				$showOut = [];
				exec(sprintf(
					'gpg --batch --with-colons --show-keys %s 2>/dev/null',
					escapeshellarg($keySource)
				), $showOut);

				foreach ($showOut as $line) {
					if (strpos($line, 'fpr:') === 0) {
						$fpr = explode(':', $line)[9] ?? '';
						if ($fpr) {
							// Write trust to a temp file and import it
							$trustFile = tempnam(sys_get_temp_dir(), 'gpg-trust-');
							file_put_contents($trustFile, $fpr . ":6:\n");
							exec(sprintf(
								'gpg --homedir %s --batch --import-ownertrust %s 2>&1',
								escapeshellarg($gpgHome),
								escapeshellarg($trustFile)
							));
							@unlink($trustFile);
						}
						break;
					}
				}

				// Fix ownership of the entire GPG directory
				exec(sprintf(
					'chown -R %s:%s %s 2>&1',
					escapeshellarg($webuser),
					escapeshellarg($web['gid']),
					escapeshellarg($gpgHome)
				));
			}
		}
	}

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
