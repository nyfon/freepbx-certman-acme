<?php
/**
 * ACME Certificate Manager - Console Command
 *
 * Provides: fwconsole certacme
 */

namespace FreePBX\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class CertmanAcme extends Command
{
	protected function configure()
	{
		$this->setName('certacme')
			->setDescription(_('ACME Certificate Management (DNS-01)'))
			->setDefinition([
				new InputOption('list', null, InputOption::VALUE_NONE, _('List ACME-managed certificates')),
				new InputOption('providers', null, InputOption::VALUE_NONE, _('List available DNS providers')),
				new InputOption('issue', null, InputOption::VALUE_NONE, _('Issue a new certificate')),
				new InputOption('renew', null, InputOption::VALUE_NONE, _('Renew certificates (all if no -d given)')),
				new InputOption('revoke', null, InputOption::VALUE_NONE, _('Revoke a certificate')),
				new InputOption('deploy', null, InputOption::VALUE_NONE, _('Re-deploy a certificate to FreePBX')),
				new InputOption('delete', null, InputOption::VALUE_NONE, _('Delete a certificate')),
				new InputOption('domain', 'd', InputOption::VALUE_REQUIRED, _('Primary domain')),
				new InputOption('san', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, _('Subject Alternative Name(s)')),
				new InputOption('dns', null, InputOption::VALUE_REQUIRED, _('DNS provider (e.g., dns_hetznercloud)')),
				new InputOption('env', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, _('Provider env var (KEY=VALUE)')),
				new InputOption('server', null, InputOption::VALUE_REQUIRED, _('CA server (letsencrypt, zerossl, buypass, or URL)')),
				new InputOption('force', null, InputOption::VALUE_NONE, _('Force renewal regardless of expiry')),
				new InputOption('json', null, InputOption::VALUE_NONE, _('Output as JSON')),
				new InputOption('update-acme', null, InputOption::VALUE_NONE, _('Download/update acme.sh')),
			]);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$certacme = \FreePBX::Certmanacme();

		if ($input->getOption('update-acme')) {
			return $this->cmdUpdateAcme($certacme, $input, $output);
		}

		if ($input->getOption('providers')) {
			return $this->cmdProviders($certacme, $input, $output);
		}

		if ($input->getOption('list')) {
			return $this->cmdList($certacme, $input, $output);
		}

		if ($input->getOption('issue')) {
			return $this->cmdIssue($certacme, $input, $output);
		}

		if ($input->getOption('renew')) {
			return $this->cmdRenew($certacme, $input, $output);
		}

		if ($input->getOption('revoke')) {
			return $this->cmdRevoke($certacme, $input, $output);
		}

		if ($input->getOption('deploy')) {
			return $this->cmdDeploy($certacme, $input, $output);
		}

		if ($input->getOption('delete')) {
			return $this->cmdDelete($certacme, $input, $output);
		}

		// No option given — show help
		$output->writeln('<info>Use --help to see available options.</info>');
		return Command::SUCCESS;
	}

	private function cmdProviders($certacme, InputInterface $input, OutputInterface $output): int
	{
		$providers = $certacme->getProviders();

		if ($input->getOption('json')) {
			$output->writeln(json_encode($providers, JSON_PRETTY_PRINT));
			return Command::SUCCESS;
		}

		$table = new Table($output);
		$table->setHeaders(['ID', 'Name', 'Required Env Vars']);

		foreach ($providers as $id => $p) {
			$envVars = implode(', ', array_keys($p['options']));
			$table->addRow([$id, $p['name'], $envVars ?: '-']);
		}

		$table->render();
		$output->writeln(sprintf("\n<info>%d providers available.</info>", count($providers)));
		return Command::SUCCESS;
	}

	private function cmdList($certacme, InputInterface $input, OutputInterface $output): int
	{
		$certs = $certacme->getCertificates();

		if ($input->getOption('json')) {
			// Strip provider_env from JSON output for security
			$safe = array_map(function ($c) {
				unset($c['provider_env']);
				return $c;
			}, $certs);
			$output->writeln(json_encode($safe, JSON_PRETTY_PRINT));
			return Command::SUCCESS;
		}

		if (empty($certs)) {
			$output->writeln('<info>No ACME certificates managed.</info>');
			return Command::SUCCESS;
		}

		$table = new Table($output);
		$table->setHeaders(['ID', 'Domain', 'Provider', 'CA', 'Status', 'Expires']);

		foreach ($certs as $c) {
			$table->addRow([
				$c['id'],
				$c['domain'],
				$c['dns_provider'],
				$c['ca_server'],
				$c['status'],
				$c['expires_at'] ?? '-',
			]);
		}

		$table->render();
		return Command::SUCCESS;
	}

