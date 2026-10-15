document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.querySelector('.sidebar-toggle');
  var sidebar = document.querySelector('.app-sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('open');
    });
    document.addEventListener('click', function (e) {
      if (sidebar.classList.contains('open') && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
        sidebar.classList.remove('open');
      }
    });
  }
});

/* ---------- PWA: install as an app on phones/tablets ---------- */
(function () {
  function L(key, fallback) { return (window.I18N && window.I18N[key]) || fallback; }
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      var swUrl = document.querySelector('link[rel="manifest"]').href.replace('manifest.php', 'sw.php');
      navigator.serviceWorker.register(swUrl).catch(function () { /* not critical - app still works fine */ });
    });
  }

  var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (isStandalone) return; // already installed/running as an app - no need to prompt

  function showInstallBar(html, onClick) {
    if (document.querySelector('.install-bar')) return;
    var bar = document.createElement('div');
    bar.className = 'install-bar';
    bar.innerHTML = html;
    document.body.appendChild(bar);
    bar.querySelector('.install-bar-close').addEventListener('click', function () {
      bar.remove();
    });
    if (onClick) bar.querySelector('.install-bar-action').addEventListener('click', onClick);
  }

  // Android / Chrome / Edge: browser offers a real install prompt we can trigger.
  var deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    if (localStorage.getItem('installBarDismissed') === '1') return;
    showInstallBar(
      '<i class="bi bi-phone me-2"></i><span>' + L('installMsg', 'Install this app on your device for the full experience.') + '</span>' +
      '<button type="button" class="btn btn-sm btn-light install-bar-action ms-auto">' + L('install', 'Install') + '</button>' +
      '<button type="button" class="install-bar-close" aria-label="' + L('dismiss', 'Dismiss') + '"><i class="bi bi-x-lg"></i></button>',
      function () {
        document.querySelector('.install-bar').remove();
        if (deferredPrompt) { deferredPrompt.prompt(); deferredPrompt = null; }
      }
    );
  });

  // iOS Safari has no install prompt API - it's always "Share -> Add to Home Screen".
  var isIos = /iphone|ipad|ipod/i.test(window.navigator.userAgent);
  var isSafari = /safari/i.test(window.navigator.userAgent) && !/crios|fxios/i.test(window.navigator.userAgent);
  if (isIos && isSafari && localStorage.getItem('installBarDismissed') !== '1') {
    showInstallBar(
      '<i class="bi bi-phone me-2"></i><span>' + L('iosBefore', 'Add this to your Home Screen: tap') + ' <i class="bi bi-box-arrow-up mx-1"></i> ' + L('iosAfter', 'then "Add to Home Screen".') + '</span>' +
      '<button type="button" class="install-bar-close" aria-label="' + L('dismiss', 'Dismiss') + '"><i class="bi bi-x-lg"></i></button>'
    );
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest('.install-bar-close')) {
      localStorage.setItem('installBarDismissed', '1');
    }
  });
})();

/* ---------- Responsive tables: label every cell with its column title ----------
   On phones / iPad portrait the CSS turns each table row into a card and shows
   this label above each value. Runs for every table on every page, so no page
   needs editing. */
(function () {
  function labelTable(table) {
    var head = table.querySelector('thead tr');
    if (!head) return;
    var labels = [];
    Array.prototype.forEach.call(head.children, function (th) {
      var text = (th.textContent || '').replace(/\s+/g, ' ').trim();
      var span = parseInt(th.getAttribute('colspan') || '1', 10) || 1;
      for (var i = 0; i < span; i++) labels.push(text);
    });
    Array.prototype.forEach.call(table.querySelectorAll('tbody tr, tfoot tr'), function (tr) {
      var col = 0;
      Array.prototype.forEach.call(tr.children, function (cell) {
        var span = parseInt(cell.getAttribute('colspan') || '1', 10) || 1;
        if (cell.tagName === 'TD' && !cell.hasAttribute('data-label') && span === 1 && labels[col]) {
          cell.setAttribute('data-label', labels[col]);
        }
        col += span;
      });
    });
  }
  function run(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('table.table'), labelTable);
  }
  document.addEventListener('DOMContentLoaded', function () {
    run();
    // tables added later (e.g. inside modals/tabs)
    if ('MutationObserver' in window) {
      var pending = false;
      new MutationObserver(function () {
        if (pending) return;
        pending = true;
        setTimeout(function () { pending = false; run(); }, 100);
      }).observe(document.body, { childList: true, subtree: true });
    }
  });
})();

/* ---------- Slide-out menu: dim backdrop + close on tap outside ---------- */
document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.querySelector('.sidebar-toggle');
  var sidebar = document.querySelector('.app-sidebar');
  if (!toggle || !sidebar) return;
  var backdrop = document.createElement('div');
  backdrop.className = 'sidebar-backdrop';
  backdrop.hidden = true;
  document.body.appendChild(backdrop);
  function sync() { backdrop.hidden = !sidebar.classList.contains('open'); }
  new MutationObserver(sync).observe(sidebar, { attributes: true, attributeFilter: ['class'] });
  backdrop.addEventListener('click', function () { sidebar.classList.remove('open'); });
  window.addEventListener('resize', function () { if (window.innerWidth >= 1200) sidebar.classList.remove('open'); });
});
