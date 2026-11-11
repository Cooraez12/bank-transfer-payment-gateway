/**
 * RT Bank Transfer: settings screen (payment accounts).
 *
 * Class names used here are fixed ("bank-transfer-...") and match the markup
 * printed by generate_accounts_repeater_html(). They do not depend on the
 * gateway id, so renaming the gateway id can never disconnect the buttons.
 */
jQuery(document).ready(function ($) {

	if (typeof bank_transfer_admin_data === 'undefined' || typeof bank_transfer_admin_data.gateway_id !== 'string') {
		return;
	}

	var data = bank_transfer_admin_data;
	var gatewayId = data.gateway_id;
	var t = data.i18n || {};
	var statusTexts = data.statuses || {};

	var $form = $('form#mainform');
	if (!$form.length || !$form.find('input[name^="woocommerce_' + gatewayId + '_"]').length) {
		return;
	}
	$form.addClass('bank-transfer-gateway-settings-form');

	var ACCOUNT = '.bank-transfer-account';
	var CONTAINER = '.bank-transfer-accounts-container';
	var INFO = '.bank-transfer-info';
	var TOGGLE = '.bank-transfer-toggle-btn';
	var SANDBOX_CHECKBOX = '.bank-transfer-sandbox-checkbox';
	var SANDBOX_KEYS = '.bank-transfer-sandbox-keys';

	function esc(text) {
		return $('<div>').text(text == null ? '' : String(text)).html();
	}

	function showError($field, message) {
		$field.addClass('error');
		var $target = $field.closest('.secret-key-wrapper');
		if (!$target.length) {
			$target = $field;
		}
		$target.siblings('.error-message').remove();
		$('<div>').addClass('error-message').attr('role', 'alert').text(message).insertAfter($target);
	}

	function clearErrors() {
		$form.find('.error-message').remove();
		$form.find('.error').removeClass('error');
		$('#bank-transfer-global-error').text('');
	}

	function sandboxModeOn() {
		return $('#woocommerce_' + gatewayId + '_sandbox').is(':checked');
	}

	/* Each account's fields are named accounts[<index>][field]. */
	function updateAccountIndices() {
		$(ACCOUNT).each(function (index) {
			$(this).attr('data-index', index);
			$(this).find('input, select, textarea').each(function () {
				var name = $(this).attr('name');
				if (name && name.indexOf('accounts[') === 0) {
					$(this).attr('name', name.replace(/^accounts\[[^\]]*\]/, 'accounts[' + index + ']'));
				}
			});
		});
	}

	function uniqueId() {
		var bytes = '';
		if (window.crypto && window.crypto.getRandomValues) {
			var arr = new Uint8Array(12);
			window.crypto.getRandomValues(arr);
			for (var i = 0; i < arr.length; i++) {
				bytes += ('0' + arr[i].toString(16)).slice(-2);
			}
		} else {
			bytes = String(Date.now()) + String(Math.floor(Math.random() * 1e9));
		}
		return 'acc_' + bytes;
	}

	function secretBox(cssClass, field) {
		return '' +
			'<div class="secret-key-wrapper" style="position: relative; display: flex; align-items: center;">' +
				'<input type="password" autocomplete="new-password" class="' + cssClass + '" name="accounts[][' + field + ']" placeholder="' + esc(t.secret_key) + '" data-has-existing="0" style="padding-right: 30px;">' +
				'<button type="button" class="toggle-secret-visibility" style="position: absolute; right: 15px; top: calc(50% - 4px); background: none; border: none; cursor: pointer; color: #555; padding: 0; outline: none;" title="' + esc(t.show_hide) + '" aria-label="' + esc(t.show_hide) + '"><i class="fa fa-eye" aria-hidden="true"></i></button>' +
			'</div>';
	}

	function newAccountHtml() {
		var position = $(ACCOUNT).length + 1;
		var checkboxId = 'bank-transfer-sandbox-checkbox-new-' + Date.now();
		// A shop in test mode most likely wants to paste sandbox keys first.
		var sandboxFirst = sandboxModeOn();

		return '' +
			'<div class="bank-transfer-account bank-transfer-account-new">' +
				'<input type="hidden" class="live-status" name="accounts[][live_status]" value="unknown">' +
				'<input type="hidden" class="sandbox-status" name="accounts[][sandbox_status]" value="unknown">' +
				'<div class="title-blog">' +
					'<h4>' +
						'<span class="account-name-display bank-transfer-name-display">' + esc(t.new_account) + '</span>' +
						'&nbsp;<i class="fa fa-caret-down bank-transfer-toggle-btn account-toggle-btn rotated" aria-hidden="true"></i>' +
					'</h4>' +
					'<div class="action-button">' +
						'<button type="button" class="delete-account-btn" title="' + esc(t.remove) + '" aria-label="' + esc(t.remove) + '"><i class="fa fa-trash" aria-hidden="true"></i></button>' +
					'</div>' +
				'</div>' +
				'<div class="bank-transfer-info account-info" style="display: block;">' +
					'<p class="description">' + esc(t.new_account_hint) + '</p>' +
					'<div class="add-blog title-priority">' +
						'<div class="account-input account-name">' +
							'<label>' + esc(t.account_name) + '</label>' +
							'<input type="text" class="account-title" name="accounts[][title]" placeholder="' + esc(t.account_name_hint) + '">' +
						'</div>' +
						'<div><input type="hidden" class="unique-id" name="accounts[][unique_id]" value="' + esc(uniqueId()) + '" readonly></div>' +
						'<div class="account-input priority-name">' +
							'<label>' + esc(t.order_of_use) + '</label>' +
							'<input type="number" class="account-priority" name="accounts[][priority]" min="1" value="' + position + '">' +
						'</div>' +
					'</div>' +
					'<div class="add-blog"><div class="account-input">' +
						'<label>' + esc(t.checkout_title) + '</label>' +
						'<input type="text" name="accounts[][checkout_title]" placeholder="' + esc(t.checkout_title_hint) + '" value="">' +
					'</div></div>' +
					'<div class="add-blog"><div class="account-input">' +
						'<label>' + esc(t.checkout_note) + '</label>' +
						'<textarea class="checkout-subtitle" name="accounts[][checkout_subtitle]" placeholder="' + esc(t.checkout_note_hint) + '" rows="2"></textarea>' +
					'</div></div>' +
					'<div class="add-blog bank-transfer-production-keys">' +
						'<div class="account-input">' +
							'<label>' + esc(t.live_keys) + '</label>' +
							'<input type="text" class="live-public-key" name="accounts[][live_public_key]" placeholder="' + esc(t.publishable_key) + '">' +
						'</div>' +
						'<div class="account-input">' + secretBox('live-secret-key', 'live_secret_key') + '</div>' +
					'</div>' +
					'<div class="account-checkbox">' +
						'<input type="checkbox" class="bank-transfer-sandbox-checkbox" id="' + checkboxId + '" name="accounts[][has_sandbox]"' + (sandboxFirst ? ' checked' : '') + '>' +
						' <label for="' + checkboxId + '">' + esc(t.have_sandbox) + '</label>' +
					'</div>' +
					'<div class="bank-transfer-sandbox-keys"' + (sandboxFirst ? '' : ' style="display: none;"') + '>' +
						'<div class="add-blog">' +
							'<div class="account-input">' +
								'<label>' + esc(t.sandbox_keys) + '</label>' +
								'<input type="text" class="sandbox-public-key" name="accounts[][sandbox_public_key]" placeholder="' + esc(t.publishable_key) + '">' +
							'</div>' +
							'<div class="account-input">' + secretBox('sandbox-secret-key', 'sandbox_secret_key') + '</div>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>';
	}

	/* Add an account. */
	$(document).on('click', '.bank-transfer-add-account', function (e) {
		e.preventDefault();
		$(CONTAINER + ' .empty-account').remove();
		$('#bank-transfer-global-error').text('');
		var $new = $(newAccountHtml());
		$(this).closest('.add-account-btn').before($new);
		updateAccountIndices();
		$new.find('.account-title').trigger('focus');
	});

	/* Remove an account (takes effect when the settings are saved). */
	$(document).on('click', CONTAINER + ' .delete-account-btn', function (e) {
		e.preventDefault();
		var $account = $(this).closest(ACCOUNT);
		var isNew = $account.hasClass('bank-transfer-account-new');
		if (!isNew && !window.confirm(t.confirm_remove)) {
			return;
		}
		$account.remove();
		updateAccountIndices();
		if (!$(ACCOUNT).length) {
			$('#bank-transfer-global-error').text(t.removed_last);
		}
	});

	/* Show or hide the sandbox key boxes. */
	$(document).on('change', SANDBOX_CHECKBOX, function () {
		$(this).closest(ACCOUNT).find(SANDBOX_KEYS).toggle($(this).is(':checked'));
	});

	/* Open or close an account. */
	$(document).on('click', CONTAINER + ' ' + TOGGLE + ', ' + CONTAINER + ' .account-name-display', function () {
		var $account = $(this).closest(ACCOUNT);
		$account.find(INFO).slideToggle();
		$account.find(TOGGLE).toggleClass('rotated');
	});

	/* Keep the heading in step with the account name. */
	$(document).on('input', ACCOUNT + ' .account-title', function () {
		var title = $.trim($(this).val()) || t.new_account;
		$(this).closest(ACCOUNT).find('.account-name-display').text(title);
	});

	/* Show or hide a secret key while typing it. */
	$(document).on('click', CONTAINER + ' .toggle-secret-visibility', function (e) {
		e.preventDefault();
		var $input = $(this).closest('.secret-key-wrapper').find('input');
		var $icon = $(this).find('i');
		if ($input.attr('type') === 'password') {
			$input.attr('type', 'text');
			$icon.removeClass('fa-eye').addClass('fa-eye-slash');
		} else {
			$input.attr('type', 'password');
			$icon.removeClass('fa-eye-slash').addClass('fa-eye');
		}
	});

	/* Check the form before it is sent. The server checks everything again. */
	$form.on('submit', function (event) {
		clearErrors();

		var hasErrors = false;
		var seenKeys = {};
		var seenTitles = {};
		var seenOrder = {};

		function fail($field, message) {
			showError($field, message);
			hasErrors = true;
		}

		function checkUnique($field, value) {
			if (!value) {
				return;
			}
			if (seenKeys[value]) {
				fail($field, t.key_used);
			} else {
				seenKeys[value] = true;
			}
		}

		var $title = $('#woocommerce_' + gatewayId + '_title');
		if ($title.length && !$.trim($title.val())) {
			fail($title, t.need_title);
		}
		var $description = $('#woocommerce_' + gatewayId + '_description');
		if ($description.length && !$.trim($description.val())) {
			fail($description, t.need_description);
		}

		$(ACCOUNT).each(function () {
			var $account = $(this);
			var $name = $account.find('.account-title');
			var $order = $account.find('.account-priority');
			var $livePublic = $account.find('.live-public-key');
			var $liveSecret = $account.find('.live-secret-key');
			var $sandboxPublic = $account.find('.sandbox-public-key');
			var $sandboxSecret = $account.find('.sandbox-secret-key');
			var sandboxTicked = $account.find(SANDBOX_CHECKBOX).is(':checked');

			var name = $.trim($name.val() || '');
			var order = $.trim($order.val() || '');
			var livePublic = $.trim($livePublic.val() || '');
			var liveSecret = $.trim($liveSecret.val() || '');
			var sandboxPublic = $.trim($sandboxPublic.val() || '');
			var sandboxSecret = $.trim($sandboxSecret.val() || '');
			var liveSecretSaved = String($liveSecret.data('has-existing')) === '1';
			var sandboxSecretSaved = String($sandboxSecret.data('has-existing')) === '1';

			var accountHasError = false;
			function failHere($field, message) {
				accountHasError = true;
				fail($field, message);
			}

			if (!name) {
				failHere($name, t.need_name);
			} else if (seenTitles[name.toLowerCase()]) {
				failHere($name, t.name_used);
			} else {
				seenTitles[name.toLowerCase()] = true;
			}

			if (!order) {
				failHere($order, t.need_order);
			} else if (seenOrder[order]) {
				failHere($order, t.order_used);
			} else {
				seenOrder[order] = true;
			}

			var hasLive = !!livePublic && (!!liveSecret || liveSecretSaved);
			var liveStarted = !!livePublic || !!liveSecret;
			var hasSandbox = sandboxTicked && !!sandboxPublic && (!!sandboxSecret || sandboxSecretSaved);

			if (liveStarted && !hasLive) {
				failHere(livePublic ? $liveSecret : $livePublic, t.need_both_live);
			}
			if (sandboxTicked && !hasSandbox) {
				failHere(sandboxPublic ? $sandboxSecret : $sandboxPublic, t.need_both_sandbox);
			}
			if (!liveStarted && !sandboxTicked) {
				failHere($livePublic, t.need_keys);
			}

			checkUnique($livePublic, livePublic);
			checkUnique($liveSecret, liveSecret);
			if (sandboxTicked) {
				checkUnique($sandboxPublic, sandboxPublic);
				checkUnique($sandboxSecret, sandboxSecret);
			}

			if (livePublic && liveSecret && livePublic === liveSecret) {
				failHere($liveSecret, t.same_keys);
			}
			if (sandboxTicked && sandboxPublic && sandboxSecret && sandboxPublic === sandboxSecret) {
				failHere($sandboxSecret, t.same_keys);
			}

			// A closed account with a problem is opened so the message is seen.
			if (accountHasError || $account.find('.error').length) {
				$account.find(INFO).show();
			}
		});

		if (hasErrors) {
			event.preventDefault();
			event.stopImmediatePropagation();
			$('#bank-transfer-global-error').text(t.fix_errors);
			$form.find('[type="submit"]').removeClass('is-busy').prop('disabled', false);
			var $first = $form.find('.error').first();
			if ($first.length && $first[0].scrollIntoView) {
				$first[0].scrollIntoView({ block: 'center' });
			}
		}
	});

	/* Ask RT whether the saved keys are accepted. Only runs when asked. */
	function setStatusLabel($account, status) {
		var key = String(status || '').toLowerCase();
		if (!statusTexts[key]) {
			key = 'unknown';
		}
		var mode = sandboxModeOn() ? t.sandbox : t.live;
		$account.find('.account-status-label')
			.removeClass('active pending inactive invalid unknown not-connected')
			.addClass(key.replace('_', '-'))
			.attr('title', statusTexts[key].help)
			.text(mode + ': ' + statusTexts[key].label);
	}

	function applyStatuses(statuses) {
		if (!Array.isArray(statuses)) {
			return;
		}
		var wantedMode = sandboxModeOn() ? 'sandbox' : 'live';
		statuses.forEach(function (item) {
			$(ACCOUNT).each(function () {
				var $account = $(this);
				if ($.trim($account.find('.account-title').val()) !== item.title) {
					return;
				}
				$account.find(item.mode === 'live' ? '.live-status' : '.sandbox-status').val(item.status);
				if (item.mode === wantedMode) {
					setStatusLabel($account, item.status);
				}
			});
		});
	}

	function runAccountSync() {
		var $button = $('#' + gatewayId + '-sync-accounts');
		var $status = $('#' + gatewayId + '-sync-status');
		if (!$button.length || $button.prop('disabled')) {
			return;
		}
		var buttonHtml = $button.html();

		$button.prop('disabled', true);
		$status.removeClass('error success').text(t.checking).show();

		$.ajax({
			url: data.ajax_url,
			method: 'POST',
			dataType: 'json',
			data: { action: data.sync_action, nonce: data.nonce }
		}).done(function (response) {
			var payload = (response && response.data) || {};
			applyStatuses(payload.statuses);
			if (response && response.success) {
				$status.removeClass('error').addClass('success').text(payload.message || t.check_done);
			} else {
				$status.removeClass('success').addClass('error').text(payload.message || t.check_failed);
			}
		}).fail(function (xhr) {
			var payload = (xhr.responseJSON && xhr.responseJSON.data) || {};
			applyStatuses(payload.statuses);
			$status.removeClass('success').addClass('error').text(payload.message || t.check_unreachable);
		}).always(function () {
			$button.prop('disabled', false).html(buttonHtml);
		});
	}

	$(document).on('click', '#' + gatewayId + '-sync-accounts', function (e) {
		e.preventDefault();
		runAccountSync();
	});
});
