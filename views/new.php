<?php
// ACME Certificate Manager - New Certificate View
// Variables: $providers (array), $caServers (array), $email (string)
?>

<div class="container-fluid">
	<h1><?= _('Issue New ACME Certificate') ?></h1>

	<a href="?display=certmanacme" class="btn btn-default" style="margin-bottom: 15px;">
		<i class="fa fa-arrow-left"></i> <?= _('Back to List') ?>
	</a>

	<form method="post" action="?display=certmanacme" id="certacme-issue-form">
		<input type="hidden" name="action" value="issue">

		<div class="panel panel-default">
			<div class="panel-heading">
				<h3 class="panel-title"><?= _('Domain Settings') ?></h3>
			</div>
			<div class="panel-body">
				<div class="form-group">
					<label for="domain"><?= _('Primary Domain') ?> <span class="text-danger">*</span></label>
					<input type="text" class="form-control" id="domain" name="domain"
						   placeholder="pbx.example.com" required>
					<p class="help-block"><?= _('The main domain for the certificate (FQDN).') ?></p>
				</div>

				<div class="form-group">
					<label for="san"><?= _('Subject Alternative Names (SANs)') ?></label>
					<textarea class="form-control" id="san" name="san" rows="3"
							  placeholder="alt1.example.com&#10;alt2.example.com"></textarea>
					<p class="help-block"><?= _('One domain per line. Optional additional domains for the same certificate.') ?></p>
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
									data-docs="<?= htmlspecialchars($p['docs']) ?>">
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
								<?= $ca === 'letsencrypt' ? 'selected' : '' ?>>
								<?= htmlspecialchars(ucfirst(str_replace('_', ' ', $ca))) ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="help-block"><?= _('Let\'s Encrypt is recommended. Use staging servers for testing.') ?></p>
				</div>
			</div>
		</div>

		<button type="submit" class="btn btn-primary btn-lg">
			<i class="fa fa-certificate"></i> <?= _('Issue Certificate') ?>
		</button>
	</form>
</div>

<script src="modules/certmanacme/assets/js/certmanacme.js"></script>
