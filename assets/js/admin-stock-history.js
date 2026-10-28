/**
 * Stock History for WooCommerce - inline note editor.
 *
 * Swaps the visible <span> for an <input> on click, persists via wp-ajax
 * (action: shfw_update_note). Uses window.load so the WC Product Data tabs
 * are fully wired before we attach handlers.
 */
(function ($) {
	'use strict';

	var L10N = window.shfwStockHistory || {};
	var MAX  = (L10N.maxLength | 0) || 500;

	function setError($cell, hasError) {
		$cell.toggleClass('is-error', !!hasError);
		if (hasError) {
			setTimeout(function () { $cell.removeClass('is-error'); }, 1500);
		}
	}

	function commit($cell, $input, originalText) {
		var entryId = parseInt($cell.attr('data-entry-id'), 10);
		var newText = $input.val();

		if (!entryId) {
			$input.replaceWith(buildSpan(originalText));
			return;
		}

		if (newText === originalText) {
			$input.replaceWith(buildSpan(newText));
			return;
		}

		$cell.addClass('is-saving');

		$.post(L10N.ajaxurl, {
			action:      'shfw_update_note',
			_ajax_nonce: L10N.nonce,
			entry_id:    entryId,
			note:        newText
		})
			.done(function (response) {
				$cell.removeClass('is-saving');
				if (response && response.success) {
					var saved = (response.data && typeof response.data.note === 'string') ? response.data.note : newText;
					$input.replaceWith(buildSpan(saved));
				} else {
					setError($cell, true);
					$input.replaceWith(buildSpan(originalText));
				}
			})
			.fail(function () {
				$cell.removeClass('is-saving');
				setError($cell, true);
				$input.replaceWith(buildSpan(originalText));
			});
	}

	function buildSpan(text) {
		var $span = $('<span class="shfw-note-text"></span>');
		var emptyLabel = (L10N.i18n && L10N.i18n.clickToAdd) ? L10N.i18n.clickToAdd : '';
		$span.attr('data-empty-label', emptyLabel);
		$span.text(text || '');
		return $span;
	}

	function startEdit(e) {
		var $cell = $(this);
		if ($cell.find('input').length > 0) {
			return; // Already editing.
		}
		if ($(e.target).is('a')) {
			return;
		}

		var $span        = $cell.find('.shfw-note-text');
		var originalText = $span.text();

		var $input = $('<input type="text" />').attr('maxlength', MAX).val(originalText);
		$span.replaceWith($input);
		$input.trigger('focus').trigger('select');

		var committed = false;
		var finish = function () {
			if (committed) { return; }
			committed = true;
			commit($cell, $input, originalText);
		};
		var cancel = function () {
			if (committed) { return; }
			committed = true;
			$input.replaceWith(buildSpan(originalText));
		};

		$input.on('blur', finish);
		$input.on('keydown', function (ev) {
			if (ev.key === 'Enter') {
				ev.preventDefault();
				finish();
			} else if (ev.key === 'Escape') {
				ev.preventDefault();
				cancel();
			}
		});
	}

	window.addEventListener('load', function () {
		if (window.location.hash === '#shfw_stock_history_data') {
			$('ul.wc-tabs a[href="#shfw_stock_history_data"]').trigger('click');
		}
		$(document).on('click', '.shfw-stock-history-note', startEdit);
	});
})(jQuery);
