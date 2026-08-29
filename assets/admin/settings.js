/**
 * StoryBoard Live — Settings page v3
 * No jQuery dependency.
 */
(function () {
  'use strict';

  var cfg = window.shseqSettings || {};
  var i18n = cfg.i18n || {};

  // ── API Key: reveal/hide toggle ────────────────────────────────────────
  document.querySelectorAll('.shseq-api-field__reveal').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var wrap  = btn.closest('.shseq-api-field__input-wrap');
      var input = wrap.querySelector('.shseq-api-field__input');
      var icon  = btn.querySelector('.dashicons');
      var isRevealed = btn.getAttribute('aria-pressed') === 'true';

      if (isRevealed) {
        input.type = 'password';
        btn.setAttribute('aria-pressed', 'false');
        btn.setAttribute('aria-label', i18n.reveal || 'Reveal key');
        icon.classList.remove('dashicons-hidden');
        icon.classList.add('dashicons-visibility');
      } else {
        input.type = 'text';
        btn.setAttribute('aria-pressed', 'true');
        btn.setAttribute('aria-label', i18n.hide || 'Hide key');
        icon.classList.remove('dashicons-visibility');
        icon.classList.add('dashicons-hidden');
      }
    });
  });

  // ── API Key: test connection ───────────────────────────────────────────
  document.querySelectorAll('.shseq-api-field__test').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var field   = btn.closest('.shseq-api-field');
      var action  = field.dataset.action;
      var inputId = field.dataset.field;
      var input   = document.getElementById(inputId);
      var result  = field.querySelector('.shseq-api-field__result');
      var icon    = btn.querySelector('.shseq-api-field__test-icon');
      var key     = input ? input.value.trim() : '';

      if (!key) {
        setResult(result, i18n.enterKey || 'Enter a key first.', false);
        return;
      }

      btn.disabled = true;
      icon && icon.classList.add('spin');
      result.className = 'shseq-api-field__result';
      result.textContent = i18n.testing || 'Testing…';

      var body = new URLSearchParams({
        action:   action,
        api_key:  key,
        _nonce:   cfg.nonce || ''
      });

      fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          setResult(result, res.data && res.data.message ? res.data.message : '', res.success);
        })
        .catch(function () {
          setResult(result, i18n.requestFail || 'Request failed.', false);
        })
        .finally(function () {
          btn.disabled = false;
          icon && icon.classList.remove('spin');
        });
    });
  });

  function setResult(el, msg, success) {
    el.textContent = msg;
    el.className = 'shseq-api-field__result ' + (success ? 'is-success' : 'is-error');
  }

  // ── System Info: copy to clipboard ────────────────────────────────────
  var copyBtn = document.getElementById('shseq-copy-sysinfo');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var feedback = document.querySelector('.shseq-sysinfo-copy-feedback');
      var raw = copyBtn.dataset.sysinfo || '';
      var text;
      try {
        var obj = JSON.parse(raw);
        text = Object.entries(obj).map(function (e) { return e[0] + ': ' + e[1]; }).join('\n');
      } catch (e) {
        text = raw;
      }

      if (navigator.clipboard) {
        navigator.clipboard.writeText(text)
          .then(function () { showFeedback(feedback, i18n.copied || 'Copied!', true); })
          .catch(function () { showFeedback(feedback, i18n.copyFail || 'Copy failed.', false); });
      } else {
        try {
          var ta = document.createElement('textarea');
          ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
          document.body.appendChild(ta); ta.select();
          document.execCommand('copy');
          document.body.removeChild(ta);
          showFeedback(feedback, i18n.copied || 'Copied!', true);
        } catch (e) {
          showFeedback(feedback, i18n.copyFail || 'Copy failed.', false);
        }
      }
    });
  }

  function showFeedback(el, msg, ok) {
    if (!el) return;
    el.textContent = msg;
    el.style.color = ok ? '#00a32a' : '#d63638';
    setTimeout(function () { el.textContent = ''; }, 3000);
  }

}());
