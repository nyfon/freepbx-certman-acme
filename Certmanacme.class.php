<?php
/**
 * ACME Certificate Manager - Main BMO Class
 *
 * Wraps acme.sh to provide DNS-01 ACME certificate management.
 * Certificates are deployed into the standard FreePBX/certman layout
 * and registered in certman_certs for native integration.
 */

namespace FreePBX\modules;

class Certmanacme implements \BMO
{
	private $FreePBX;
	private $db;

	/** @var string Path to acme.sh script directory */
	private $acmeScriptDir;

	/** @var string Path to acme.sh home/data directory */
	private $acmeHomeDir;

	/** CA server aliases */
	private const CA_SERVERS = [
		'letsencrypt' => 'https://acme-v02.api.letsencrypt.org/directory',
		'letsencrypt_staging' => 'https://acme-staging-v02.api.letsencrypt.org/directory',
		'zerossl' => 'https://acme.zerossl.com/v2/DV90',
		'buypass' => 'https://api.buypass.com/acme/directory',
		'buypass_staging' => 'https://api.buypass.no/acme-v02/directory',
		'google' => 'https://dv.acme-v02.api.pki.goog/directory',
		'googletrust' => 'https://dv.acme-v02.api.pki.goog/directory',
	];

	public function __construct($freepbx = null)
	{
		if ($freepbx === null) {
			return;
		}
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
	}

	// ── BMO Interface ────────────────────────────────────────────────

	public function install()
	{
		// Handled by install.php
	}

	public function uninstall()
	{
		// Handled by uninstall.php
	}

	public function backup()
	{
	}

	public function restore($backup)
	{
	}

	public function doConfigPageInit($page)
	{
	}

	// ── Settings ─────────────────────────────────────────────────────

	/**
	 * Get a module setting.
	 */
	public function getSetting(string $key, $default = null): ?string
	{
		$stmt = $this->db->prepare("SELECT `value` FROM certman_acme_settings WHERE `key` = :key");
		$stmt->execute([':key' => $key]);
		$val = $stmt->fetchColumn();
		return $val !== false ? $val : $default;
	}

	/**
	 * Set a module setting.
	 */
	public function setSetting(string $key, ?string $value): void
	{
		$stmt = $this->db->prepare(
			"INSERT INTO certman_acme_settings (`key`, `value`) VALUES (:key, :value)
			 ON DUPLICATE KEY UPDATE `value` = :value2"
		);
		$stmt->execute([':key' => $key, ':value' => $value, ':value2' => $value]);
	}

	/**
	 * Get the acme.sh script path.
	 */
	private function getAcmeScript(): string
	{
		if ($this->acmeScriptDir === null) {
			$this->acmeScriptDir = $this->getSetting('acme_script_dir', '/var/lib/asterisk/certman_acme/acme.sh');
		}
		return $this->acmeScriptDir . '/acme.sh';
	}

	/**
	 * Get the acme.sh home directory.
	 */
	private function getAcmeHome(): string
	{
		if ($this->acmeHomeDir === null) {
			$this->acmeHomeDir = $this->getSetting('acme_home_dir', '/var/lib/asterisk/certman_acme/data');
		}
		return $this->acmeHomeDir;
	}

	/**
	 * Resolve a CA server alias to its URL.
	 */
	private function resolveCA(string $ca): string
	{
		$ca = strtolower(trim($ca));
		if (isset(self::CA_SERVERS[$ca])) {
			return self::CA_SERVERS[$ca];
		}
		// Assume it's a direct URL
		if (filter_var($ca, FILTER_VALIDATE_URL)) {
			return $ca;
		}
		return self::CA_SERVERS['letsencrypt'];
	}

	// ── acme.sh Management ───────────────────────────────────────────

