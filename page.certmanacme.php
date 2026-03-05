<?php
// ACME Certificate Manager - Page Router
$request = $_REQUEST;
$certacme = FreePBX::Certmanacme();
$request['action'] = !empty($request['action']) ? $request['action'] : '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	switch ($request['action']) {
		case 'issue':
			$domain = strtolower(trim($_POST['domain'] ?? ''));
			$dnsProvider = trim($_POST['dns_provider'] ?? '');
			$caServer = trim($_POST['ca_server'] ?? 'letsencrypt');
			$sanRaw = trim($_POST['san'] ?? '');
			$san = array_filter(array_map('trim', explode("\n", $sanRaw)));

			// Parse provider env vars from POST
			$providerEnv = [];
			foreach ($_POST as $key => $value) {
				if (strpos($key, 'penv_') === 0) {
					$envKey = substr($key, 5); // Strip "penv_" prefix
					if ($value !== '') {
						$providerEnv[$envKey] = $value;
					}
				}
			}

			if ($domain && $dnsProvider) {
				$result = $certacme->issueCertificate($domain, $dnsProvider, $providerEnv, $san, $caServer);
				if ($result['success']) {
					$_SESSION['certacme_msg'] = ['type' => 'success', 'text' => $result['message']];
				} else {
					$_SESSION['certacme_msg'] = ['type' => 'danger', 'text' => $result['message']];
				}
			}
			// Redirect to list
			header('Location: ?display=certmanacme');
			exit;

		case 'settings':
			if (isset($_POST['email'])) {
				$certacme->setSetting('email', trim($_POST['email']));
			}
			if (isset($_POST['default_ca'])) {
				$certacme->setSetting('default_ca', trim($_POST['default_ca']));
			}
			$_SESSION['certacme_msg'] = ['type' => 'success', 'text' => _('Settings saved.')];
			header('Location: ?display=certmanacme&action=settings');
			exit;

		case 'delete':
			$domain = strtolower(trim($_POST['domain'] ?? ''));
			if ($domain) {
				$result = $certacme->deleteCertificate($domain, true);
				$type = $result['success'] ? 'success' : 'danger';
				$_SESSION['certacme_msg'] = ['type' => $type, 'text' => $result['message']];
			}
			header('Location: ?display=certmanacme');
			exit;

		case 'update-acme':
			$result = $certacme->updateAcme();
			$type = $result['success'] ? 'success' : 'danger';
			$_SESSION['certacme_msg'] = ['type' => $type, 'text' => $result['message']];
			header('Location: ?display=certmanacme&action=settings');
			exit;

		case 'renew':
			$domain = strtolower(trim($_POST['domain'] ?? ''));
			if ($domain) {
				$result = $certacme->renewCertificate($domain);
				$type = $result['success'] ? 'success' : 'danger';
				$_SESSION['certacme_msg'] = ['type' => $type, 'text' => $result['message']];
			}
			header('Location: ?display=certmanacme');
			exit;

		case 'make-default':
			$domain = strtolower(trim($_POST['domain'] ?? ''));
			if ($domain) {
				$result = $certacme->makeDefault($domain);
				$type = $result['success'] ? 'success' : 'danger';
				$_SESSION['certacme_msg'] = ['type' => $type, 'text' => $result['message']];
			}
			header('Location: ?display=certmanacme');
			exit;

		case 'edit':
			$domain = strtolower(trim($_POST['domain'] ?? ''));
			$dnsProvider = trim($_POST['dns_provider'] ?? '');
			$caServer = trim($_POST['ca_server'] ?? 'letsencrypt');
			$sanRaw = trim($_POST['san'] ?? '');
			$san = array_filter(array_map('trim', explode("\n", $sanRaw)));

			$providerEnv = [];
			foreach ($_POST as $key => $value) {
				if (strpos($key, 'penv_') === 0) {
					$envKey = substr($key, 5);
					if ($value !== '') {
						$providerEnv[$envKey] = $value;
					}
				}
			}

			if ($domain && $dnsProvider) {
				// Delete existing and re-issue with new settings
				$certacme->deleteCertificate($domain, true);
				$result = $certacme->issueCertificate($domain, $dnsProvider, $providerEnv, $san, $caServer);
				$type = $result['success'] ? 'success' : 'danger';
				$_SESSION['certacme_msg'] = ['type' => $type, 'text' => $result['message']];
			}
			header('Location: ?display=certmanacme');
			exit;
	}
}

$id = (int) ($request['id'] ?? 0);
echo $certacme->myShowPage($request['action'], $id);
