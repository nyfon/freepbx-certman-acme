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

	// --- Migration off the 17.0.2/17.0.3 pre-signed packaging -----------------
	//
	// Those releases shipped a module.sig (a type=local *pointer*) and installed a
	// hash list into /etc/freepbx.secure. An in-place upgrade leaves module.sig
	// behind, and it still covers the OLD files -- so FreePBX re-hashes the new
	// ones, they do not match, and Module Admin reports
	// "Module has been tampered. Please redownload".
	//
	// Any pre-existing signature is stale by definition at this point: install.php
	// only runs on install/upgrade, which is exactly when the files change. Remove
	// the pair so the module reads cleanly as unsigned. Admins who want a signature
	// local-sign the box after installing (see README).
	$staleSigs = [
		__DIR__ . '/module.sig',
		'/etc/freepbx.secure/certmanacme.sig',
	];
	foreach ($staleSigs as $staleSig) {
		if (file_exists($staleSig)) {
			@unlink($staleSig);
		}
	}

	// 17.0.2/17.0.3 also gave the bundled signing key *ultimate* ownertrust, which
	// is what broke verification in the first place: a trusted key sends
	// GPG::checkSig() down a branch that omits 'parsedout', so verifyModule() never
	// recognises a type=local signature. Undo our own side effect so a later local
	// signing on this box works. Non-fatal -- the key may already be gone.
	$oldKeyFpr = '2DF52C9E1CA424385850B1FE5D2C91139077FDD0';
	$webuser = FreePBX::Config()->get('AMPASTERISKWEBUSER');
	if ($webuser && ($web = posix_getpwnam($webuser))) {
		$gpgHome = rtrim($web['dir'], '/') . '/.gnupg';
		$listed = [];
		exec(sprintf(
			'gpg --homedir %s --batch --list-keys %s 2>/dev/null',
			escapeshellarg($gpgHome),
			escapeshellarg($oldKeyFpr)
		), $listed, $keyPresent);

		if ($keyPresent === 0) {
			$trustFile = tempnam(sys_get_temp_dir(), 'gpg-trust-');
			file_put_contents($trustFile, $oldKeyFpr . ":2:\n");
			exec(sprintf(
				'sudo -u %s gpg --homedir %s --batch --import-ownertrust %s 2>&1',
				escapeshellarg($webuser),
				escapeshellarg($gpgHome),
				escapeshellarg($trustFile)
			));
			@unlink($trustFile);
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
