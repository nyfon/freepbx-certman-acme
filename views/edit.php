<?php
// ACME Certificate Manager - Edit Certificate View
// Variables: $cert (array), $providers (array), $caServers (array)

$msg = $_SESSION['certacme_msg'] ?? null;
unset($_SESSION['certacme_msg']);

$san = json_decode($cert['san'] ?? '[]', true) ?: [];
$providerEnv = json_decode($cert['provider_env'] ?? '{}', true) ?: [];
?>

<div class="container-fluid">
	<h1><?= _('Edit Certificate') ?>: <?= htmlspecialchars($cert['domain']) ?></h1>

	<a href="?display=certmanacme" class="btn btn-default" style="margin-bottom: 15px;">
		<i class="fa fa-arrow-left"></i> <?= _('Back to List') ?>
	</a>

	<?php if ($msg): ?>
		<div class="alert alert-<?= htmlspecialchars($msg['type']) ?> alert-dismissible">
			<button type="button" class="close" data-dismiss="alert">&times;</button>
			<?= htmlspecialchars($msg['text']) ?>
		</div>
	<?php endif; ?>

	<div class="panel panel-default">
		<div class="panel-heading">
			<h3 class="panel-title"><?= _('Certificate Status') ?></h3>
		</div>
		<div class="panel-body">
			<?php
			$statusClass = match ($cert['status']) {
				'active' => 'success',
				'pending' => 'warning',
				'failed' => 'danger',
				'revoked' => 'default',
				default => 'info',
			};
			?>
			<div class="form-group">
				<label><?= _('Status') ?></label>
				<p class="form-control-static">
					<span class="label label-<?= $statusClass ?>">
						<?= htmlspecialchars(ucfirst($cert['status'])) ?>
					</span>
				</p>
			</div>
			<?php if ($cert['issued_at']): ?>
				<div class="form-group">
					<label><?= _('Issued') ?></label>
					<p class="form-control-static"><?= htmlspecialchars($cert['issued_at']) ?></p>
				</div>
			<?php endif; ?>
			<?php if ($cert['expires_at']): ?>
				<div class="form-group">
					<label><?= _('Expires') ?></label>
					<?php
					$expires = strtotime($cert['expires_at']);
					$daysLeft = $expires ? (int) round(($expires - time()) / 86400) : null;
					?>
					<p class="form-control-static">
						<?= htmlspecialchars($cert['expires_at']) ?>
						<?php if ($daysLeft !== null): ?>
							<?php if ($daysLeft <= 0): ?>
								<span class="label label-danger"><?= _('Expired') ?></span>
							<?php elseif ($daysLeft <= 30): ?>
								<span class="label label-warning"><?= sprintf(_('%d days left'), $daysLeft) ?></span>
							<?php else: ?>
								<span class="text-muted">(<?= sprintf(_('%d days left'), $daysLeft) ?>)</span>
							<?php endif; ?>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ($cert['certman_cid']): ?>
				<div class="form-group">
					<label><?= _('Certman ID') ?></label>
					<p class="form-control-static"><?= htmlspecialchars($cert['certman_cid']) ?></p>
				</div>
			<?php endif; ?>
			<?php if ($cert['status'] === 'failed' && $cert['last_error']): ?>
				<div class="form-group">
					<label><?= _('Last Error') ?></label>
					<pre class="text-danger" style="max-height: 200px; overflow-y: auto;"><?= htmlspecialchars($cert['last_error']) ?></pre>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<form method="post" action="?display=certmanacme" id="certacme-edit-form">
		<input type="hidden" name="action" value="edit">
		<input type="hidden" name="id" value="<?= (int) $cert['id'] ?>">
		<input type="hidden" name="domain" value="<?= htmlspecialchars($cert['domain']) ?>">

		<div class="panel panel-default">
			<div class="panel-heading">
				<h3 class="panel-title"><?= _('Domain Settings') ?></h3>
			</div>
			<div class="panel-body">
				<div class="form-group">
					<label><?= _('Primary Domain') ?></label>
					<p class="form-control-static"><strong><?= htmlspecialchars($cert['domain']) ?></strong></p>
					<p class="help-block"><?= _('The primary domain cannot be changed. Delete and re-issue to use a different domain.') ?></p>
				</div>

				<div class="form-group">
					<label for="san"><?= _('Subject Alternative Names (SANs)') ?></label>
					<textarea class="form-control" id="san" name="san" rows="3"
							  placeholder="alt1.example.com&#10;alt2.example.com"><?= htmlspecialchars(implode("\n", $san)) ?></textarea>
					<p class="help-block"><?= _('One domain per line. Changes require re-issuance.') ?></p>
				</div>
			</div>
		</div>

		<div class="panel panel-default">
			<div class="panel-heading">
				<h3 class="panel-title"><?= _('DNS Provider') ?></h3>
			</div>
			<div class="panel-body">
				<div class="form-group">
					<label for="dns_provider"><?= _('DNS Provider') ?> <span class="text-danger">*</span></label>
					<select class="form-control" id="dns_provider" name="dns_provider" required>
						<option value=""><?= _('-- Select DNS Provider --') ?></option>
						<?php foreach ($providers as $id => $p): ?>
							<option value="<?= htmlspecialchars($id) ?>"
									data-options="<?= htmlspecialchars(json_encode($p['options'])) ?>"
									data-optional="<?= htmlspecialchars(json_encode($p['optional'])) ?>"
									data-docs="<?= htmlspecialchars($p['docs']) ?>"
									<?= $id === $cert['dns_provider'] ? 'selected' : '' ?>>
								<?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($id) ?>)
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div id="provider-docs" style="display: none;" class="alert alert-info">
					<i class="fa fa-book"></i> <a id="provider-docs-link" href="#" target="_blank"><?= _('Provider Documentation') ?></a>
				</div>

				<div id="provider-fields-required"></div>
				<div id="provider-fields-optional">
					<div id="optional-toggle" style="display: none; margin-bottom: 10px;">
						<a href="#" onclick="document.getElementById('optional-fields').style.display='block'; this.style.display='none'; return false;">
							<i class="fa fa-caret-down"></i> <?= _('Show optional settings') ?>
						</a>
					</div>
					<div id="optional-fields" style="display: none;"></div>
				</div>
			</div>
		</div>

		<div class="panel panel-default">
			<div class="panel-heading">
				<h3 class="panel-title"><?= _('Certificate Authority') ?></h3>
			</div>
			<div class="panel-body">
				<div class="form-group">
					<label for="ca_server"><?= _('CA Server') ?></label>
					<select class="form-control" id="ca_server" name="ca_server">
						<?php foreach ($caServers as $ca): ?>
							<option value="<?= htmlspecialchars($ca) ?>"
								<?= $ca === $cert['ca_server'] ? 'selected' : '' ?>>
								<?= htmlspecialchars(ucfirst(str_replace('_', ' ', $ca))) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
		</div>

		<div class="btn-toolbar">
			<button type="submit" class="btn btn-primary">
				<i class="fa fa-save"></i> <?= _('Save & Re-issue Certificate') ?>
			</button>
			<form method="post" action="?display=certmanacme" style="display: inline;">
				<input type="hidden" name="action" value="renew">
				<input type="hidden" name="domain" value="<?= htmlspecialchars($cert['domain']) ?>">
				<button type="submit" class="btn btn-success">
					<i class="fa fa-refresh"></i> <?= _('Force Renew') ?>
				</button>
			</form>
			<form method="post" action="?display=certmanacme" style="display: inline;"
				  onsubmit="return confirm('<?= _('Are you sure you want to delete this certificate?') ?>');">
				<input type="hidden" name="action" value="delete">
				<input type="hidden" name="domain" value="<?= htmlspecialchars($cert['domain']) ?>">
				<button type="submit" class="btn btn-danger">
					<i class="fa fa-trash"></i> <?= _('Delete') ?>
				</button>
			</form>
		</div>
	</form>
</div>

<script>
// Pre-populate provider env fields with saved values
var savedEnv = <?= json_encode($providerEnv) ?>;
</script>
<script src="modules/certmanacme/assets/js/certmanacme.js"></script>
