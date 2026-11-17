/* Download form and privacy notice bar.
   Download buttons (.dl-link) open a short form; on submit the details go to /api/download.php,
   which stores them on the server and hands back the file link. Once someone has filled it in,
   later downloads in the same browser tab session go straight through. Without JavaScript the
   buttons stay plain links to the CDN. */
(function () {
  var NOTICE_KEY = 'vx_privacy_notice', NOTICE_VER = '2026-11-17', DONE_KEY = 'vx_dl_form';

  function get(store, k) { try { return window[store].getItem(k); } catch (e) { return null; } }
  function set(store, k, v) { try { window[store].setItem(k, v); } catch (e) {} }

  /* ---------- privacy notice bar ---------- */
  if (get('localStorage', NOTICE_KEY) !== NOTICE_VER) {
    var bar = document.createElement('div');
    bar.className = 'vx-notice';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Privacy notice');
    bar.innerHTML = '<p><b>No tracking or advertising cookies.</b> This site keeps only what it needs to work: a note that you saw this message, and, if you download, the details you give in the download form. Fonts load from Google Fonts and the site is served through Cloudflare.</p>'
      + '<a class="btn btn-ghost btn-sm" href="privacy.html">Privacy notice</a>'
      + '<button type="button" class="btn btn-primary btn-sm">OK</button>';
    bar.querySelector('button').addEventListener('click', function () {
      set('localStorage', NOTICE_KEY, NOTICE_VER);
      bar.remove();
    });
    document.body.appendChild(bar);
  }

  /* ---------- download form ---------- */
  var links = document.querySelectorAll('a.dl-link');
  if (!links.length || typeof HTMLDialogElement !== 'function') return;

  var dlg = document.createElement('dialog');
  dlg.className = 'vx-dlg';
  dlg.setAttribute('aria-labelledby', 'vx-dlg-title');
  dlg.innerHTML =
    '<form novalidate>'
    + '<div><h2 id="vx-dlg-title">Before you download</h2><div class="vx-file" id="vx-file"></div></div>'
    + '<p>Tell us who is using VESTIGEX. We use this to understand who the tool serves and, if needed, to contact you about this release. We never sell or share it.</p>'
    + field('vx-name', 'name', 'Name', 'text', 'name', 120)
    + field('vx-org', 'organization', 'Organization', 'text', 'organization', 160)
    + field('vx-email', 'email', 'Work email', 'email', 'email', 254)
    + '<div class="vx-hp" aria-hidden="true"><label for="vx-web">Website</label><input id="vx-web" name="website" tabindex="-1" autocomplete="off"></div>'
    + '<div><label class="vx-check"><input type="checkbox" name="consent" id="vx-consent"><span>I have read the <a href="privacy.html" target="_blank" rel="noopener">privacy notice</a> and agree to VESTIGEX keeping these details for the purposes it describes.</span></label><div class="vx-err" id="vx-consent-err"></div></div>'
    + '<div class="vx-status" id="vx-status" role="alert"></div>'
    + '<div class="vx-actions"><button type="button" class="btn btn-ghost btn-sm" value="cancel">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Download</button></div>'
    + '</form>';
  document.body.appendChild(dlg);

  function field(id, name, label, type, auto, max) {
    return '<div class="vx-field"><label for="' + id + '">' + label + '</label>'
      + '<input id="' + id + '" name="' + name + '" type="' + type + '" autocomplete="' + auto + '" maxlength="' + max + '" required aria-describedby="' + id + '-err">'
      + '<div class="vx-err" id="' + id + '-err"></div></div>';
  }

  var form = dlg.querySelector('form'), status = dlg.querySelector('#vx-status'),
      submit = form.querySelector('button[type=submit]'), current = null;
  var map = { name: 'vx-name', organization: 'vx-org', email: 'vx-email', consent: 'vx-consent' };

  function go(url) { window.location.href = url; }
  function fields() {
    return { name: document.getElementById('vx-name'), organization: document.getElementById('vx-org'), email: document.getElementById('vx-email'),
      consent: document.getElementById('vx-consent'), website: document.getElementById('vx-web') };
  }

  function showErr(key, msg) {
    var input = document.getElementById(map[key]), err = document.getElementById(map[key] + '-err');
    if (err) err.textContent = msg || '';
    if (input) { if (msg) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid'); }
  }

  function check() {
    var bad = null, f = fields();
    var tests = {
      name: f.name.value.trim() ? '' : 'Enter your name.',
      organization: f.organization.value.trim() ? '' : 'Enter your organization.',
      email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.value.trim()) ? '' : 'Enter a valid email address.',
      consent: f.consent.checked ? '' : 'Please agree to the privacy notice to continue.'
    };
    for (var k in tests) { showErr(k, tests[k]); if (tests[k] && !bad) bad = k; }
    if (bad) document.getElementById(map[bad]).focus();
    return !bad;
  }

  Array.prototype.forEach.call(links, function (a) {
    a.addEventListener('click', function (e) {
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
      if (get('sessionStorage', DONE_KEY)) return;   // already filled in during this visit
      e.preventDefault();
      current = a.href;
      dlg.querySelector('#vx-file').textContent = current.split('/').pop();
      status.textContent = '';
      submit.disabled = false;
      dlg.showModal();
      document.getElementById('vx-name').focus();
    });
  });

  form.querySelector('button[value=cancel]').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    status.textContent = '';
    if (!check()) return;
    var f = fields(), url = current;
    submit.disabled = true;
    submit.textContent = 'Starting…';
    fetch('api/download.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: f.name.value, organization: f.organization.value, email: f.email.value,
        consent: f.consent.checked, website: f.website.value, file: url
      })
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, body: j }; });
    }).then(function (res) {
      submit.textContent = 'Download';
      submit.disabled = false;
      if (res.status === 200 && res.body.ok) {
        set('sessionStorage', DONE_KEY, '1');
        dlg.close();
        go(res.body.url || url);
      } else if (res.status === 422 && res.body.errors) {
        for (var k in res.body.errors) showErr(k, res.body.errors[k]);
      } else if (res.status === 429) {
        status.textContent = res.body.error || 'Too many requests.';
      } else {
        failOpen(url);
      }
    }).catch(function () {
      submit.textContent = 'Download';
      submit.disabled = false;
      failOpen(url);
    });
  });

  // If the server cannot take the form, never block the download.
  function failOpen(url) {
    status.innerHTML = 'We could not save your details just now. <a href="' + url.replace(/"/g, '&quot;') + '">Download anyway</a>';
  }
})();
