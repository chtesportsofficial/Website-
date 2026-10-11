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
  var readyRes, ready = new Promise(function (r) { readyRes = r; });

  // Supabase session theke user id (supabase-js localStorage-e rakhe)
  function sbUserId() {
    try {
      var raw = localStorage.getItem('sb-myfficbwcbgbxbdqjexv-auth-token');
      if (!raw) return null;
      var o = JSON.parse(raw);
      var u = (o && o.user) || (o && o.currentSession && o.currentSession.user);
      return (u && u.id) || null;
    } catch (e) { return null; }
  }

  window.OneSignalDeferred.push(async function (OneSignal) {
    try {
      await OneSignal.init({
        appId: APP_ID,
        serviceWorkerPath: WORKER,
        serviceWorkerParam: { scope: BASE },
        notifyButton: { enable: false },
        allowLocalhostAsSecureOrigin: true
      });
      // login thakle OneSignal-er External ID = Supabase user id
      var uid = sbUserId();
      if (uid) { if (String(OneSignal.User.externalId || '') !== uid) await OneSignal.login(uid); }
      else if (OneSignal.User.externalId) { await OneSignal.logout(); }
    } catch (e) { console.warn('[push] init', e); }
    readyRes();
  });

  function withOS(fn) {
    return ready.then(function () {
      return new Promise(function (res) {
        window.OneSignalDeferred.push(async function (OS) {
          try { res(await fn(OS)); } catch (e) { console.warn('[push]', e); res(null); }
        });
      });
    });
  }
  function isIOS() { return /iphone|ipad|ipod/i.test(navigator.userAgent); }
  function standalone() { return window.navigator.standalone === true || (window.matchMedia && matchMedia('(display-mode: standalone)').matches); }

  /* ---- API for the rest of the site ---- */
  window.CHTPush = {
    // browser/device ei feature support kore kina
    supported: function () {
      if (typeof Notification === 'undefined' || !('serviceWorker' in navigator)) return false;
      if (isIOS() && !standalone()) return false;   // iPhone: Add to Home Screen lagbe
      return true;
    },
    needsHomeScreen: function () { return isIOS() && !standalone(); },
    // 'granted' | 'denied' | 'default'
    permission: function () { return typeof Notification === 'undefined' ? 'denied' : Notification.permission; },
    // push ekhon chalu ase kina (true/false)
    isOn: function () {
      return withOS(function (OS) { return !!OS.User.PushSubscription.optedIn; }).then(function (v) { return !!v; });
    },
    // chalu koro (permission chaibe), chalu hole true
    enable: function () {
      return withOS(async function (OS) {
        if (Notification.permission === 'denied') return false;
        if (Notification.permission === 'default') await OS.Notifications.requestPermission();
        if (Notification.permission !== 'granted') return false;
        await OS.User.PushSubscription.optIn();
        return !!OS.User.PushSubscription.optedIn;
      }).then(function (v) { return !!v; });
    },
    // bondho koro (permission thakbe, shudhu notification jabe na)
    disable: function () {
      return withOS(async function (OS) { await OS.User.PushSubscription.optOut(); return true; });
    },
    // preference gulo OneSignal tag hishebe rakhe: CHTPush.setTags({new_tournament:'1', promo:'0'})
    setTags: function (obj) { return withOS(function (OS) { return OS.User.addTags(obj); }); },
    // login korle: CHTPush.login(session.user.id)   logout: CHTPush.logout()
    login:  function (id) { return withOS(function (OS) { return OS.login(String(id)); }); },
    logout: function () { return withOS(function (OS) { return OS.logout(); }); },
    // status bodlale call hobe: CHTPush.onChange(function(on){...})
    onChange: function (cb) {
      window.OneSignalDeferred.push(function (OS) {
        try { OS.User.PushSubscription.addEventListener('change', function (e) { cb(!!(e && e.current && e.current.optedIn)); }); } catch (e) {}
      });
    }
  };
})();
