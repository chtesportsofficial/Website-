/* =========================================================
   CHT ESP ORG — global.js
   Shared across every page. Handles:
   - Dark / Light theme (persisted in localStorage)
   - Bangla / English language (persisted in localStorage)
   - Auto-injected Bottom Navigation (always visible, correct
     active glow, no manual copy/paste needed on new pages)
   - Auto-injected Footer
   Add these two lines to any page's <head>/<body> and everything
   below just works:
     <link rel="stylesheet" href="theme.css">
     <script src="global.js" defer></script>
   ========================================================= */
(function () {
  "use strict";

  var THEME_KEY = "chteo_theme"; // 'dark' | 'light' | 'system'
  var LANG_KEY = "chteo_lang";   // 'en' | 'bn'
  var AUTH_KEY = "chteo_logged_in";

  /* =========================================================
     0. SUPABASE PROFILE SYNC
     Keeps localStorage('user_profile') / localStorage('user_uid')
     in sync with the REAL signed-in user's row in Supabase, so
     every page (profile.html, index.html, side_drawer.html, ...)
     shows the actual email / UID instead of the hardcoded
     placeholder defaults ("userid99@gmail.com", "#99").

     Before this, no page ever queried Supabase for profile data —
     profile.html etc. only ever read localStorage('user_profile'),
     which chteo_auth.html never wrote to after signup/login. That
     is the entire reason the placeholder values were showing.

     IMPORTANT: update PROFILE_TABLE / column names below to match
     your actual Supabase table if they differ.
     ========================================================= */
  var SUPABASE_URL = "https://myfficbwcbgbxbdqjexv.supabase.co";
  var SUPABASE_ANON_KEY = "sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5";
  var PROFILE_TABLE = "profiles"; // <-- change if your table name differs

  var _sbClient = null;
  function getSupabaseClient(cb) {
    if (_sbClient) { cb(_sbClient); return; }
    if (window.supabase && window.supabase.createClient) {
      _sbClient = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);
      cb(_sbClient);
      return;
    }
    var existing = document.getElementById("sb-js-cdn");
    if (!existing) {
      var s = document.createElement("script");
      s.id = "sb-js-cdn";
      s.src = "https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2";
      s.onload = function () {
        _sbClient = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);
        cb(_sbClient);
      };
      document.head.appendChild(s);
    } else {
      existing.addEventListener("load", function () {
        _sbClient = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);
        cb(_sbClient);
      });
    }
  }

  function saveProfileLocally(row, authEmail, authUid) {
    var current = {};
    try { current = JSON.parse(localStorage.getItem("user_profile") || "{}"); } catch (e) {}
    var merged = Object.assign({}, current, {
      email: (row && row.email) || authEmail || current.email,
      whatsapp: (row && row.whatsapp) || current.whatsapp,
      // Team Name lives in the 'full_name' column on Supabase (no
      // separate 'team_name' column on this table).
      teamName: (row && row.full_name) || current.teamName,
      slogan: (row && row.slogan) || current.slogan,
      country: (row && row.country) || current.country,
      location: (row && row.location) || current.location,
      uid: (row && row.user_number != null) ? row.user_number : current.uid,
      avatarUrl: (row && row.avatar_url) || current.avatarUrl,
      // Whether this signed-in user is an admin (drives the "Admin Panel"
      // row in profile.html). Use an explicit undefined-check rather than
      // `||` so that a real `false` from Supabase isn't lost.
      isAdmin: (row && typeof row.is_admin !== "undefined") ? !!row.is_admin : current.isAdmin,
      supabase_uid: authUid || current.supabase_uid
    });
    localStorage.setItem("user_profile", JSON.stringify(merged));
    if (row && row.user_number != null) localStorage.setItem("user_uid", String(row.user_number));
    if (authUid) localStorage.setItem("supabase_uid", authUid);
    // Keep the 'profile_picture' cache (read by profile.html, index.html,
    // side_drawer.html, and the bottom-nav avatar below) in sync with the
    // real Supabase Storage URL — this key already works as a plain
    // <img src>, whether it holds a base64 string or a URL, so no other
    // page needs to change.
    if (row && row.avatar_url) localStorage.setItem("profile_picture", row.avatar_url);
    else if (row && "avatar_url" in row && row.avatar_url == null) localStorage.removeItem("profile_picture");
    try {
      document.dispatchEvent(new CustomEvent("chteo:profile-synced", { detail: merged }));
    } catch (e) {}
    return merged;
  }

  // Pulls the signed-in user's real row from Supabase and refreshes
  // localStorage + fires 'chteo:profile-synced' so any page listening
  // (see profile.html) can re-render with the fresh data.
  function syncProfile() {
    return new Promise(function (resolve) {
      getSupabaseClient(function (client) {
        client.auth.getUser().then(function (res) {
          var user = res && res.data && res.data.user;
          if (!user) { resolve(null); return; }
          client.from(PROFILE_TABLE).select("*").eq("id", user.id).single()
            .then(function (result) {
              if (result && result.error) {
                console.warn("CHTEO.syncProfile: could not read " + PROFILE_TABLE + " row —", result.error.message);
              }
              var merged = saveProfileLocally(result && result.data, user.email, user.id);
              resolve(merged);
            })
            .catch(function () { resolve(saveProfileLocally(null, user.email, user.id)); });
        }).catch(function () { resolve(null); });
      });
    });
  }

  /* =========================================================
     0b. BAN WATCHER
     Logs a signed-in user out IMMEDIATELY once an admin flips
     profiles.is_banned to true — not just the next time they try
     to log in. Two layers, so this works even if the project's
     Realtime setup isn't ready yet:
       1. Supabase Realtime — near-instant, fires as soon as the
          admin's update lands (requires the profiles table to be
          added to the supabase_realtime publication — see
          enable_realtime.sql).
       2. A 20s fallback poll — a safety net in case Realtime
          isn't enabled, so a blocked user is still caught within
          seconds either way.
     ========================================================= */
  var _banWatcherStarted = false;

  function handleBanDetected() {
    try {
      localStorage.removeItem("user_profile");
      localStorage.removeItem("profile_picture");
      localStorage.removeItem("user_uid");
      localStorage.removeItem(AUTH_KEY);
    } catch (e) {}
    getSupabaseClient(function (client) {
      client.auth.signOut().finally(function () {
        window.location.replace("chteo_auth.html?blocked=1");
      });
    });
  }

  function startBanWatcher() {
    if (_banWatcherStarted) return;
    _banWatcherStarted = true;

    getSupabaseClient(function (client) {
      client.auth.getUser().then(function (res) {
        var user = res && res.data && res.data.user;
        if (!user) return;

        function checkNow() {
          client.from(PROFILE_TABLE).select("is_banned").eq("id", user.id).single()
            .then(function (result) {
              if (result && result.data && result.data.is_banned) handleBanDetected();
            })
            .catch(function () {});
        }

        // Catch a ban that already happened before this page loaded.
        checkNow();

        // Layer 1: instant catch via Realtime (silently does nothing if
        // the table isn't in the realtime publication yet).
        try {
          client
            .channel("ban-watch-" + user.id)
            .on("postgres_changes", {
              event: "UPDATE",
              schema: "public",
              table: PROFILE_TABLE,
              filter: "id=eq." + user.id
            }, function (payload) {
              if (payload && payload.new && payload.new.is_banned) handleBanDetected();
            })
            .subscribe();
        } catch (e) {}

        // Layer 2: fallback poll every 20s.
        setInterval(checkNow, 20000);
      }).catch(function () {});
    });
  }

  /* =========================================================
     1. THEME
     ========================================================= */
  function getSystemTheme() {
    return window.matchMedia && window.matchMedia("(prefers-color-scheme: light)").matches
      ? "light" : "dark";
  }
  function resolveTheme(pref) {
    if (!pref || pref === "system") return getSystemTheme();
    return pref;
  }
  function getThemePref() {
    return localStorage.getItem(THEME_KEY) || "dark";
  }
  function applyTheme(pref) {
    document.documentElement.setAttribute("data-theme", resolveTheme(pref));
  }
  function setThemePref(pref) {
    localStorage.setItem(THEME_KEY, pref);
    applyTheme(pref);
    document.dispatchEvent(new CustomEvent("chteo:themechange", { detail: { pref: pref } }));
  }
  // Apply immediately (script runs before body paints most of the page)
  applyTheme(getThemePref());

  /* =========================================================
     2. LANGUAGE / i18n
     ========================================================= */
  var translations = {
    en: {
      "nav.home": "Home",
      "nav.refer": "Refer & Earn",
      "nav.support": "Support",
      "nav.wallet": "Wallet",
      "nav.profile": "Profile",

      "settings.title": "Settings",
      "settings.notification": "Notification",
      "settings.theme": "Theme",
      "settings.language": "Language",
      "settings.changePassword": "Change Password",

      "password.title": "Change Password",
      "password.current": "Current Password",
      "password.currentPh": "Enter current password",
      "password.new": "New Password",
      "password.newPh": "Enter new password",
      "password.confirm": "Confirm New Password",
      "password.confirmPh": "Confirm new password",
      "password.update": "Update Password",
      "password.fillAll": "Please fill in all fields.",
      "password.mismatch": "New password and confirmation do not match.",

      "lang.title": "Language",
      "lang.subtitle": "Select Language",
      "lang.confirm": "Confirm",

      "theme.title": "Theme",
      "theme.chooseTheme": "Choose Theme",
      "theme.system": "System Default",
      "theme.systemSub": "Follow system setting",
      "theme.light": "Light Mode",
      "theme.lightSub": "Light theme",
      "theme.dark": "Dark Mode",
      "theme.darkSub": "Dark theme",

      "notif.title": "Notification",
      "notif.push": "Push Notifications",
      "notif.pushSub": "Receive push notifications",
      "notif.prefTitle": "Notification Preferences",
      "notif.tournament": "New Tournament Updates",
      "notif.tournamentSub": "Get noticed about new tournaments",
      "notif.match": "Match Reminders",
      "notif.matchSub": "Get reminded before matches",
      "notif.win": "Win & Rewards",
      "notif.winSub": "Get notified about wins & rewards",
      "notif.promo": "Promotions & Offers",
      "notif.promoSub": "Receive special offers & promo",
      "notif.quietTitle": "Quiet Hours",
      "notif.quietEnable": "Enable Quiet Hours",
      "notif.quietEnableSub": "Mute notifications during set hours",
      "notif.startTime": "Start Time",
      "notif.endTime": "End Time",
      "notif.footnoteOn": "You will not receive notifications during quiet hours.",
      "notif.footnoteOff": "Quiet hours is off. You will receive notifications at all times.",

      "feedback.title": "Feedback",
      "feedback.placeholder": "Please describe your problem or suggestion in detail. It will help us improve your experience.",
      "feedback.attach": "Attach Screenshot (Optional)",
      "feedback.uploadText": "Upload Screenshot",
      "feedback.uploadSub": "JPG, PNG up to 5MB",
      "feedback.info1": "Your feedback is important to us.",
      "feedback.info2": "We will review your feedback and get back to you soon.",
      "feedback.submit": "Submit Feedback",
      "feedback.toast": "Thanks for your feedback!",

      "about.title": "About Us",
      "about.website": "Official Website",
      "about.websiteSub": "Visit our official site",
      "about.email": "Email Support",
      "about.emailSub": "Get in touch via email",
      "about.help": "Help & Support",
      "about.helpSub": "Need help? We're here for you.",
      "about.whatsapp": "Join our Whatsapp",
      "about.whatsappSub": "Group & Channel",
      "about.connected": "Connected with us",

      "wallet.title": "Wallet",
      "wallet.totalPrize": "Total Prize Won",
      "wallet.totalPrizeSub": "Total amount your team earned over its lifetime",
      "wallet.currentBalance": "CURRENT BALANCE",
      "wallet.withdrawable": "Withdrawable",
      "wallet.nonWithdrawable": "Non-Withdrawable",
      "wallet.addMoney": "Add Money",
      "wallet.withdraw": "Withdraw",
      "wallet.minWithdraw": "Minimum Withdraw: 50 BDT",
      "wallet.selectMethod": "Select Payment Method",
      "wallet.enterAmount": "Enter the Amount",
      "wallet.enterNumber": "Enter your number",
      "wallet.deposit": "Deposit",
      "wallet.requestWithdraw": "Request Withdraw",
      "wallet.recentTx": "RECENT TRANSACTIONS",
      "wallet.noTx": "No transactions yet.",

      "profile.title": "Profile",
      "profile.userInfo": "USER INFORMATION",
      "profile.uid": "UID",
      "profile.email": "Email",
      "profile.whatsapp": "WhatsApp",
      "profile.slotBought": "Slot Bought",
      "profile.teamRank": "Team Rank",
      "profile.editProfile": "EDIT PROFILE",
      "profile.editProfileSub": "Update your personal information",
      "profile.settings": "SETTINGS",
      "profile.settingsSub": "Adjust your preferences",
      "profile.feedback": "FEEDBACK",
      "profile.feedbackSub": "Send us your feedback",
      "profile.aboutUs": "ABOUT US",
      "profile.aboutUsSub": "Learn more about us",
      "profile.logout": "LOG OUT",
      "profile.logoutConfirm": "Are you sure you want to log out?",

      "editprofile.title": "Edit your Profile",
      "editprofile.whatsapp": "WHATSAPP NUMBER :",
      "editprofile.whatsappPh": "Enter your WhatsApp number",
      "editprofile.email": "EMAIL :",
      "editprofile.emailPh": "Enter your email",
      "editprofile.emailNote": "This is your login email and can't be changed here. Contact support to change it.",
      "editprofile.teamName": "TEAM NAME :",
      "editprofile.teamNamePh": "Enter your team name",
      "editprofile.slogan": "TEAM SLOGAN :",
      "editprofile.sloganMax": "max 50 characters",
      "editprofile.sloganPh": "Enter your team slogan",
      "editprofile.sloganError": "Slogan is over 50 characters.",
      "editprofile.country": "COUNTY :",
      "editprofile.countrySelect": "Select your country",
      "editprofile.location": "LOCATION :",
      "editprofile.locationPh": "Enter your location",
      "editprofile.save": "SAVE CHANGES",

      "refer.title": "Refer & Earn",
      "refer.totalCommissions": "Total Commissions",
      "refer.totalCommissionsSub": "Lifetime earnings from your referred friends",
      "refer.registeredMembers": "Registered Members",
      "refer.registeredMembersSub": "Members who registered and deposited",
      "refer.totalDeposit": "Total Deposit Amount",
      "refer.totalDepositSub": "Total deposit amount by your referred members",
      "refer.invitationLink": "Invitation Link",
      "refer.invitationLinkSub": "Share your unique link and invite friends",
      "refer.invitationCode": "Invitation Code",
      "refer.invitationCodeSub": "Share your code and invite friends",
      "refer.share": "Share Invitation",
      "refer.shareSub": "Share via social platforms",
      "refer.copy": "Copy",
      "refer.commissionDetails": "Commission Details",
      "refer.commissionDetailsSub": "See the earnings you get from your referred members",
      "refer.noCommissions": "No commissions yet.",
      "refer.viewAll": "View All",
      "refer.rulesTitle": "Invitation Rules",
      "refer.rulesSub": "Please follow the rules to earn commission",
      "refer.rule1": "Invite your friends using your link or code.",
      "refer.rule2": "Your friend must register with your link or code and make a deposit to be counted.",
      "refer.rule3": "You will earn 2% from your friend's deposit.",
      "refer.colJoinDate": "Joining Date",
      "refer.colTeamName": "Team Name",
      "refer.colPhone": "Phone",
      "refer.colDeposit": "Deposit Amount",
      "refer.colCommission": "Commission",
      "refer.colStatus": "Status",

      "index.dailyTournaments": "Daily (Scrims) Tournaments",
      "index.paidTournaments": "Today's Paid (Qualify) Tournaments",
      "index.topTeams": "{month}'s Top Teams",
      "index.viewAll": "View All →",
      "index.seeOther": "See other teams ↓",
      "index.vipOnly": "ONLY FOR",
      "index.vipMembers": "VIP MEMBERS",
      "index.vipButton": "BE A VIP MEMBER",
      "index.ytTitle": "How to play tournament from our website?",
      "index.ytSub": "Watch the video to know everything in detail.",
      "index.watchVideo": "Watch Video",

      "support.needHelp": "Need Help?",
      "support.subtext": "If you have any questions or need assistance, our support team is here to help.",
      "support.whatsapp": "WhatsApp Support",
      "support.whatsappDesc": "Chat with us on WhatsApp",
      "support.email": "Email Support"
    },

    bn: {
      "nav.home": "হোম",
      "nav.refer": "রেফার ও আয়",
      "nav.support": "সাপোর্ট",
      "nav.wallet": "ওয়ালেট",
      "nav.profile": "প্রোফাইল",

      "settings.title": "সেটিংস",
      "settings.notification": "নোটিফিকেশন",
      "settings.theme": "থিম",
      "settings.language": "ভাষা",
      "settings.changePassword": "পাসওয়ার্ড পরিবর্তন",

      "password.title": "পাসওয়ার্ড পরিবর্তন",
      "password.current": "বর্তমান পাসওয়ার্ড",
      "password.currentPh": "বর্তমান পাসওয়ার্ড লিখুন",
      "password.new": "নতুন পাসওয়ার্ড",
      "password.newPh": "নতুন পাসওয়ার্ড লিখুন",
      "password.confirm": "নতুন পাসওয়ার্ড নিশ্চিত করুন",
      "password.confirmPh": "নতুন পাসওয়ার্ড আবার লিখুন",
      "password.update": "পাসওয়ার্ড আপডেট করুন",
      "password.fillAll": "অনুগ্রহ করে সব ঘর পূরণ করুন।",
      "password.mismatch": "নতুন পাসওয়ার্ড ও নিশ্চিতকরণ মিলছে না।",

      "lang.title": "ভাষা",
      "lang.subtitle": "ভাষা নির্বাচন করুন",
      "lang.confirm": "নিশ্চিত করুন",

      "theme.title": "থিম",
      "theme.chooseTheme": "থিম নির্বাচন করুন",
      "theme.system": "সিস্টেম ডিফল্ট",
      "theme.systemSub": "সিস্টেম সেটিং অনুসরণ করুন",
      "theme.light": "লাইট মোড",
      "theme.lightSub": "লাইট থিম",
      "theme.dark": "ডার্ক মোড",
      "theme.darkSub": "ডার্ক থিম",

      "notif.title": "নোটিফিকেশন",
      "notif.push": "পুশ নোটিফিকেশন",
      "notif.pushSub": "পুশ নোটিফিকেশন পান",
      "notif.prefTitle": "নোটিফিকেশন প্রেফারেন্স",
      "notif.tournament": "নতুন টুর্নামেন্ট আপডেট",
      "notif.tournamentSub": "নতুন টুর্নামেন্ট সম্পর্কে জানুন",
      "notif.match": "ম্যাচ রিমাইন্ডার",
      "notif.matchSub": "ম্যাচের আগে রিমাইন্ডার পান",
      "notif.win": "জয় ও পুরস্কার",
      "notif.winSub": "জয় ও পুরস্কার সম্পর্কে জানুন",
      "notif.promo": "প্রমোশন ও অফার",
      "notif.promoSub": "বিশেষ অফার ও প্রমো পান",
      "notif.quietTitle": "নিরিবিলি সময়",
      "notif.quietEnable": "নিরিবিলি সময় চালু করুন",
      "notif.quietEnableSub": "নির্দিষ্ট সময়ে নোটিফিকেশন বন্ধ রাখুন",
      "notif.startTime": "শুরুর সময়",
      "notif.endTime": "শেষ সময়",
      "notif.footnoteOn": "নিরিবিলি সময়ে আপনি নোটিফিকেশন পাবেন না।",
      "notif.footnoteOff": "নিরিবিলি সময় বন্ধ আছে। আপনি সবসময় নোটিফিকেশন পাবেন।",

      "feedback.title": "ফিডব্যাক",
      "feedback.placeholder": "আপনার সমস্যা বা পরামর্শ বিস্তারিত লিখুন। এটি আমাদের সেবার মান উন্নত করতে সাহায্য করবে।",
      "feedback.attach": "স্ক্রিনশট সংযুক্ত করুন (ঐচ্ছিক)",
      "feedback.uploadText": "স্ক্রিনশট আপলোড করুন",
      "feedback.uploadSub": "JPG, PNG সর্বোচ্চ ৫ এমবি",
      "feedback.info1": "আপনার ফিডব্যাক আমাদের কাছে গুরুত্বপূর্ণ।",
      "feedback.info2": "আমরা আপনার ফিডব্যাক পর্যালোচনা করে শীঘ্রই যোগাযোগ করব।",
      "feedback.submit": "ফিডব্যাক পাঠান",
      "feedback.toast": "আপনার ফিডব্যাকের জন্য ধন্যবাদ!",

      "about.title": "আমাদের সম্পর্কে",
      "about.website": "অফিসিয়াল ওয়েবসাইট",
      "about.websiteSub": "আমাদের অফিসিয়াল সাইট দেখুন",
      "about.email": "ইমেইল সাপোর্ট",
      "about.emailSub": "ইমেইলের মাধ্যমে যোগাযোগ করুন",
      "about.help": "সাহায্য ও সাপোর্ট",
      "about.helpSub": "সাহায্য প্রয়োজন? আমরা আছি আপনার পাশে।",
      "about.whatsapp": "আমাদের হোয়াটসঅ্যাপে যোগ দিন",
      "about.whatsappSub": "গ্রুপ ও চ্যানেল",
      "about.connected": "আমাদের সাথে যুক্ত থাকুন",

      "wallet.title": "ওয়ালেট",
      "wallet.totalPrize": "মোট পুরস্কার জিতেছেন",
      "wallet.totalPrizeSub": "আপনার দলের এখন পর্যন্ত মোট অর্জিত অর্থ",
      "wallet.currentBalance": "বর্তমান ব্যালেন্স",
      "wallet.withdrawable": "উত্তোলনযোগ্য",
      "wallet.nonWithdrawable": "অ-উত্তোলনযোগ্য",
      "wallet.addMoney": "টাকা যোগ করুন",
      "wallet.withdraw": "উত্তোলন",
      "wallet.minWithdraw": "সর্বনিম্ন উত্তোলন: ৫০ টাকা",
      "wallet.selectMethod": "পেমেন্ট মাধ্যম নির্বাচন করুন",
      "wallet.enterAmount": "টাকার পরিমাণ লিখুন",
      "wallet.enterNumber": "আপনার নম্বর লিখুন",
      "wallet.deposit": "জমা দিন",
      "wallet.requestWithdraw": "উত্তোলনের অনুরোধ",
      "wallet.recentTx": "সাম্প্রতিক লেনদেন",
      "wallet.noTx": "এখনও কোনো লেনদেন নেই।",

      "profile.title": "প্রোফাইল",
      "profile.userInfo": "ব্যবহারকারীর তথ্য",
      "profile.uid": "ইউআইডি",
      "profile.email": "ইমেইল",
      "profile.whatsapp": "হোয়াটসঅ্যাপ",
      "profile.slotBought": "কেনা স্লট",
      "profile.teamRank": "দলের র‍্যাংক",
      "profile.editProfile": "প্রোফাইল সম্পাদনা",
      "profile.editProfileSub": "আপনার ব্যক্তিগত তথ্য আপডেট করুন",
      "profile.settings": "সেটিংস",
      "profile.settingsSub": "আপনার পছন্দ ঠিক করুন",
      "profile.feedback": "ফিডব্যাক",
      "profile.feedbackSub": "আমাদের ফিডব্যাক পাঠান",
      "profile.aboutUs": "আমাদের সম্পর্কে",
      "profile.aboutUsSub": "আমাদের সম্পর্কে আরও জানুন",
      "profile.logout": "লগ আউট",
      "profile.logoutConfirm": "আপনি কি লগ আউট করতে চান?",

      "editprofile.title": "আপনার প্রোফাইল সম্পাদনা করুন",
      "editprofile.whatsapp": "হোয়াটসঅ্যাপ নম্বর :",
      "editprofile.whatsappPh": "আপনার হোয়াটসঅ্যাপ নম্বর লিখুন",
      "editprofile.email": "ইমেইল :",
      "editprofile.emailPh": "আপনার ইমেইল লিখুন",
      "editprofile.emailNote": "এটি আপনার লগইন ইমেইল, এখানে পরিবর্তন করা যাবে না। পরিবর্তন করতে সাপোর্টে যোগাযোগ করুন।",
      "editprofile.teamName": "টিমের নাম :",
      "editprofile.teamNamePh": "আপনার টিমের নাম লিখুন",
      "editprofile.slogan": "টিমের স্লোগান :",
      "editprofile.sloganMax": "সর্বোচ্চ ৫০ অক্ষর",
      "editprofile.sloganPh": "আপনার টিমের স্লোগান লিখুন",
      "editprofile.sloganError": "স্লোগান ৫০ অক্ষরের বেশি হয়ে গেছে।",
      "editprofile.country": "দেশ :",
      "editprofile.countrySelect": "আপনার দেশ নির্বাচন করুন",
      "editprofile.location": "অবস্থান :",
      "editprofile.locationPh": "আপনার অবস্থান লিখুন",
      "editprofile.save": "পরিবর্তন সংরক্ষণ করুন",

      "refer.title": "রেফার ও আয়",
      "refer.totalCommissions": "মোট কমিশন",
      "refer.totalCommissionsSub": "রেফার করা বন্ধুদের থেকে সারাজীবনের আয়",
      "refer.registeredMembers": "নিবন্ধিত সদস্য",
      "refer.registeredMembersSub": "যারা নিবন্ধন করে জমা দিয়েছেন",
      "refer.totalDeposit": "মোট জমার পরিমাণ",
      "refer.totalDepositSub": "আপনার রেফার করা সদস্যদের মোট জমা",
      "refer.invitationLink": "আমন্ত্রণ লিংক",
      "refer.invitationLinkSub": "আপনার নিজস্ব লিংক শেয়ার করে বন্ধুদের আমন্ত্রণ জানান",
      "refer.invitationCode": "আমন্ত্রণ কোড",
      "refer.invitationCodeSub": "আপনার কোড শেয়ার করে বন্ধুদের আমন্ত্রণ জানান",
      "refer.share": "আমন্ত্রণ শেয়ার করুন",
      "refer.shareSub": "সোশ্যাল মিডিয়ায় শেয়ার করুন",
      "refer.copy": "কপি",
      "refer.commissionDetails": "কমিশনের বিবরণ",
      "refer.commissionDetailsSub": "আপনার রেফার করা সদস্যদের থেকে পাওয়া আয় দেখুন",
      "refer.noCommissions": "এখনও কোনো কমিশন নেই।",
      "refer.viewAll": "সব দেখুন",
      "refer.rulesTitle": "আমন্ত্রণের নিয়মাবলী",
      "refer.rulesSub": "কমিশন পেতে নিয়মগুলো অনুসরণ করুন",
      "refer.rule1": "আপনার লিংক বা কোড ব্যবহার করে বন্ধুদের আমন্ত্রণ জানান।",
      "refer.rule2": "আপনার বন্ধুকে অবশ্যই আপনার লিংক বা কোড দিয়ে নিবন্ধন করে জমা দিতে হবে।",
      "refer.rule3": "আপনি আপনার বন্ধুর জমার ২% আয় করবেন।",
      "refer.colJoinDate": "যোগদানের তারিখ",
      "refer.colTeamName": "টিমের নাম",
      "refer.colPhone": "ফোন",
      "refer.colDeposit": "জমার পরিমাণ",
      "refer.colCommission": "কমিশন",
      "refer.colStatus": "অবস্থা",

      "index.dailyTournaments": "দৈনিক (স্ক্রিম) টুর্নামেন্ট",
      "index.paidTournaments": "আজকের পেইড (কোয়ালিফাই) টুর্নামেন্ট",
      "index.topTeams": "{month}ের শীর্ষ দল",
      "index.viewAll": "সব দেখুন →",
      "index.seeOther": "অন্যান্য দল দেখুন ↓",
      "index.vipOnly": "শুধুমাত্র",
      "index.vipMembers": "ভিআইপি সদস্যদের জন্য",
      "index.vipButton": "ভিআইপি সদস্য হোন",
      "index.ytTitle": "কীভাবে আমাদের ওয়েবসাইট থেকে টুর্নামেন্ট খেলবেন?",
      "index.ytSub": "বিস্তারিত জানতে ভিডিওটি দেখুন।",
      "index.watchVideo": "ভিডিও দেখুন",

      "support.needHelp": "সাহায্য প্রয়োজন?",
      "support.subtext": "আপনার কোনো প্রশ্ন থাকলে বা সাহায্যের প্রয়োজন হলে, আমাদের সাপোর্ট টিম আপনার পাশে আছে।",
      "support.whatsapp": "হোয়াটসঅ্যাপ সাপোর্ট",
      "support.whatsappDesc": "হোয়াটসঅ্যাপে আমাদের সাথে চ্যাট করুন",
      "support.email": "ইমেইল সাপোর্ট"
    }
  };

  function getLangPref() {
    return localStorage.getItem(LANG_KEY) || "en";
  }

  var MONTH_NAMES = {
    en: ["January","February","March","April","May","June","July","August","September","October","November","December"],
    bn: ["জানুয়ারি","ফেব্রুয়ারি","মার্চ","এপ্রিল","মে","জুন","জুলাই","আগস্ট","সেপ্টেম্বর","অক্টোবর","নভেম্বর","ডিসেম্বর"]
  };
  function currentMonthName(lang) {
    var names = MONTH_NAMES[lang] || MONTH_NAMES.en;
    return names[new Date().getMonth()];
  }

  function applyLanguage(lang) {
    var dict = translations[lang] || translations.en;
    document.documentElement.setAttribute("lang", lang === "bn" ? "bn" : "en");

    document.querySelectorAll("[data-i18n]").forEach(function (el) {
      var key = el.getAttribute("data-i18n");
      if (dict[key] != null) {
        var text = dict[key];
        if (text.indexOf("{month}") !== -1) {
          text = text.replace("{month}", currentMonthName(lang === "bn" ? "bn" : "en"));
        }
        el.textContent = text;
      }
    });
    document.querySelectorAll("[data-i18n-placeholder]").forEach(function (el) {
      var key = el.getAttribute("data-i18n-placeholder");
      if (dict[key] != null) el.setAttribute("placeholder", dict[key]);
    });
    document.querySelectorAll("[data-i18n-title]").forEach(function (el) {
      var key = el.getAttribute("data-i18n-title");
      if (dict[key] != null) el.setAttribute("title", dict[key]);
    });
  }

  function setLangPref(lang) {
    localStorage.setItem(LANG_KEY, lang);
    applyLanguage(lang);
    document.dispatchEvent(new CustomEvent("chteo:langchange", { detail: { lang: lang } }));
  }

  /* =========================================================
     3. AUTH-AWARE PROFILE NAVIGATION (kept from the original site)
     ========================================================= */
  function goToProfile() {
    var isLoggedIn = localStorage.getItem(AUTH_KEY) === "true";
    window.location.href = isLoggedIn ? "profile.html" : "chteo_auth.html";
  }
  window.goToProfile = goToProfile;

  /* =========================================================
     4. BOTTOM NAVIGATION + FOOTER (auto-injected on every page)
     ========================================================= */
  var NAV_MAP = {
    "index.html": "home",
    "": "home",
    "refer-earn.html": "refer",
    "support.html": "support",
    "wallet.html": "wallet",
    "profile.html": "profile"
  };
  // Pages that intentionally have no bottom nav (full-screen auth flow)
  var NO_NAV_PAGES = { "chteo_auth.html": true };

  function currentPageFile() {
    var path = window.location.pathname.split("/").pop();
    return (path || "").toLowerCase();
  }

  function getSavedProfilePicture() {
    try { return localStorage.getItem("profile_picture") || ""; }
    catch (e) { return ""; }
  }

  function buildNavHTML(active) {
    function cls(name) {
      return "navitem" + (active === name ? " active" : "");
    }

    // If the user has set a profile photo (Edit Profile page), show a small
    // circular thumbnail of it in the bottom nav instead of the generic
    // person icon, so it's visible on every page.
    var savedPicture = getSavedProfilePicture();
    var profileIcon = savedPicture
      ? '<img class="nav-avatar" src="' + savedPicture + '" alt="">'
      : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9.2"/><circle cx="12" cy="9.6" r="3"/><path d="M6 18.2c1.1-2.5 3.2-3.8 6-3.8s4.9 1.3 6 3.8"/></svg>';

    return (
      '<a class="' + cls("home") + '" href="index.html">' +
        '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3.2 3 10.5V21h6.2v-6.4h5.6V21H21V10.5L12 3.2Z"/></svg>' +
        '<div class="lbl" data-i18n="nav.home">Home</div></a>' +
      '<a class="' + cls("refer") + '" href="refer-earn.html">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9.5" cy="8" r="3.4"/><path d="M3 20c0-3.9 2.9-6.6 6.5-6.6S16 16.1 16 20"/><path d="M18.5 8v5M16 10.5h5"/></svg>' +
        '<div class="lbl" data-i18n="nav.refer">Refer &amp; Earn</div></a>' +
      '<a class="' + cls("support") + ' support-item" href="support.html">' +
        '<div class="support-fab">\uD83C\uDFA7</div><div class="lbl" data-i18n="nav.support">Support</div></a>' +
      '<a class="' + cls("wallet") + '" href="wallet.html">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 8a2 2 0 0 1 2-2h11a1 1 0 0 1 1 1v2"/><path d="M4 8v9a2 2 0 0 0 2 2h13a1 1 0 0 0 1-1v-7a2 2 0 0 0-2-2H6a2 2 0 0 1-2-2Z"/></svg>' +
        '<div class="lbl" data-i18n="nav.wallet">Wallet</div></a>' +
      '<a class="' + cls("profile") + (savedPicture ? " has-avatar" : "") + '" href="javascript:void(0)" onclick="goToProfile()">' +
        profileIcon +
        '<div class="lbl" data-i18n="nav.profile">Profile</div></a>'
    );
  }

  function injectGlobalUI() {
    var page = currentPageFile();
    var active = NAV_MAP[page] || "";

    // Footer — reuse an existing .app-footer if the page already has one
    // (e.g. index.html), otherwise create it.
    var footer = document.querySelector(".app-footer");
    if (!footer) {
      footer = document.createElement("div");
      footer.className = "app-footer";
      var wrap = document.querySelector(".phone, .screen, .app");
      if (wrap) wrap.appendChild(footer); else document.body.appendChild(footer);
    }
    footer.classList.add("global-footer");
    footer.textContent = "\u00A9CHT ESP ORG. All Rights Reserved";

    // Bottom nav
    if (!NO_NAV_PAGES[page]) {
      var nav = document.querySelector(".bottomnav");
      if (!nav) {
        nav = document.createElement("div");
        nav.className = "bottomnav";
        document.body.appendChild(nav);
      }
      nav.innerHTML = buildNavHTML(active);
    } else {
      document.body.classList.add("no-bottomnav");
    }
  }

  /* =========================================================
     5. STUCK-STATE SAFETY NET (bfcache restore)
     Mobile browsers can restore a page from the back-forward
     cache (bfcache) — e.g. after tapping a card/button that
     navigated away, then pressing Back — with that element's
     native tap-highlight / :hover / :active paint still applied,
     because no new touch event ever fired to clear it. Clearing
     focus and forcing one reflow on restore fixes the stuck
     visual without altering layout, theme, or navigation.
     ========================================================= */
  window.addEventListener("pageshow", function (evt) {
    if (evt.persisted) {
      if (document.activeElement && typeof document.activeElement.blur === "function") {
        document.activeElement.blur();
      }
      document.body.classList.add("chteo-reflow");
      void document.body.offsetHeight; // force a synchronous reflow/repaint
      document.body.classList.remove("chteo-reflow");
    }
  });

  /* =========================================================
     6. MODAL / BOTTOM-SHEET BACK-BUTTON INTEGRATION
     A page opens an in-page modal/sheet by calling
     CHTEO.openModal(closeFn) right after showing it, and closes
     it by calling CHTEO.closeModal() from every UI control that
     dismisses it (X button, backdrop tap, choosing an option,
     etc.) instead of hiding it directly. This makes one press of
     the device/browser Back button close the modal first rather
     than leaving the page, and it removes the extra history entry
     again as soon as the modal is closed normally, so a later
     Back press still only takes one click to leave the page.
     ========================================================= */
  var modalStack = [];
  function openModal(closeFn) {
    modalStack.push(closeFn);
    history.pushState({ chteoModal: true, depth: modalStack.length }, "", window.location.href);
  }
  function closeModal() {
    if (!modalStack.length) return;
    modalStack.pop();
    if (history.state && history.state.chteoModal) {
      history.back();
    }
  }
  window.addEventListener("popstate", function () {
    // Reached when the user presses Back while a modal we opened is
    // showing: the browser has already moved off our pushed state,
    // so just close the modal in place — don't navigate further.
    if (modalStack.length && !(history.state && history.state.chteoModal)) {
      var fn = modalStack.pop();
      if (typeof fn === "function") fn();
    }
  });

  /* =========================================================
     7. INIT
     ========================================================= */
  function init() {
    injectGlobalUI();
    applyLanguage(getLangPref());
    // If a session already exists (e.g. user reopens the app / lands on
    // profile.html directly), quietly refresh the cached profile from
    // Supabase so real email/UID show up instead of stale/placeholder data.
    if (localStorage.getItem(AUTH_KEY) === "true") {
      syncProfile();
      startBanWatcher();
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  // Public API used by theme.html, language-select.html, and any page
  // with its own modal/bottom-sheet (edit_profile.html, index.html, ...)
  window.CHTEO = {
    getThemePref: getThemePref,
    setThemePref: setThemePref,
    resolveTheme: resolveTheme,
    getLangPref: getLangPref,
    setLangPref: setLangPref,
    applyLanguage: applyLanguage,
    translations: translations,
    openModal: openModal,
    closeModal: closeModal,
    syncProfile: syncProfile,
    getSupabaseClient: getSupabaseClient,
    startBanWatcher: startBanWatcher
  };
})();
