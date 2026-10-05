/* PeekDrive website: language switch (English is the static text, French comes
   from the data-fr attributes) and the uninstall survey. Loaded in <head> so the
   language is known before the first paint. No dependency, no external request.

   Language: an element with data-en and data-fr gets its content from them; an
   attribute listed in ATTRS is translated with data-en-<attr> / data-fr-<attr>.
   Order of precedence: ?lang=fr|en (set by the extension), then the choice saved
   in localStorage, then the browser language. */
(function () {
  'use strict';

  var KEY = 'peekdrive-lang';
  var ATTRS = ['placeholder', 'aria-label', 'alt', 'title'];
  var doc = document;
  var root = doc.documentElement;
  var current = 'en'; // language of the text currently in the page

  function valid(lang) { return lang === 'fr' || lang === 'en'; }

  // ?lang=fr|en (set by the extension) wins over the saved, then the browser language.
  function pickLang() {
    var asked = null;
    var saved = null;
    try {
      var params = new URLSearchParams(location.search);
      asked = params.get('lang');
      if (valid(asked)) {
        // Applied and saved: drop it so a later FR/EN click survives a reload.
        params.delete('lang');
        var rest = params.toString();
        history.replaceState(null, '', location.pathname + (rest ? '?' + rest : '') + location.hash);
      }
    } catch (e) {}
    if (valid(asked)) {
      try { localStorage.setItem(KEY, asked); } catch (e) {}
      return asked;
    }
    try { saved = localStorage.getItem(KEY); } catch (e) {}
    if (valid(saved)) return saved;
    var browser = (navigator.language || navigator.userLanguage || 'en').toLowerCase();
    return browser.indexOf('fr') === 0 ? 'fr' : 'en';
  }

  function markButtons(lang) {
    var buttons = doc.querySelectorAll('.lang-btn');
    for (var i = 0; i < buttons.length; i++) {
      var on = buttons[i].getAttribute('data-lang') === lang;
      buttons[i].classList.toggle('is-active', on);
      buttons[i].setAttribute('aria-pressed', on ? 'true' : 'false');
    }
  }

  function applyLang(lang) {
    var i, j, els;
    if (lang !== current) {
      els = doc.querySelectorAll('[data-' + lang + ']');
      for (i = 0; i < els.length; i++) {
        var value = els[i].getAttribute('data-' + lang);
        if (els[i].tagName === 'TITLE') doc.title = value;
        else els[i].innerHTML = value;
      }
      for (j = 0; j < ATTRS.length; j++) {
        els = doc.querySelectorAll('[data-' + lang + '-' + ATTRS[j] + ']');
        for (i = 0; i < els.length; i++) {
          els[i].setAttribute(ATTRS[j], els[i].getAttribute('data-' + lang + '-' + ATTRS[j]));
        }
      }
      current = lang;
    }
    root.lang = lang;
    markButtons(lang);
  }

  function setLang(lang) {
    if (!valid(lang)) return;
    applyLang(lang);
    try { localStorage.setItem(KEY, lang); } catch (e) {}
    try { doc.dispatchEvent(new CustomEvent('peekdrive:lang', { detail: lang })); } catch (e) {}
  }

  // ── Copy helper: clipboard API, then a selection the visitor can copy by hand.
  function copyText(text, sourceEl, onDone) {
    function bySelection() {
      try {
        var range = doc.createRange();
        range.selectNodeContents(sourceEl);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        if (doc.execCommand('copy')) onDone();
      } catch (e) {}
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(onDone, bySelection);
    } else {
      bySelection();
    }
  }

  // ── Uninstall survey ──────────────────────────────────────────────────────
  function initSurvey() {
    var form = doc.getElementById('survey');
    if (!form || !window.fetch) return;

    var MIN_FILL_MS = 3000; // same threshold as feedback.php (MIN_FILL_MS)
    var TIMEOUT_MS = 12000;
    // Same pattern as feedback.php, so an address the server would refuse is caught here.
    var EMAIL_OK = /^[A-Za-z0-9.!#$%&'*+\/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/;
    var started = Date.now();
    var busy = false;
    var copyTimer = null;

    var sendBtn = form.querySelector('button[type="submit"]');
    var reasonError = doc.getElementById('reason-error');
    var emailError = doc.getElementById('email-error');
    var failBox = doc.getElementById('survey-fail');
    var failText = doc.getElementById('survey-answer');
    var mailLink = doc.getElementById('survey-mail');
    var copyBtn = doc.getElementById('survey-copy');
    var doneBox = doc.getElementById('survey-done');
    var message = form.elements.message;
    var email = form.elements.email;

    function lang() { return root.lang === 'fr' ? 'fr' : 'en'; }
    function text(name) { return failBox.getAttribute('data-' + name + '-' + lang()) || ''; }
    function checked() { return form.querySelector('input[name="reason"]:checked'); }

    function payload() {
      var reason = checked();
      return {
        reason: reason ? reason.value : '',
        message: message.value.replace(/\r\n?/g, '\n').trim(),
        email: email.value.trim(),
        lang: lang(),
        trap: form.elements.trap.value,
        elapsed: Date.now() - started
      };
    }

    // The answer as plain text, for the "send it yourself" fallback.
    function answer() {
      var reason = checked();
      var data = payload();
      var label = reason ? reason.parentNode.querySelector('.choice-label').textContent.trim() : '';
      var lines = [text('reason') + ' ' + label];
      if (data.message) lines.push('', data.message);
      if (data.email) lines.push('', text('email') + ' ' + data.email);
      return lines.join('\n');
    }

    function refreshFallback() {
      if (failBox.hidden) return;
      var body = answer();
      failText.textContent = body;
      mailLink.href = 'mailto:contact@peekdrive.com?subject=' + encodeURIComponent(text('subject')) +
        '&body=' + encodeURIComponent(body.replace(/\n/g, '\r\n'));
    }

    function setBusy(on) {
      busy = on;
      sendBtn.disabled = on;
      sendBtn.setAttribute('aria-busy', on ? 'true' : 'false');
      sendBtn.textContent = sendBtn.getAttribute((on ? 'data-sending-' : 'data-') + lang());
    }

    function showFail() {
      setBusy(false);
      failBox.hidden = false;
      refreshFallback();
      failBox.focus();
    }

    function showDone() {
      setBusy(false);
      form.hidden = true;
      // The intro speaks of "these two questions", which are no longer on screen.
      var lead = doc.querySelector('.page-hero .lead');
      if (lead) lead.hidden = true;
      doneBox.hidden = false;
      doneBox.focus();
    }

    function showEmailError(on) {
      emailError.hidden = !on;
      email.setAttribute('aria-invalid', on ? 'true' : 'false');
      if (on) email.focus();
    }

    function validate() {
      var ok = true;
      var typed = email.value.trim();
      var emailOk = typed === '' || (email.checkValidity() && EMAIL_OK.test(typed));
      showEmailError(!emailOk);
      if (!emailOk) ok = false;
      var hasReason = !!checked();
      reasonError.hidden = hasReason;
      if (!hasReason) { form.querySelector('input[name="reason"]').focus(); ok = false; }
      return ok;
    }

    function post(data) {
      var controller = window.AbortController ? new AbortController() : null;
      var timer = setTimeout(function () { if (controller) controller.abort(); }, TIMEOUT_MS);
      fetch(form.getAttribute('action'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(data),
        credentials: 'same-origin',
        signal: controller ? controller.signal : undefined
      }).then(function (res) {
        return res.json().then(function (json) {
          if (res.ok && json && json.ok === true) return 'ok';
          return json && json.error === 'email' ? 'email' : 'fail';
        });
      }).then(function (result) {
        clearTimeout(timer);
        if (result === 'ok') showDone();
        // The server refused the address: the visitor can correct it and send again.
        else if (result === 'email') { setBusy(false); showEmailError(true); }
        else showFail();
      }, function () {
        clearTimeout(timer);
        showFail();
      });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (busy || !validate()) return;
      failBox.hidden = true;
      setBusy(true);
      // A form sent faster than a person can fill it is refused by the server: wait out the rest.
      var wait = Math.max(0, MIN_FILL_MS - (Date.now() - started));
      setTimeout(function () { post(payload()); }, wait);
    });

    form.addEventListener('change', function (event) {
      if (event.target.name === 'reason') reasonError.hidden = true;
      refreshFallback();
    });
    form.addEventListener('input', function (event) {
      if (event.target === email) showEmailError(false);
      refreshFallback();
    });
    doc.addEventListener('peekdrive:lang', function () {
      // The switch has just put the idle label back on the button: keep "Sending…" while busy.
      if (busy) sendBtn.textContent = sendBtn.getAttribute('data-sending-' + lang());
      refreshFallback();
    });

    copyBtn.addEventListener('click', function () {
      copyText(failText.textContent, failText, function () {
        // Keep the button's width while it says "Copied".
        if (!copyTimer) copyBtn.style.minWidth = copyBtn.offsetWidth + 'px';
        copyBtn.textContent = copyBtn.getAttribute('data-copied-' + lang());
        clearTimeout(copyTimer);
        copyTimer = setTimeout(function () {
          copyTimer = null;
          copyBtn.style.minWidth = '';
          copyBtn.textContent = copyBtn.getAttribute('data-' + lang());
        }, 1800);
      });
    });
  }

  // ── Start ─────────────────────────────────────────────────────────────────
  root.classList.add('js');
  var lang = pickLang();
  root.lang = lang;
  if (lang !== current) {
    // Hide the English text until the French one is in place (see .i18n-wait in site.css).
    root.classList.add('i18n-wait');
    setTimeout(function () { root.classList.remove('i18n-wait'); }, 3000);
  }

  function ready() {
    try { applyLang(lang); } catch (e) {}
    root.classList.remove('i18n-wait');
    var buttons = doc.querySelectorAll('.lang-btn');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].addEventListener('click', function () { setLang(this.getAttribute('data-lang')); });
    }
    initSurvey();
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', ready);
  else ready();
})();
