/**
 * StoryBoard Live — Templates Page JS (Loop 3 Final)
 * - Category tab filtering (keyboard-accessible tablist)
 * - Mobile <select> sync
 * - Live search with debounce + screen-reader announcement
 * - Pro unlock tooltip with focus trap
 * - Zero dependencies; plain ES5-compatible
 */
(function () {
	'use strict';

	/* ── DOM refs ── */
	var grid     = document.getElementById('shseq-tpl-grid');
	var cards    = grid ? Array.prototype.slice.call(grid.querySelectorAll('.shseq-tpl-card')) : [];
	var tabs     = Array.prototype.slice.call(document.querySelectorAll('.shseq-tpl-tab'));
	var search   = document.getElementById('shseq-tpl-search');
	var empty    = document.getElementById('shseq-tpl-empty');
	var clearBtn = document.getElementById('shseq-tpl-clear');
	var srRegion = document.getElementById('shseq-tpl-sr-announce');
	var tooltip  = document.getElementById('shseq-pro-tooltip');
	var catSel   = document.getElementById('shseq-tpl-cat-select');

	var currentCat  = 'all';
	var searchDebounce;

	/* ── State ── */
	function applyFilters() {
		var q = search ? search.value.toLowerCase().trim() : '';
		var visible = 0;
		cards.forEach(function (card) {
			var catMatch = currentCat === 'all' || card.dataset.cat === currentCat;
			var qMatch   = !q || (card.dataset.search || '').indexOf(q) !== -1;
			var show     = catMatch && qMatch;
			if (show) {
				card.removeAttribute('hidden');
				++visible;
			} else {
				card.setAttribute('hidden', '');
			}
		});
		if (empty) {
			if (visible === 0) {
				empty.removeAttribute('hidden');
			} else {
				empty.setAttribute('hidden', '');
			}
		}
		announce(visible === 0
			? 'No templates found.'
			: visible + ' template' + (visible !== 1 ? 's' : '') + ' shown.');
	}

	function announce(msg) {
		if (!srRegion) return;
		srRegion.textContent = '';
		setTimeout(function () { srRegion.textContent = msg; }, 50);
	}

	/* ── Tabs ── */
	tabs.forEach(function (tab, idx) {
		tab.addEventListener('click', function () {
			setActiveTab(tab);
		});
		tab.addEventListener('keydown', function (e) {
			var next;
			if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
				next = tabs[(idx + 1) % tabs.length];
			} else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
				next = tabs[(idx - 1 + tabs.length) % tabs.length];
			} else if (e.key === 'Home') {
				next = tabs[0];
			} else if (e.key === 'End') {
				next = tabs[tabs.length - 1];
			}
			if (next) {
				e.preventDefault();
				setActiveTab(next);
				next.focus();
			}
		});
	});

	function setActiveTab(tab) {
		tabs.forEach(function (t) {
			t.classList.remove('shseq-tpl-tab--active');
			t.setAttribute('aria-selected', 'false');
			t.setAttribute('tabindex', '-1');
		});
		tab.classList.add('shseq-tpl-tab--active');
		tab.setAttribute('aria-selected', 'true');
		tab.setAttribute('tabindex', '0');
		currentCat = tab.dataset.cat || 'all';
		// Sync mobile select
		if (catSel) catSel.value = currentCat;
		applyFilters();
	}

	/* ── Mobile select ── */
	if (catSel) {
		catSel.addEventListener('change', function () {
			currentCat = catSel.value;
			// Sync desktop tabs
			tabs.forEach(function (t) {
				var active = t.dataset.cat === currentCat;
				t.classList.toggle('shseq-tpl-tab--active', active);
				t.setAttribute('aria-selected', active ? 'true' : 'false');
				t.setAttribute('tabindex', active ? '0' : '-1');
			});
			applyFilters();
		});
	}

	/* ── Search ── */
	if (search) {
		search.addEventListener('input', function () {
			clearTimeout(searchDebounce);
			searchDebounce = setTimeout(applyFilters, 220);
		});
	}

	if (clearBtn) {
		clearBtn.addEventListener('click', function () {
			if (search) { search.value = ''; search.focus(); }
			applyFilters();
		});
	}

	/* ── Pro tooltip ── */
	var tooltipTrigger = null;

	function openTooltip(trigger) {
		if (!tooltip) return;
		tooltipTrigger = trigger;

		// Position tooltip below the card's action button
		var rect = trigger.getBoundingClientRect();
		var scrollY = window.pageYOffset || document.documentElement.scrollTop;
		tooltip.style.top    = (rect.bottom + scrollY + 8) + 'px';
		tooltip.style.left   = Math.max(8, rect.left + rect.width / 2 - 120) + 'px';

		tooltip.removeAttribute('hidden');
		tooltip.setAttribute('aria-hidden', 'false');
		trigger.setAttribute('aria-expanded', 'true');

		// Focus first focusable element
		var firstFocus = tooltip.querySelector('a, button');
		if (firstFocus) firstFocus.focus();

		// Focus trap
		document.addEventListener('keydown', trapFocus);
		document.addEventListener('click', outsideClick);
	}

	function closeTooltip() {
		if (!tooltip) return;
		tooltip.setAttribute('hidden', '');
		tooltip.setAttribute('aria-hidden', 'true');
		if (tooltipTrigger) {
			tooltipTrigger.setAttribute('aria-expanded', 'false');
			tooltipTrigger.focus();
			tooltipTrigger = null;
		}
		document.removeEventListener('keydown', trapFocus);
		document.removeEventListener('click', outsideClick);
	}

	function trapFocus(e) {
		if (e.key === 'Escape') { closeTooltip(); return; }
		if (e.key !== 'Tab') return;
		var focusable = Array.prototype.slice.call(
			tooltip.querySelectorAll('a[href], button:not([disabled])'));
		if (!focusable.length) return;
		var first = focusable[0], last = focusable[focusable.length - 1];
		if (e.shiftKey && document.activeElement === first) {
			e.preventDefault(); last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault(); first.focus();
		}
	}

	function outsideClick(e) {
		if (tooltip && !tooltip.contains(e.target) && e.target !== tooltipTrigger) {
			closeTooltip();
		}
	}

	document.querySelectorAll('[data-pro-trigger]').forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			if (tooltipTrigger === btn) { closeTooltip(); return; }
			openTooltip(btn);
		});
	});

	var closeBtn = tooltip ? tooltip.querySelector('.shseq-pro-tooltip__close') : null;
	if (closeBtn) {
		closeBtn.addEventListener('click', closeTooltip);
	}

	/* ── Init ── */
	applyFilters();

}());