	private function cmdIssue($certacme, InputInterface $input, OutputInterface $output): int
	{
		$domain = $input->getOption('domain');
		$dns = $input->getOption('dns');

		if (!$domain || !$dns) {
			$output->writeln('<error>--domain (-d) and --dns are required for --issue.</error>');
			return Command::FAILURE;
		}

		$envVars = $this->parseEnvOptions($input->getOption('env'));
		$san = $input->getOption('san') ?: [];
		$server = $input->getOption('server') ?: $certacme->getSetting('default_ca', 'letsencrypt');

		$output->writeln(sprintf('<info>Issuing certificate for %s via %s...</info>', $domain, $dns));

		$result = $certacme->issueCertificate($domain, $dns, $envVars, $san, $server);

		if ($result['success']) {
			$output->writeln('<info>' . $result['message'] . '</info>');
			return Command::SUCCESS;
		}

		$output->writeln('<error>' . $result['message'] . '</error>');
		return Command::FAILURE;
	}

	private function cmdRenew($certacme, InputInterface $input, OutputInterface $output): int
	{
		$domain = $input->getOption('domain');
		$force = $input->getOption('force');

		if ($domain) {
			$output->writeln(sprintf('<info>Renewing certificate for %s...</info>', $domain));
			$result = $certacme->renewCertificate($domain, $force);
			if ($result['success']) {
				$output->writeln('<info>' . $result['message'] . '</info>');
				return Command::SUCCESS;
			}
			$output->writeln('<error>' . $result['message'] . '</error>');
			return Command::FAILURE;
		}

		// Renew all
		$output->writeln('<info>Checking all ACME certificates for renewal...</info>');
		$results = $certacme->renewAll($force);

		$hasFailure = false;
		foreach ($results as $dom => $result) {
			$tag = $result['success'] ? 'info' : 'error';
			$output->writeln(sprintf('<%s>%s: %s</%s>', $tag, $dom, $result['message'], $tag));
			if (!$result['success']) {
				$hasFailure = true;
			}
		}

		return $hasFailure ? Command::FAILURE : Command::SUCCESS;
	}

	private function cmdRevoke($certacme, InputInterface $input, OutputInterface $output): int
	{
		$domain = $input->getOption('domain');
		if (!$domain) {
			$output->writeln('<error>--domain (-d) is required for --revoke.</error>');
			return Command::FAILURE;
		}

		$result = $certacme->revokeCertificate($domain);
		$tag = $result['success'] ? 'info' : 'error';
		$output->writeln(sprintf('<%s>%s</%s>', $tag, $result['message'], $tag));
		return $result['success'] ? Command::SUCCESS : Command::FAILURE;
	}

	private function cmdDeploy($certacme, InputInterface $input, OutputInterface $output): int
	{
		$domain = $input->getOption('domain');
		if (!$domain) {
			$output->writeln('<error>--domain (-d) is required for --deploy.</error>');
			return Command::FAILURE;
		}

		$result = $certacme->deployCertificate($domain);
		$tag = $result['success'] ? 'info' : 'error';
		$output->writeln(sprintf('<%s>%s</%s>', $tag, $result['message'], $tag));
		return $result['success'] ? Command::SUCCESS : Command::FAILURE;
	}

	private function cmdDelete($certacme, InputInterface $input, OutputInterface $output): int
	{
		$domain = $input->getOption('domain');
		if (!$domain) {
			$output->writeln('<error>--domain (-d) is required for --delete.</error>');
			return Command::FAILURE;
		}

		$result = $certacme->deleteCertificate($domain, true);
		$tag = $result['success'] ? 'info' : 'error';
		$output->writeln(sprintf('<%s>%s</%s>', $tag, $result['message'], $tag));
		return $result['success'] ? Command::SUCCESS : Command::FAILURE;
	}

	private function cmdUpdateAcme($certacme, InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('<info>Updating acme.sh...</info>');
		$result = $certacme->updateAcme();
		$tag = $result['success'] ? 'info' : 'error';
		$output->writeln(sprintf('<%s>%s</%s>', $tag, $result['message'], $tag));
		return $result['success'] ? Command::SUCCESS : Command::FAILURE;
	}

	/**
	 * Parse --env KEY=VALUE options into an associative array.
	 */
	private function parseEnvOptions(array $envOptions): array
	{
		$result = [];
		foreach ($envOptions as $pair) {
			$pos = strpos($pair, '=');
			if ($pos !== false) {
				$key = substr($pair, 0, $pos);
				$value = substr($pair, $pos + 1);
				$result[$key] = $value;
			}
		}
		return $result;
	}
}
