/**
 * StoryBoard Live — All Sequences Page JS (Loop 3 Final)
 *
 * Features:
 *  - Live search with debounce + screen-reader announcement
 *  - Select-all + per-row checkbox → bulk bar
 *  - Accessible confirm before bulk delete
 *  - Copy shortcode with toast feedback (Clipboard API + execCommand fallback)
 *  - Inline rename dialog with AJAX uniqueness check + focus trap
 *  - Duplicate sequence via AJAX with optimistic redirect
 *  - Sync top/bottom select-all checkboxes
 */
(function () {
	'use strict';

	var cfg = window.shseqSeq || {};
	var i18n = cfg.i18n || {};

	/* ── DOM ── */
	var searchInput   = document.getElementById('shseq-seq-search');
	var tableBody     = document.querySelector('#shseq-seq-table tbody');
	var rows          = tableBody ? Array.prototype.slice.call(tableBody.querySelectorAll('.shseq-seq-row')) : [];
	var srAnnounce    = document.getElementById('shseq-sr-announce');
	var toast         = document.getElementById('shseq-toast');
	var bulkBar       = document.getElementById('shseq-bulk-bar');
	var bulkCount     = document.getElementById('shseq-bulk-count');
	var bulkForm      = document.getElementById('shseq-bulk-form');
	var bulkDeleteBtn = document.getElementById('shseq-bulk-delete-btn');
	var bulkCancel    = document.getElementById('shseq-bulk-cancel');
	var selectAllTop  = document.getElementById('shseq-select-all');
	var selectAllBot  = document.getElementById('shseq-select-all-bottom');
	var rowCheckboxes = tableBody ? Array.prototype.slice.call(tableBody.querySelectorAll('.shseq-row-cb')) : [];

	// Rename dialog
	var renameDialog  = document.getElementById('shseq-rename-dialog');
	var renameOverlay = document.getElementById('shseq-rename-overlay');
	var renameInput   = document.getElementById('shseq-rename-input');
	var renameError   = document.getElementById('shseq-rename-error');
	var renameSave    = document.getElementById('shseq-rename-save');
	var renameCancel  = document.getElementById('shseq-rename-cancel');
	var _renamePostId = null;
	var _renameTitleEl= null;

	var searchDebounce;
	var toastTimeout;

	/* ── Toast ── */
	function showToast(msg, isError) {
		if (!toast) return;
		clearTimeout(toastTimeout);
		toast.textContent = msg;
		toast.classList.toggle('is-error', !!isError);
		toast.removeAttribute('hidden');
		// Force reflow
		toast.offsetHeight; // eslint-disable-line
		toast.classList.add('is-visible');
		toastTimeout = setTimeout(function () {
			toast.classList.remove('is-visible');
			setTimeout(function () { toast.setAttribute('hidden', ''); }, 250);
		}, 2800);
	}

	function announce(msg) {
		if (!srAnnounce) return;
		srAnnounce.textContent = '';
		setTimeout(function () { srAnnounce.textContent = msg; }, 60);
	}

	/* ── Search ── */
	if (searchInput) {
		searchInput.addEventListener('input', function () {
			clearTimeout(searchDebounce);
			searchDebounce = setTimeout(applySearch, 200);
		});
	}

	function applySearch() {
		var q = searchInput ? searchInput.value.toLowerCase().trim() : '';
		var visible = 0;
		rows.forEach(function (row) {
			var haystack = row.dataset.search || '';
			var show = !q || haystack.indexOf(q) !== -1;
			if (show) {
				row.removeAttribute('hidden');
				++visible;
			} else {
				row.setAttribute('hidden', '');
			}
		});
		var msg = visible === 0
			? (i18n.noResults || 'No sequences match your search.')
			: (i18n.resultCount || '%d sequence(s) shown.').replace('%d', visible);
		announce(msg);
		// Update visible count display
		var countEl = document.querySelector('.shseq-result-count');
		if (countEl) countEl.textContent = msg;
	}

	/* ── Checkboxes + Bulk bar ── */
	function updateBulkBar() {
		var checked = rowCheckboxes.filter(function (cb) { return cb.checked && !cb.closest('tr[hidden]'); });
		var n = checked.length;
		if (bulkBar) {
			if (n > 0) {
				bulkBar.removeAttribute('hidden');
			} else {
				bulkBar.setAttribute('hidden', '');
			}
		}
		if (bulkCount) {
			bulkCount.textContent = n + ' ' + (n === 1 ? 'selected' : 'selected');
		}
		// Sync select-all indeterminate state
		var allChecked = rowCheckboxes.length > 0 && n === rowCheckboxes.length;
		[selectAllTop, selectAllBot].forEach(function (sa) {
			if (!sa) return;
			sa.checked = allChecked;
			sa.indeterminate = n > 0 && !allChecked;
		});
	}

	rowCheckboxes.forEach(function (cb) {
		cb.addEventListener('change', updateBulkBar);
	});

	[selectAllTop, selectAllBot].forEach(function (sa) {
		if (!sa) return;
		sa.addEventListener('change', function () {
			var checked = sa.checked;
			rowCheckboxes.forEach(function (cb) {
				if (!cb.closest('tr[hidden]')) cb.checked = checked;
			});
			// Sync the other select-all
			[selectAllTop, selectAllBot].forEach(function (other) {
				if (other && other !== sa) other.checked = checked;
			});
			updateBulkBar();
		});
	});

	if (bulkCancel) {
		bulkCancel.addEventListener('click', function () {
			rowCheckboxes.forEach(function (cb) { cb.checked = false; });
			[selectAllTop, selectAllBot].forEach(function (sa) { if (sa) { sa.checked = false; sa.indeterminate = false; } });
			updateBulkBar();
		});
	}

	// Accessible bulk delete confirm
	if (bulkForm && bulkDeleteBtn) {
		bulkForm.addEventListener('submit', function (e) {
			var checked = rowCheckboxes.filter(function (cb) { return cb.checked; });
			if (checked.length === 0) { e.preventDefault(); return; }
			if (!window.confirm(i18n.confirmDelete || 'Delete the selected sequences permanently? This cannot be undone.')) {
				e.preventDefault();
			}
		});
	}

	/* ── Shortcode copy ── */
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.shseq-copy-btn');
		if (!btn) return;
		var text = btn.dataset.copy;
		if (!text) return;
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () {
				showToast(i18n.copied || 'Copied!');
			}).catch(function () {
				fallbackCopy(text);
			});
		} else {
			fallbackCopy(text);
		}
	});

	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.left = '-9999px';
		document.body.appendChild(ta);
		ta.focus();
		ta.select();
		try {
			var ok = document.execCommand('copy');
			showToast(ok ? (i18n.copied || 'Copied!') : (i18n.copyFail || 'Copy failed — press Ctrl+C'), !ok);
		} catch (err) {
			showToast(i18n.copyFail || 'Copy failed — press Ctrl+C', true);
		}
		document.body.removeChild(ta);
	}

	/* ── Inline rename ── */
	function openRenameDialog(postId, titleEl) {
		if (!renameDialog) return;
		_renamePostId  = postId;
		_renameTitleEl = titleEl;
		renameInput.value = titleEl ? titleEl.textContent.trim() : '';
		hideRenameError();
		renameDialog.removeAttribute('hidden');
		renameOverlay.removeAttribute('hidden');
		renameInput.focus();
		renameInput.select();
		document.addEventListener('keydown', renameFocusTrap);
	}

	function closeRenameDialog() {
		if (!renameDialog) return;
		renameDialog.setAttribute('hidden', '');
		renameOverlay.setAttribute('hidden', '');
		document.removeEventListener('keydown', renameFocusTrap);
		if (_renameTitleEl) _renameTitleEl.focus();
		_renamePostId  = null;
		_renameTitleEl = null;
	}

	function showRenameError(msg) {
		if (!renameError) return;
		renameError.textContent = msg;
		renameError.removeAttribute('hidden');
		renameInput.setAttribute('aria-invalid', 'true');
	}
	function hideRenameError() {
		if (!renameError) return;
		renameError.setAttribute('hidden', '');
		renameInput.removeAttribute('aria-invalid');
	}

	function renameFocusTrap(e) {
		if (e.key === 'Escape') { closeRenameDialog(); return; }
		if (e.key !== 'Tab') return;
		var focusable = Array.prototype.slice.call(
			renameDialog.querySelectorAll('input, button:not([disabled])'));
		if (!focusable.length) return;
		var first = focusable[0], last = focusable[focusable.length - 1];
		if (e.shiftKey && document.activeElement === first) {
			e.preventDefault(); last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault(); first.focus();
		}
	}

	// Double-click on title to rename
	document.addEventListener('dblclick', function (e) {
		var titleEl = e.target.closest('.shseq-seq-title');
		if (!titleEl) return;
		var row    = titleEl.closest('tr');
		var postId = row ? row.dataset.postId : null;
		if (!postId) return;
		openRenameDialog(postId, titleEl);
	});

	// Keyboard: Enter/Space on inline-rename span
	document.addEventListener('keydown', function (e) {
		if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('shseq-inline-rename')) {
			e.preventDefault();
			var row    = e.target.closest('tr');
			var postId = row ? row.dataset.postId : null;
			var titleEl = row ? row.querySelector('.shseq-seq-title') : null;
			if (postId) openRenameDialog(postId, titleEl);
		}
	});

	if (renameCancel) renameCancel.addEventListener('click', closeRenameDialog);
	if (renameOverlay) renameOverlay.addEventListener('click', closeRenameDialog);

	if (renameSave) {
		renameSave.addEventListener('click', function () {
			var name = renameInput ? renameInput.value.trim() : '';
			if (!name) { showRenameError(i18n.renameEmpty || 'Name cannot be empty.'); return; }
			hideRenameError();
			renameSave.disabled = true;

			var fd = new FormData();
			fd.append('action', 'shseq_inline_rename');
			fd.append('nonce', cfg.nonceRename || '');
			fd.append('post_id', _renamePostId || '');
			fd.append('name', name);

			fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					renameSave.disabled = false;
					if (res.success) {
						if (_renameTitleEl) _renameTitleEl.textContent = res.data.name;
						showToast(i18n.renameSaved || 'Name saved.');
						closeRenameDialog();
					} else {
						var msg = (res.data && res.data.message) || 'Error saving name.';
						if (res.data && res.data.code === 'duplicate') {
							showRenameError(i18n.renameDupe || msg);
						} else {
							showRenameError(msg);
						}
					}
				})
				.catch(function () {
					renameSave.disabled = false;
					showRenameError('Network error. Please try again.');
				});
		});

		// Enter key in input = save
		if (renameInput) {
			renameInput.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); renameSave.click(); }
			});
		}
	}

	/* ── Duplicate ── */
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.shseq-duplicate-btn');
		if (!btn) return;
		var postId = btn.dataset.postId;
		if (!postId) return;
		btn.disabled = true;

		var fd = new FormData();
		fd.append('action', 'shseq_duplicate_sequence');
		fd.append('nonce', cfg.nonceDuplicate || '');
		fd.append('post_id', postId);

		fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				btn.disabled = false;
				if (res.success) {
					showToast(res.data.message || 'Duplicated!');
					// Offer redirect to new draft after 2s
					setTimeout(function () {
						if (res.data.listUrl) window.location = res.data.listUrl;
					}, 1800);
				} else {
					showToast((res.data && res.data.message) || 'Duplicate failed.', true);
				}
			})
			.catch(function () {
				btn.disabled = false;
				showToast('Network error.', true);
			});
	});

	/* ── Init ── */
	updateBulkBar();

}());
