/**
 * ACME Certificate Manager - Dynamic provider field loading
 *
 * When a DNS provider is selected, this script reads the data-options and
 * data-optional attributes from the <option> element and generates the
 * appropriate form fields for the provider's environment variables.
 */
(function () {
	'use strict';

	var select = document.getElementById('dns_provider');
	if (!select) return;

	select.addEventListener('change', function () {
		var option = select.options[select.selectedIndex];
		var requiredContainer = document.getElementById('provider-fields-required');
		var optionalContainer = document.getElementById('optional-fields');
		var optionalToggle = document.getElementById('optional-toggle');
		var docsDiv = document.getElementById('provider-docs');
		var docsLink = document.getElementById('provider-docs-link');

		// Clear existing fields
		requiredContainer.innerHTML = '';
		optionalContainer.innerHTML = '';
		optionalToggle.style.display = 'none';
		optionalContainer.style.display = 'none';

		if (!option.value) {
			docsDiv.style.display = 'none';
			return;
		}

		// Documentation link
		var docs = option.getAttribute('data-docs');
		if (docs) {
			var href = docs.indexOf('://') === -1 ? 'https://' + docs : docs;
			docsLink.href = href;
			docsDiv.style.display = 'block';
		} else {
			docsDiv.style.display = 'none';
		}

		// Required fields
		var required = {};
		try { required = JSON.parse(option.getAttribute('data-options') || '{}'); } catch (e) {}

		Object.keys(required).forEach(function (key) {
			requiredContainer.appendChild(
				buildField(key, required[key], true)
			);
		});

		// Optional fields
		var optional = {};
		try { optional = JSON.parse(option.getAttribute('data-optional') || '{}'); } catch (e) {}

		var optKeys = Object.keys(optional);
		if (optKeys.length > 0) {
			optionalToggle.style.display = 'block';
			optKeys.forEach(function (key) {
				optionalContainer.appendChild(
					buildField(key, optional[key], false)
				);
			});
		}
	});

	// Trigger change on page load if a provider is already selected (edit page)
	if (select.value) {
		select.dispatchEvent(new Event('change'));
	}

	function buildField(envVar, description, isRequired) {
		var div = document.createElement('div');
		div.className = 'form-group';

		var label = document.createElement('label');
		label.setAttribute('for', 'penv_' + envVar);
		label.textContent = envVar;
		if (isRequired) {
			var span = document.createElement('span');
			span.className = 'text-danger';
			span.textContent = ' *';
			label.appendChild(span);
		}

		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'form-control';
		input.id = 'penv_' + envVar;
		input.name = 'penv_' + envVar;
		input.placeholder = envVar;
		if (isRequired) {
			input.required = true;
		}

		// Pre-fill from saved values (edit page)
		if (typeof savedEnv !== 'undefined' && savedEnv[envVar]) {
			input.value = savedEnv[envVar];
		}

		// Mask token/secret fields
		var lowerVar = envVar.toLowerCase();
		if (lowerVar.indexOf('token') !== -1 ||
			lowerVar.indexOf('secret') !== -1 ||
			lowerVar.indexOf('key') !== -1 ||
			lowerVar.indexOf('password') !== -1) {
			input.type = 'password';
			input.autocomplete = 'off';
		}

		var help = document.createElement('p');
		help.className = 'help-block';
		help.textContent = description;

		div.appendChild(label);
		div.appendChild(input);
		div.appendChild(help);

		return div;
	}
})();
