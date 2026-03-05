<?php
// ACME Certificate Manager - Settings View
// Variables: $settings (array), $caServers (array)

$msg = $_SESSION['certacme_msg'] ?? null;
unset($_SESSION['certacme_msg']);
?>

<div class="container-fluid">
	<h1><?= _('ACME Certificate Settings') ?></h1>

	<a href="?display=certmanacme" class="btn btn-default" style="margin-bottom: 15px;">
		<i class="fa fa-arrow-left"></i> <?= _('Back to List') ?>
	</a>

	<?php if ($msg): ?>
		<div class="alert alert-<?= htmlspecialchars($msg['type']) ?> alert-dismissible">
			<button type="button" class="close" data-dismiss="alert">&times;</button>
			<?= htmlspecialchars($msg['text']) ?>
		</div>
	<?php endif; ?>

	<form method="post" action="?display=certmanacme">
		<input type="hidden" name="action" value="settings">

		<div class="panel panel-default">
			<div class="panel-heading">
				<h3 class="panel-title"><?= _('General') ?></h3>
			</div>
			<div class="panel-body">
				<div class="form-group">
					<label for="email"><?= _('Account Email') ?></label>
					<input type="email" class="form-control" id="email" name="email"
						   value="<?= htmlspecialchars($settings['email']) ?>"
						   placeholder="admin@example.com">
					<p class="help-block"><?= _('Email for ACME account registration and expiry notifications.') ?></p>
				</div>

				<div class="form-group">
					<label for="default_ca"><?= _('Default CA Server') ?></label>
					<select class="form-control" id="default_ca" name="default_ca">
						<?php foreach ($caServers as $ca): ?>
							<option value="<?= htmlspecialchars($ca) ?>"
								<?= $ca === $settings['default_ca'] ? 'selected' : '' ?>>
								<?= htmlspecialchars(ucfirst(str_replace('_', ' ', $ca))) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
		</div>

		<button type="submit" class="btn btn-primary">
			<i class="fa fa-save"></i> <?= _('Save Settings') ?>
		</button>
	</form>

	<div class="panel panel-default" style="margin-top: 20px;">
		<div class="panel-heading">
			<h3 class="panel-title"><?= _('acme.sh') ?></h3>
		</div>
		<div class="panel-body">
			<?php
			$acmeScript = $settings['acme_script_dir'] . '/acme.sh';
			$acmeInstalled = file_exists($acmeScript);
			?>
			<div class="form-group">
				<label><?= _('Status') ?></label>
				<?php if ($acmeInstalled): ?>
					<p class="form-control-static">
						<span class="label label-success"><?= _('Installed') ?></span>
					</p>
				<?php else: ?>
					<p class="form-control-static">
						<span class="label label-danger"><?= _('Not installed') ?></span>
						<span class="text-danger"><?= _('acme.sh is required. Click "Download/Update acme.sh" below.') ?></span>
					</p>
				<?php endif; ?>
			</div>
			<div class="form-group">
				<label><?= _('Script Directory') ?></label>
				<p class="form-control-static"><code><?= htmlspecialchars($settings['acme_script_dir']) ?></code></p>
			</div>
			<div class="form-group">
				<label><?= _('Data Directory') ?></label>
				<p class="form-control-static"><code><?= htmlspecialchars($settings['acme_home_dir']) ?></code></p>
			</div>
		</div>
		<div class="panel-footer">
			<form method="post" action="?display=certmanacme">
				<input type="hidden" name="action" value="update-acme">
				<button type="submit" class="btn btn-default">
					<i class="fa fa-download"></i> <?= _('Download / Update acme.sh') ?>
				</button>
			</form>
		</div>
	</div>
</div>