	/**
	 * Download or update acme.sh from GitHub.
	 */
	public function updateAcme(): array
	{
		$scriptDir = dirname($this->getAcmeScript());
		if (!is_dir($scriptDir)) {
			mkdir($scriptDir, 0750, true);
		}

		$tarball = $scriptDir . '/acme.sh.tar.gz';
		$downloadCmd = sprintf(
			'curl -sSL -o %s https://github.com/acmesh-official/acme.sh/archive/refs/heads/master.tar.gz 2>&1',
			escapeshellarg($tarball)
		);

		$output = [];
		$exitCode = -1;
		exec($downloadCmd, $output, $exitCode);

		if ($exitCode !== 0 || !file_exists($tarball)) {
			return ['success' => false, 'message' => 'Failed to download acme.sh: ' . implode("\n", $output)];
		}

		// Extract, overwriting existing files
		$extractCmd = sprintf(
			'tar -xzf %s -C %s --strip-components=1 2>&1',
			escapeshellarg($tarball),
			escapeshellarg($scriptDir)
		);
		exec($extractCmd, $output, $exitCode);
		@unlink($tarball);

		$script = $scriptDir . '/acme.sh';
		if (!file_exists($script)) {
			return ['success' => false, 'message' => 'Extraction failed: acme.sh not found after extract.'];
		}

		chmod($script, 0750);

		// Clear notification if one existed
		\FreePBX::Notifications()->delete('certmanacme', 'ACME_DOWNLOAD_FAILED');

		return ['success' => true, 'message' => 'acme.sh updated successfully.'];
	}

	// ── Provider Discovery ───────────────────────────────────────────

	/**
	 * Discover all available DNS providers from acme.sh dnsapi/ scripts.
	 *
	 * Parses the dns_*_info variable from each script to extract:
	 * - Display name
	 * - Site URL
	 * - Docs URL
	 * - Required options (env vars)
	 * - Optional options (env vars)
	 *
	 * @return array Keyed by provider ID (e.g., "dns_hetznercloud")
	 */
	public function getProviders(): array
	{
		$dnsapiDir = dirname($this->getAcmeScript()) . '/dnsapi';
		if (!is_dir($dnsapiDir)) {
			return [];
		}

		$providers = [];
		$files = glob($dnsapiDir . '/dns_*.sh');

		foreach ($files as $file) {
			$basename = basename($file, '.sh');
			$content = file_get_contents($file);
			if ($content === false) {
				continue;
			}

			$provider = $this->parseProviderInfo($basename, $content);
			if ($provider !== null) {
				$providers[$basename] = $provider;
			}
		}

		// Sort by display name
		uasort($providers, function ($a, $b) {
			return strcasecmp($a['name'], $b['name']);
		});

		return $providers;
	}

	/**
	 * Parse a single DNS provider script for its info block.
	 */
	private function parseProviderInfo(string $id, string $content): ?array
	{
		// Match the info variable: dns_*_info='...'
		$funcName = $id;
		$pattern = '/' . preg_quote($funcName, '/') . '_info=\'(.*?)\'/s';
		if (!preg_match($pattern, $content, $m)) {
			// Fallback: try double quotes
			$pattern = '/' . preg_quote($funcName, '/') . '_info="(.*?)"/s';
			if (!preg_match($pattern, $content, $m)) {
				return [
					'id' => $id,
					'name' => $id,
					'site' => '',
					'docs' => '',
					'options' => [],
					'optional' => [],
				];
			}
		}

		$info = $m[1];
		$lines = explode("\n", $info);

		$name = trim($lines[0] ?? $id);
		$site = '';
		$docs = '';
		$options = [];
		$optional = [];
		$currentSection = 'options'; // 'options' or 'optional'

		for ($i = 1; $i < count($lines); $i++) {
			$line = trim($lines[$i]);
			if ($line === '') {
				continue;
			}

			if (preg_match('/^Site:\s*(.+)$/i', $line, $sm)) {
				$site = trim($sm[1]);
			} elseif (preg_match('/^Docs:\s*(.+)$/i', $line, $sm)) {
				$docs = trim($sm[1]);
			} elseif (preg_match('/^Options:\s*$/i', $line)) {
				$currentSection = 'options';
			} elseif (preg_match('/^Optional:\s*$/i', $line)) {
				$currentSection = 'optional';
			} elseif (preg_match('/^Issues:/i', $line)) {
				// Skip issues line
			} elseif (preg_match('/^\s*(\w+)\s+(.+)$/', $line, $sm)) {
				$envVar = trim($sm[1]);
				$desc = trim($sm[2]);
				if ($currentSection === 'optional') {
					$optional[$envVar] = $desc;
				} else {
					$options[$envVar] = $desc;
				}
			}
		}

		return [
			'id' => $id,
			'name' => $name,
			'site' => $site,
			'docs' => $docs,
			'options' => $options,
			'optional' => $optional,
		];
	}

