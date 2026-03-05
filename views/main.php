<?php
// ACME Certificate Manager - Main View (Certificate List)
// Variables: $certs (array)

$msg = $_SESSION['certacme_msg'] ?? null;
unset($_SESSION['certacme_msg']);
?>

<div class="container-fluid">
	<h1><?= _('ACME Certificates') ?></h1>

	<?php if ($msg): ?>
		<div class="alert alert-<?= htmlspecialchars($msg['type']) ?> alert-dismissible">
			<button type="button" class="close" data-dismiss="alert">&times;</button>
			<?= htmlspecialchars($msg['text']) ?>
		</div>
	<?php endif; ?>

	<div class="btn-toolbar" style="margin-bottom: 15px;">
		<a href="?display=certmanacme&action=new" class="btn btn-primary">
			<i class="fa fa-plus"></i> <?= _('New Certificate') ?>
		</a>
		<a href="?display=certmanacme&action=settings" class="btn btn-default" style="margin-left: 5px;">
			<i class="fa fa-cog"></i> <?= _('Settings') ?>
		</a>
	</div>

	<?php if (empty($certs)): ?>
		<div class="alert alert-info">
			<?= _('No ACME certificates managed yet. Click "New Certificate" to issue one.') ?>
		</div>
	<?php else: ?>
		<table class="table table-striped table-hover">
			<thead>
				<tr>
					<th><?= _('Domain') ?></th>
					<th><?= _('DNS Provider') ?></th>
					<th><?= _('CA Server') ?></th>
					<th><?= _('Status') ?></th>
					<th><?= _('Expires') ?></th>
					<th><?= _('Default') ?></th>
					<th><?= _('Actions') ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($certs as $cert): ?>
					<?php
					$statusClass = match ($cert['status']) {
						'active' => 'success',
						'pending' => 'warning',
						'failed' => 'danger',
						'revoked' => 'default',
						default => 'info',
					};

					$san = json_decode($cert['san'] ?? '[]', true);
					$sanStr = $san ? implode(', ', $san) : '';
					$domainDisplay = htmlspecialchars($cert['domain']);
					if ($sanStr) {
						$domainDisplay .= '<br><small class="text-muted">SANs: ' . htmlspecialchars($sanStr) . '</small>';
					}
					$isDefault = $this->isDefault((int) ($cert['certman_cid'] ?? 0));
					?>
					<tr>
						<td><?= $domainDisplay ?></td>
						<td><?= htmlspecialchars($cert['dns_provider']) ?></td>
						<td><?= htmlspecialchars($cert['ca_server']) ?></td>
						<td>
							<span class="label label-<?= $statusClass ?>">
								<?= htmlspecialchars(ucfirst($cert['status'])) ?>
							</span>
							<?php if ($cert['status'] === 'failed' && $cert['last_error']): ?>
								<br><small class="text-danger" title="<?= htmlspecialchars($cert['last_error']) ?>">
									<?= htmlspecialchars(substr($cert['last_error'], 0, 80)) ?>...
								</small>
							<?php endif; ?>
						</td>
						<td>
							<?php if ($cert['expires_at']): ?>
								<?= htmlspecialchars($cert['expires_at']) ?>
								<?php
								$expires = strtotime($cert['expires_at']);
								$daysLeft = $expires ? (int) round(($expires - time()) / 86400) : null;
								if ($daysLeft !== null && $daysLeft <= 30): ?>
									<br><small class="text-warning"><?= sprintf(_('%d days left'), $daysLeft) ?></small>
								<?php endif; ?>
							<?php else: ?>
								-
							<?php endif; ?>
						</td>
						<td style="text-align: center;">
							<?php if ($cert['status'] === 'active' && !empty($cert['certman_cid'])): ?>
								<input type="radio" name="default_cert"
									   value="<?= htmlspecialchars($cert['domain']) ?>"
									   <?= $isDefault ? 'checked' : '' ?>
									   onchange="document.getElementById('default-form-domain').value=this.value; document.getElementById('default-form').submit();">
							<?php endif; ?>
						</td>
						<td>
							<a href="?display=certmanacme&action=edit&id=<?= (int) $cert['id'] ?>"
							   class="btn btn-xs btn-default" title="<?= _('Edit') ?>">
								<i class="fa fa-pencil"></i>
							</a>
							<form method="post" action="?display=certmanacme" style="display: inline;">
								<input type="hidden" name="action" value="renew">
								<input type="hidden" name="domain" value="<?= htmlspecialchars($cert['domain']) ?>">
								<button type="submit" class="btn btn-xs btn-success" title="<?= _('Renew') ?>">
									<i class="fa fa-refresh"></i>
								</button>
							</form>
							<form method="post" action="?display=certmanacme" style="display: inline;"
								  onsubmit="return confirm('<?= _('Are you sure you want to delete this certificate?') ?>');">
								<input type="hidden" name="action" value="delete">
								<input type="hidden" name="domain" value="<?= htmlspecialchars($cert['domain']) ?>">
								<button type="submit" class="btn btn-xs btn-danger" title="<?= _('Delete') ?>">
									<i class="fa fa-trash"></i>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<form id="default-form" method="post" action="?display=certmanacme" style="display: none;">
		<input type="hidden" name="action" value="make-default">
		<input type="hidden" name="domain" value="" id="default-form-domain">
	</form>
</div>
