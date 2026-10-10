/* CHT ESP ORG — web push (OneSignal) */
(function(){
  var APP_ID = '30a5e5d9-ed4b-414d-b366-350c6bc08b2f';
  var BASE   = '/Website-/';                      // GitHub Pages sub-folder
  var WORKER = 'Website-/OneSignalSDKWorker.js';  // origin-relative path

  

  /* ---- OneSignal SDK ---- */
  window.OneSignalDeferred = window.OneSignalDeferred || [];
  var sdk = document.createElement('script');
  sdk.src = 'https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js';
  sdk.defer = true;
  document.head.appendChild(sdk);
  window.OneSignalDeferred.push(async function (OneSignal) {
    await OneSignal.init({
      appId: APP_ID,
      serviceWorkerPath: WORKER,
      serviceWorkerParam: { scope: BASE },
      notifyButton: { enable: false },
      allowLocalhostAsSecureOrigin: true
    });
  });

  /* ---- helpers for the rest of the site ---- */
  window.CHTPush = {
    // user login korle call koro: CHTPush.login(session.user.id)
    login:  function (id) { window.OneSignalDeferred.push(function (OS) { try { OS.login(String(id)); } catch (e) {} }); },
    logout: function () { window.OneSignalDeferred.push(function (OS) { try { OS.logout(); } catch (e) {} }); },
    ask:    function () { return askPermission(); }
  };

  function askPermission() {
    return new Promise(function (res) {
      window.OneSignalDeferred.push(async function (OS) {
        try { await OS.Notifications.requestPermission(); } catch (e) {}
        res(typeof Notification !== 'undefined' ? Notification.permission : 'denied');
      });
    });
  }

  /* ---- little bottom banner ---- */
  var KEY = 'cht_push_dismiss';
  function dismissedRecently() {
    try { var t = +localStorage.getItem(KEY) || 0; return Date.now() - t < 7 * 864e5; } catch (e) { return false; }
  }
  function isIOS() { return /iphone|ipad|ipod/i.test(navigator.userAgent); }
  function standalone() { return window.navigator.standalone === true || (window.matchMedia && matchMedia('(display-mode: standalone)').matches); }

  function css() {
    var s = document.createElement('style');
    s.textContent =
      '.cp-bar{position:fixed;left:12px;right:12px;bottom:calc(86px + env(safe-area-inset-bottom,0px));z-index:9999;display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:18px;background:#10161f;color:#fff;border:1px solid rgba(57,231,123,.45);box-shadow:0 14px 34px rgba(0,0,0,.35);font-family:"Hind Siliguri","Inter",sans-serif;animation:cpin .25s ease}' +
      '@keyframes cpin{from{transform:translateY(14px);opacity:0}to{transform:none;opacity:1}}' +
      '.cp-ic{font-size:22px;flex-shrink:0}' +
      '.cp-tx{flex:1;min-width:0;font-size:13px;line-height:1.45}' +
      '.cp-tx b{display:block;font-size:14px;margin-bottom:1px}' +
      '.cp-tx span{color:#aab6c8;font-size:12px}' +
      '.cp-yes{border:0;border-radius:12px;padding:10px 14px;background:#39e77b;color:#04210f;font:800 13px "Hind Siliguri","Inter",sans-serif;cursor:pointer;flex-shrink:0}' +
      '.cp-no{border:0;background:transparent;color:#8fa0bd;font-size:18px;padding:4px 2px;cursor:pointer;flex-shrink:0}';
    document.head.appendChild(s);
  }

  function show(html, onYes) {
    css();
    var d = document.createElement('div');
    d.className = 'cp-bar';
    d.innerHTML = html;
    document.body.appendChild(d);
    function close(remember) {
      if (remember) { try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {} }
      d.remove();
    }
    d.querySelector('.cp-no').onclick = function () { close(true); };
    var y = d.querySelector('.cp-yes');
    if (y) y.onclick = async function () { y.disabled = true; await onYes(); close(false); };
  }

  function init() {
    if (dismissedRecently()) return;
    // iPhone: web push shudhu "Add to Home Screen" korle kaj kore
    if (isIOS() && !standalone()) {
      show('<div class="cp-ic">🔔</div><div class="cp-tx"><b>নোটিফিকেশন পেতে</b><span>Safari-র Share বাটন চেপে "Add to Home Screen" করুন, তারপর অ্যাপ থেকে চালু করুন।</span></div><button class="cp-no" aria-label="বন্ধ">✕</button>');
      return;
    }
    if (typeof Notification === 'undefined' || !('serviceWorker' in navigator)) return;
    if (Notification.permission !== 'default') return;
    show('<div class="cp-ic">🔔</div><div class="cp-tx"><b>নোটিফিকেশন চালু করুন</b><span>আজকের লবি আর ম্যাচের সময় মিস করবেন না</span></div><button class="cp-yes">চালু করুন</button><button class="cp-no" aria-label="বন্ধ">✕</button>', askPermission);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 2500); });
  else setTimeout(init, 2500);
})();