	// ── Certificate CRUD ─────────────────────────────────────────────

	/**
	 * Get all ACME-managed certificates.
	 */
	public function getCertificates(): array
	{
		$stmt = $this->db->query("SELECT * FROM certman_acme_certs ORDER BY domain");
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	/**
	 * Get a single certificate by ID.
	 */
	public function getCertificate(int $id): ?array
	{
		$stmt = $this->db->prepare("SELECT * FROM certman_acme_certs WHERE id = :id");
		$stmt->execute([':id' => $id]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	/**
	 * Get a certificate by domain name.
	 */
	public function getCertificateByDomain(string $domain): ?array
	{
		$stmt = $this->db->prepare("SELECT * FROM certman_acme_certs WHERE domain = :domain");
		$stmt->execute([':domain' => strtolower(trim($domain))]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	// ── Certificate Issuance ─────────────────────────────────────────

	/**
	 * Issue a new certificate via acme.sh DNS-01 challenge.
	 *
	 * @param string $domain       Primary domain
	 * @param string $dnsProvider  DNS provider ID (e.g., "dns_hetznercloud")
	 * @param array  $providerEnv  Provider environment variables (e.g., ["HETZNER_TOKEN" => "xxx"])
	 * @param array  $san          Subject Alternative Names
	 * @param string $caServer     CA server alias or URL
	 * @return array               Result with 'success', 'message', 'cert_id'
	 */
	public function issueCertificate(
		string $domain,
		string $dnsProvider,
		array $providerEnv = [],
		array $san = [],
		string $caServer = 'letsencrypt'
	): array {
		$domain = strtolower(trim($domain));

		// Check for existing cert
		$existing = $this->getCertificateByDomain($domain);
		if ($existing && $existing['status'] === 'active') {
			return ['success' => false, 'message' => sprintf("Certificate for '%s' already exists. Use renew or delete it first.", $domain)];
		}

		// Build acme.sh command
		$args = [
			'--issue',
			'--dns', escapeshellarg($dnsProvider),
			'-d', escapeshellarg($domain),
		];

		foreach ($san as $altName) {
			$altName = strtolower(trim($altName));
			if ($altName && $altName !== $domain) {
				$args[] = '-d';
				$args[] = escapeshellarg($altName);
			}
		}

		$args[] = '--server';
		$args[] = escapeshellarg($this->resolveCA($caServer));

		// Add email if configured
		$email = $this->getSetting('email');
		if ($email) {
			$args[] = '--accountemail';
			$args[] = escapeshellarg($email);
		}

		// Run acme.sh
		$result = $this->runAcme($args, $providerEnv);

		if (!$result['success']) {
			// Store/update the cert record with failed status
			$this->saveCertRecord($domain, $san, $dnsProvider, $providerEnv, $caServer, 'failed', $result['output']);
			return ['success' => false, 'message' => "acme.sh issuance failed:\n" . $result['output']];
		}

		// Deploy to FreePBX
		$deployResult = $this->deployCertificate($domain);
		if (!$deployResult['success']) {
			$this->saveCertRecord($domain, $san, $dnsProvider, $providerEnv, $caServer, 'failed', $deployResult['message']);
			return $deployResult;
		}

		// Read certificate expiry
		$expiresAt = $this->readCertExpiry($domain);

		// Save cert record
		$certId = $this->saveCertRecord(
			$domain, $san, $dnsProvider, $providerEnv, $caServer,
			'active', null, $expiresAt
		);

		return [
			'success' => true,
			'message' => sprintf("Certificate for '%s' issued and deployed successfully.", $domain),
			'cert_id' => $certId,
		];
	}

	/**
	 * Renew a certificate.
	 */
	public function renewCertificate(string $domain, bool $force = false): array
	{
		$cert = $this->getCertificateByDomain($domain);
		if (!$cert) {
			return ['success' => false, 'message' => sprintf("No ACME certificate found for '%s'.", $domain)];
		}

		$providerEnv = json_decode($cert['provider_env'] ?? '{}', true) ?: [];

		$args = [
			'--renew',
			'-d', escapeshellarg($cert['domain']),
		];

		if ($force) {
			$args[] = '--force';
		}

		$result = $this->runAcme($args, $providerEnv);

		// acme.sh returns 2 if cert is not due for renewal (skip)
		if (!$result['success'] && $result['exit_code'] === 2) {
			return ['success' => true, 'message' => sprintf("Certificate for '%s' is not due for renewal.", $domain)];
		}

		if (!$result['success']) {
			$this->updateCertStatus($cert['id'], 'failed', $result['output']);
			return ['success' => false, 'message' => "acme.sh renewal failed:\n" . $result['output']];
		}

		// Re-deploy
		$deployResult = $this->deployCertificate($domain);
		if (!$deployResult['success']) {
			$this->updateCertStatus($cert['id'], 'failed', $deployResult['message']);
			return $deployResult;
		}

		$expiresAt = $this->readCertExpiry($domain);
		$this->updateCertStatus($cert['id'], 'active', null, $expiresAt);

		return [
			'success' => true,
			'message' => sprintf("Certificate for '%s' renewed and deployed successfully.", $domain),
		];
	}

	/**
	 * Renew all certificates that are due.
	 */
	public function renewAll(bool $force = false): array
	{
		$certs = $this->getCertificates();
		$results = [];

		foreach ($certs as $cert) {
			if ($cert['status'] === 'revoked') {
				continue;
			}
			$results[$cert['domain']] = $this->renewCertificate($cert['domain'], $force);
		}

		return $results;
	}

	/**
	 * Revoke a certificate.
	 */
	public function revokeCertificate(string $domain): array
	{
		$cert = $this->getCertificateByDomain($domain);
		if (!$cert) {
			return ['success' => false, 'message' => sprintf("No ACME certificate found for '%s'.", $domain)];
		}

		$args = [
			'--revoke',
			'-d', escapeshellarg($domain),
		];

		$result = $this->runAcme($args);

		if (!$result['success']) {
			return ['success' => false, 'message' => "acme.sh revoke failed:\n" . $result['output']];
		}

		$this->updateCertStatus($cert['id'], 'revoked');
		return ['success' => true, 'message' => sprintf("Certificate for '%s' revoked.", $domain)];
	}

	/**
	 * Delete a certificate record and optionally remove files.
	 */
	public function deleteCertificate(string $domain, bool $removeFiles = false): array
	{
		$cert = $this->getCertificateByDomain($domain);
		if (!$cert) {
			return ['success' => false, 'message' => sprintf("No ACME certificate found for '%s'.", $domain)];
		}

		// Remove from certman if registered
		if (!empty($cert['certman_cid'])) {
			try {
				$certman = \FreePBX::Certman();
				$certman->removeCertificate($cert['certman_cid']);
			} catch (\Exception $e) {
				// Non-fatal: certman record may already be gone
			}
		}

		if ($removeFiles) {
			$args = ['--remove', '-d', escapeshellarg($domain)];
			$this->runAcme($args);
		}

		$stmt = $this->db->prepare("DELETE FROM certman_acme_certs WHERE id = :id");
		$stmt->execute([':id' => $cert['id']]);

		return ['success' => true, 'message' => sprintf("Certificate for '%s' deleted.", $domain)];
	}

	// ── Deployment ───────────────────────────────────────────────────

	/**
	 * Deploy an acme.sh-issued certificate into the FreePBX/certman file layout.
	 *
	 * Copies cert files to /etc/asterisk/keys/{basename}/ and the integration
	 * directory, then registers the cert in certman_certs.
	 */
	public function deployCertificate(string $domain): array
	{
		$acmeHome = $this->getAcmeHome();
		$certDir = $acmeHome . '/' . $domain . '_ecc';

		// acme.sh may use domain/ or domain_ecc/ depending on key type
		if (!is_dir($certDir)) {
			$certDir = $acmeHome . '/' . $domain;
		}
		if (!is_dir($certDir)) {
			return ['success' => false, 'message' => sprintf("acme.sh cert directory not found for '%s'.", $domain)];
		}

		// Locate cert files (acme.sh naming convention)
		$keyFile = $certDir . '/' . $domain . '.key';
		$certFile = $certDir . '/' . $domain . '.cer';
		$caFile = $certDir . '/ca.cer';
		$fullchainFile = $certDir . '/fullchain.cer';

		if (!file_exists($keyFile) || !file_exists($certFile)) {
			return ['success' => false, 'message' => sprintf("Certificate files missing in '%s'.", $certDir)];
		}

		$pkcs = \FreePBX::create()->PKCS;
		$keysDir = $pkcs->getKeysLocation();
		$basename = $domain;

		// Create certman-style directory structure
		$dstDir = $keysDir . '/' . $basename;
		if (!is_dir($dstDir)) {
			mkdir($dstDir, 0750, true);
		}

		$keyContent = file_get_contents($keyFile);
		$certContent = file_get_contents($certFile);
		$chainContent = file_exists($caFile) ? file_get_contents($caFile) : '';
		$fullchainContent = file_exists($fullchainFile) ? file_get_contents($fullchainFile) : $certContent . "\n" . $chainContent;

		// Write to {basename}/ subdirectory (certman internal layout)
		$this->writeSecure($dstDir . '/private.pem', $keyContent);
		$this->writeSecure($dstDir . '/cert.pem', $certContent);
		$this->writeSecure($dstDir . '/chain.pem', $chainContent);
		$this->writeSecure($dstDir . '/fullchain.pem', $fullchainContent);

		// Write to keys root (certman public layout)
		$this->writeSecure($keysDir . '/' . $basename . '.key', $keyContent);
		$this->writeSecure($keysDir . '/' . $basename . '.crt', $certContent);
		$this->writeSecure($keysDir . '/' . $basename . '.pem', $keyContent . "\n" . $fullchainContent);
		$this->writeSecure($keysDir . '/' . $basename . '-ca-bundle.crt', $chainContent);
		$this->writeSecure($keysDir . '/' . $basename . '-fullchain.crt', $fullchainContent);

		// Chown to web user
		$webuser = \FreePBX::Config()->get('AMPASTERISKWEBUSER');
		$webgroup = \FreePBX::Config()->get('AMPASTERISKWEBGROUP');
		if ($webuser) {
			$allFiles = [
				$dstDir . '/private.pem', $dstDir . '/cert.pem',
				$dstDir . '/chain.pem', $dstDir . '/fullchain.pem',
				$keysDir . '/' . $basename . '.key',
				$keysDir . '/' . $basename . '.crt',
				$keysDir . '/' . $basename . '.pem',
				$keysDir . '/' . $basename . '-ca-bundle.crt',
				$keysDir . '/' . $basename . '-fullchain.crt',
			];
			foreach ($allFiles as $f) {
				if (file_exists($f)) {
					chown($f, $webuser);
					if ($webgroup) {
						chgrp($f, $webgroup);
					}
				}
			}
		}

		// Register in certman_certs (type='up' = uploaded)
		$certmanCid = $this->registerInCertman($basename, $domain);

		// Update our record with the certman CID
		$cert = $this->getCertificateByDomain($domain);
		if ($cert) {
			$stmt = $this->db->prepare("UPDATE certman_acme_certs SET certman_cid = :cid WHERE id = :id");
			$stmt->execute([':cid' => $certmanCid, ':id' => $cert['id']]);
		}

		// If this is the only certificate, make it the default automatically
		$this->autoSetDefault($certmanCid);

		// Trigger certman hooks (reload Apache, Asterisk, HAProxy)
		$this->triggerReloads($certmanCid);

		return ['success' => true, 'message' => sprintf("Certificate for '%s' deployed to FreePBX.", $domain)];
	}

	/**
	 * Register or update a certificate in certman's certman_certs table.
	 */
	private function registerInCertman(string $basename, string $description): int
	{
		$stmt = $this->db->prepare("SELECT cid FROM certman_certs WHERE basename = :basename");
		$stmt->execute([':basename' => $basename]);
		$existing = $stmt->fetchColumn();

		if ($existing) {
			// Update the existing record
			$stmt = $this->db->prepare(
				"UPDATE certman_certs SET type = 'up', description = :desc WHERE cid = :cid"
			);
			$stmt->execute([':desc' => $description, ':cid' => $existing]);
			return (int) $existing;
		}

		// Insert new
		$stmt = $this->db->prepare(
			"INSERT INTO certman_certs (basename, description, type, `default`)
			 VALUES (:basename, :desc, 'up', 0)"
		);
		$stmt->execute([':basename' => $basename, ':desc' => $description]);
		return (int) $this->db->lastInsertId();
	}

	/**
	 * Make a certificate the default FreePBX certificate.
	 *
	 * Delegates to certman's makeCertDefault() which handles copying files
	 * to the integration directory and running hooks.
	 */
	public function makeDefault(string $domain): array
	{
		$cert = $this->getCertificateByDomain($domain);
		if (!$cert) {
			return ['success' => false, 'message' => sprintf("No ACME certificate found for '%s'.", $domain)];
		}
		if (empty($cert['certman_cid'])) {
			return ['success' => false, 'message' => sprintf("Certificate '%s' is not registered in certman yet.", $domain)];
		}

		try {
			$certman = \FreePBX::Certman();
			$certman->makeCertDefault($cert['certman_cid']);
		} catch (\Exception $e) {
			return ['success' => false, 'message' => sprintf("Failed to set default: %s", $e->getMessage())];
		}

		$this->triggerReloads($cert['certman_cid']);

		return ['success' => true, 'message' => sprintf("Certificate for '%s' is now the default.", $domain)];
	}

	/**
	 * Automatically set a certificate as default if it's the only one in certman.
	 */
	private function autoSetDefault(int $certmanCid): void
	{
		$stmt = $this->db->query("SELECT COUNT(*) FROM certman_certs");
		$count = (int) $stmt->fetchColumn();

		// If there's only one cert total, or no default is set, make this the default
		$stmt = $this->db->query("SELECT COUNT(*) FROM certman_certs WHERE `default` = 1");
		$hasDefault = (int) $stmt->fetchColumn();

		if ($count === 1 || $hasDefault === 0) {
			try {
				$certman = \FreePBX::Certman();
				$certman->makeCertDefault($certmanCid);
			} catch (\Exception $e) {
				// Non-fatal
			}
		}
	}

	/**
	 * Check if a certificate is the current default.
	 */
	public function isDefault(int $certmanCid): bool
	{
		if (!$certmanCid) {
			return false;
		}
		$stmt = $this->db->prepare("SELECT `default` FROM certman_certs WHERE cid = :cid");
		$stmt->execute([':cid' => $certmanCid]);
		return (int) $stmt->fetchColumn() === 1;
	}

	/**
	 * Trigger FreePBX service reloads after certificate deployment.
	 *
	 * Mirrors what certman does: flag a reload, reload Asterisk directly,
	 * run fwconsole reload (picks up Apache/all modules), and restart HAProxy.
	 */
	private function triggerReloads(int $certmanCid): void
	{
		// Flag FreePBX that a reload is needed (shows "Apply Config" bar)
		if (function_exists('needreload')) {
			needreload();
		}

		try {
			// Reload Asterisk TLS and dialplan
			$astman = $this->FreePBX->astman;
			if ($astman && $astman->connected()) {
				$astman->Reload();
				$a = fpbx_which('asterisk');
				if (!empty($a)) {
					exec($a . " -rx 'dialplan reload'");
				}
			}
		} catch (\Exception $e) {
			// Non-fatal
		}

		try {
			// Full fwconsole reload (Apache, all modules)
			$fwconsole = fpbx_which('fwconsole');
			if (!empty($fwconsole)) {
				exec($fwconsole . ' reload 2>&1');
			}
		} catch (\Exception $e) {
			// Non-fatal
		}

		try {
			// Reload HAProxy if sysadmin module is available and HAProxy is enabled
			if ($this->FreePBX->Modules->checkStatus('sysadmin')) {
				$sysadmin = $this->FreePBX->Sysadmin;
				$haproxyEnabled = $sysadmin->getConfig('enbableHaproxy');
				if ($haproxyEnabled === 'enabled') {
					$sysadmin->runHook('update-sslconf', ['restart_haproxy' => true]);
				}
			}
		} catch (\Exception $e) {
			// Non-fatal
		}
	}

	// ── acme.sh Shell Wrapper ────────────────────────────────────────

	/**
	 * Execute acme.sh with the given arguments and environment variables.
	 *
	 * @param array $args        Command arguments (already escaped where needed)
	 * @param array $env         Additional environment variables
	 * @return array             ['success' => bool, 'output' => string, 'exit_code' => int]
	 */
	private function runAcme(array $args, array $env = []): array
	{
		$script = $this->getAcmeScript();
		$home = $this->getAcmeHome();

		if (!file_exists($script)) {
			return [
				'success' => false,
				'output' => sprintf("acme.sh script not found at '%s'.", $script),
				'exit_code' => -1,
			];
		}

		$cmd = escapeshellarg($script)
			. ' --home ' . escapeshellarg($home)
			. ' ' . implode(' ', $args);

		// Build environment
		$envStrings = [];
		foreach ($env as $key => $value) {
			// Only allow alphanumeric + underscore env var names
			if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
				$envStrings[] = escapeshellarg($key) . '=' . escapeshellarg($value);
			}
		}

		if (!empty($envStrings)) {
			$cmd = 'env ' . implode(' ', $envStrings) . ' ' . $cmd;
		}

		$output = [];
		$exitCode = -1;
		exec($cmd . ' 2>&1', $output, $exitCode);

		$outputStr = implode("\n", $output);

		return [
			'success' => $exitCode === 0,
			'output' => $outputStr,
			'exit_code' => $exitCode,
		];
	}

	// ── Helpers ──────────────────────────────────────────────────────

	/**
	 * Write content to a file with secure permissions (0600).
	 */
	private function writeSecure(string $path, string $content): void
	{
		file_put_contents($path, $content);
		chmod($path, 0600);
	}

	/**
	 * Read certificate expiry date from the deployed cert file.
	 */
	private function readCertExpiry(string $domain): ?string
	{
		$pkcs = \FreePBX::create()->PKCS;
		$keysDir = $pkcs->getKeysLocation();
		$certFile = $keysDir . '/' . $domain . '.crt';

		if (!file_exists($certFile)) {
			return null;
		}

		$certData = openssl_x509_parse(file_get_contents($certFile));
		if ($certData && isset($certData['validTo_time_t'])) {
			return date('Y-m-d H:i:s', $certData['validTo_time_t']);
		}
		return null;
	}

	/**
	 * Save or update a certificate record in our tracking table.
	 */
	private function saveCertRecord(
		string $domain,
		array $san,
		string $dnsProvider,
		array $providerEnv,
		string $caServer,
		string $status,
		?string $lastError = null,
		?string $expiresAt = null
	): int {
		$existing = $this->getCertificateByDomain($domain);

		if ($existing) {
			$stmt = $this->db->prepare(
				"UPDATE certman_acme_certs SET
					san = :san, dns_provider = :dns, provider_env = :env,
					ca_server = :ca, status = :status, last_error = :err,
					issued_at = IF(:status2 = 'active', NOW(), issued_at),
					expires_at = COALESCE(:expires, expires_at)
				 WHERE id = :id"
			);
			$stmt->execute([
				':san' => json_encode($san),
				':dns' => $dnsProvider,
				':env' => json_encode($providerEnv),
				':ca' => $caServer,
				':status' => $status,
				':status2' => $status,
				':err' => $lastError,
				':expires' => $expiresAt,
				':id' => $existing['id'],
			]);
			return (int) $existing['id'];
		}

		$stmt = $this->db->prepare(
			"INSERT INTO certman_acme_certs
				(domain, san, dns_provider, provider_env, ca_server, status, issued_at, expires_at, last_error)
			 VALUES
				(:domain, :san, :dns, :env, :ca, :status, IF(:status2 = 'active', NOW(), NULL), :expires, :err)"
		);
		$stmt->execute([
			':domain' => $domain,
			':san' => json_encode($san),
			':dns' => $dnsProvider,
			':env' => json_encode($providerEnv),
			':ca' => $caServer,
			':status' => $status,
			':status2' => $status,
			':expires' => $expiresAt,
			':err' => $lastError,
		]);
		return (int) $this->db->lastInsertId();
	}

	/**
	 * Update certificate status in our tracking table.
	 */
	private function updateCertStatus(int $id, string $status, ?string $lastError = null, ?string $expiresAt = null): void
	{
		$stmt = $this->db->prepare(
			"UPDATE certman_acme_certs SET
				status = :status,
				last_error = :err,
				expires_at = COALESCE(:expires, expires_at)
			 WHERE id = :id"
		);
		$stmt->execute([':status' => $status, ':err' => $lastError, ':expires' => $expiresAt, ':id' => $id]);
	}

	// ── UI Page Rendering ────────────────────────────────────────────

	/**
	 * Show the module page (called from page.certmanacme.php).
	 */
	public function myShowPage(string $action = '', int $id = 0): string
	{
		switch ($action) {
			case 'new':
				return $this->showNewCertPage();
			case 'edit':
				return $this->showEditCertPage($id);
			case 'settings':
				return $this->showSettingsPage();
			default:
				return $this->showMainPage();
		}
	}

	private function showMainPage(): string
	{
		ob_start();
		$certs = $this->getCertificates();
		include __DIR__ . '/views/main.php';
		return ob_get_clean();
	}

	private function showNewCertPage(): string
	{
		ob_start();
		$providers = $this->getProviders();
		$caServers = array_keys(self::CA_SERVERS);
		$email = $this->getSetting('email', '');
		include __DIR__ . '/views/new.php';
		return ob_get_clean();
	}

	private function showEditCertPage(int $id): string
	{
		$cert = $this->getCertificate($id);
		if (!$cert) {
			$_SESSION['certacme_msg'] = ['type' => 'danger', 'text' => _('Certificate not found.')];
			return $this->showMainPage();
		}
		ob_start();
		$providers = $this->getProviders();
		$caServers = array_keys(self::CA_SERVERS);
		include __DIR__ . '/views/edit.php';
		return ob_get_clean();
	}

	private function showSettingsPage(): string
	{
		ob_start();
		$settings = [
			'email' => $this->getSetting('email', ''),
			'default_ca' => $this->getSetting('default_ca', 'letsencrypt'),
			'acme_script_dir' => $this->getSetting('acme_script_dir', ''),
			'acme_home_dir' => $this->getSetting('acme_home_dir', ''),
		];
		$caServers = array_keys(self::CA_SERVERS);
		include __DIR__ . '/views/settings.php';
		return ob_get_clean();
	}
}
