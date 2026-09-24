/**
 * Загрузка Telegram WebApp SDK, устойчивая к недоступности telegram.org.
 *
 * Зачем: раньше каждый шаблон подключал SDK синхронным тегом
 * <script src="https://telegram.org/js/telegram-web-app.js"></script>. Такой тег
 * блокирует разбор документа, и если telegram.org не отказывает в соединении, а
 * молчит (пакеты отбрасываются — так выглядит блокировка у части операторов),
 * страница не отрисовывается вовсе, пока не истечёт системный таймаут TCP.
 * Сам мессенджер при этом работает: он ходит через адреса DC, а не telegram.org.
 *
 * Здесь SDK грузится асинхронно и с собственным таймаутом, а колбэк вызывается
 * всегда — с объектом WebApp либо с null. Страница рисуется сразу.
 *
 * Главное: вход больше не зависит от SDK. Подписанный initData Telegram кладёт
 * прямо в ссылку запуска (#tgWebAppData=…) — это та же строка, что отдаёт
 * window.Telegram.WebApp.initData, и сервер проверяет её обычным путём
 * (TelegramWebApp::verify). См. SkazTg.initData().
 *
 * ES5 — как и остальной клиентский код портала.
 */
(function (w, d) {
  'use strict';

  var SCRIPT_SRC = 'https://telegram.org/js/telegram-web-app.js';
  var ASKED_KEY = 'skazWriteAccessAsked';   // «уже спрашивали на этом устройстве»
  var TIMEOUT_MS = 8000;

  var waiters = null; // колбэки, ждущие текущей загрузки

  function current() {
    return (w.Telegram && w.Telegram.WebApp) || null;
  }

  /**
   * Стоит ли вообще идти за SDK на telegram.org.
   *
   * Внутри Telegram клиент отдаёт SDK сам, и запроса не возникает. А вот в
   * мини-приложении MAX и в обычном браузере запрос уходит — и висит: у части
   * операторов telegram.org не отказывает в соединении, а молчит, так что
   * ответа нет до системного таймаута TCP. Страница при этом отрисована
   * (скрипт async), но вкладка остаётся «в загрузке», и хост мини-приложения
   * держит свой индикатор — снаружи это выглядит как «раздел грузится вечно».
   * Толку от SDK там всё равно нет: openTelegramLink есть только в Telegram.
   *
   * Признаки Telegram по убыванию надёжности: SDK уже в окне; платформа из
   * сессии (см. Auth::setPlatform); мост, который нативный клиент вставляет в
   * webview; параметры запуска Mini App в самой ссылке (страницы входа).
   */
  function inTelegram() {
    if (current()) { return true; }
    if (w.SkazPlatform === 'tg') { return true; }
    if (w.SkazPlatform === 'max' || w.SkazPlatform === 'web') { return false; }
    return !!(w.TelegramWebviewProxy || w.TelegramWebviewProxyProto || launchInitData());
  }

  function flush(wa) {
    var list = waiters;
    waiters = null; // неудачу не кэшируем: повторный вызов пробует снова
    if (!list) { return; }
    for (var i = 0; i < list.length; i++) {
      try { list[i](wa); } catch (e) {}
    }
  }

  /**
   * Вызывает cb(webApp|null). Никогда не «повисает»: при молчащем хосте
   * колбэк придёт по таймауту с null.
   */
  function ensure(cb, timeoutMs) {
    if (typeof cb !== 'function') { return; }

    var ready = current();
    if (ready) { cb(ready); return; }

    // Не Telegram — за SDK не идём вовсе: колбэк получает null сразу, как если
    // бы загрузка не удалась. Все вызывающие этот случай уже обрабатывают.
    if (!inTelegram()) { cb(null); return; }

    if (waiters) { waiters.push(cb); return; } // загрузка уже идёт
    waiters = [cb];

    var settled = false;
    var timer = null;

    function finish() {
      if (settled) { return; }
      settled = true;
      if (timer) { clearTimeout(timer); }
      flush(current());
    }

    timer = setTimeout(finish, timeoutMs || TIMEOUT_MS);

    var existing = d.querySelector('script[src="' + SCRIPT_SRC + '"]');
    if (existing) {
      // На уже вставленном теге слушаем и error: иначе неудачная вставка
      // оставила бы следующий вызов ждать вечно.
      existing.addEventListener('load', finish, { once: true });
      existing.addEventListener('error', finish, { once: true });
      return;
    }

    var sc = d.createElement('script');
    sc.src = SCRIPT_SRC;
    sc.async = true;
    sc.onload = finish;
    sc.onerror = finish;
    (d.head || d.body || d.documentElement).appendChild(sc);
  }

  /**
   * initData из параметров запуска Mini App — без участия SDK.
   *
   * Декодируем вручную через decodeURIComponent, а не URLSearchParams:
   * последний трактует «+» как пробел, что испортило бы строку и сломало HMAC
   * на сервере.
   *
   * Параметры запуска есть только на первой странице: дальше Mini App ходит по
   * обычным ссылкам и хеш теряется. Поэтому запоминаем строку в sessionStorage —
   * она живёт ровно столько, сколько открыто мини-приложение. По ней житель
   * входит заново, если сессия на сервере истекла (auth/login.php), и по ней же
   * inTelegram() узнаёт Telegram на любой странице, а не только на первой.
   */
  var STORE_KEY = 'skazTgInitData';

  function launchInitData() {
    var pick = function (src) {
      if (!src) { return ''; }
      var m = /[#&?]tgWebAppData=([^&]*)/.exec(src);
      if (!m) { return ''; }
      try { return decodeURIComponent(m[1]); } catch (e) { return ''; }
    };
    var fresh = pick(w.location.hash) || pick(w.location.search);
    try {
      if (fresh) { w.sessionStorage.setItem(STORE_KEY, fresh); return fresh; }
      return w.sessionStorage.getItem(STORE_KEY) || '';
    } catch (e) {
      return fresh;   // хранилище недоступно (приватный режим и т.п.) — как раньше
    }
  }

  /** initData из SDK, а если его нет — из ссылки запуска. */
  function initData(wa) {
    if (wa && wa.initData) { return wa.initData; }
    return launchInitData();
  }

  /**
   * start_param (deep-link) из всех источников: query самой ссылки, SDK,
   * hash-параметры запуска и поле start_param внутри initData.
   *
   * Query — первым: это указание именно для этого перехода. При повторном входе
   * (auth/login.php) туда кладут страницу, где житель был, а SDK и initData
   * помнят параметр исходного запуска — со старой ссылки из чата. При обычном
   * запуске все источники и так совпадают.
   */
  function startParam(wa) {
    try {
      var qs = new URLSearchParams(w.location.search);
      var fromQuery = qs.get('startapp') || qs.get('tgWebAppStartParam');
      if (fromQuery) { return fromQuery; }
    } catch (e) {}
    if (wa && wa.initDataUnsafe && wa.initDataUnsafe.start_param) {
      return wa.initDataUnsafe.start_param;
    }
    try {
      var hs = new URLSearchParams(String(w.location.hash).replace(/^#/, ''));
      var fromHash = hs.get('tgWebAppStartParam');
      if (fromHash) { return fromHash; }

      var raw = launchInitData();
      if (raw) {
        var sp = new URLSearchParams(raw).get('start_param');
        if (sp) { return sp; }
      }
    } catch (e) {}
    return '';
  }

  /**
   * Разовый запрос разрешения боту писать в личку (Bot API 6.9+).
   *
   * Запуск мини-приложения сам по себе такого права НЕ даёт: писать первым бот
   * может только тем, кто нажал Start в чате или согласился здесь. Поэтому при
   * входе один раз показываем системный диалог — иначе уведомления о бронях,
   * выдаче и возврате до жителя не дойдут.
   *
   * Спрашиваем только если право ещё не выдано (initDataUnsafe.user
   * .allows_write_to_pm) и если на этом устройстве ещё не спрашивали: повторно
   * дёргать человека, однажды отказавшего, невежливо. cb вызывается ровно один
   * раз в любом случае — и после ответа, и если диалог не поддержан или завис,
   * чтобы вход не мог из-за него застопориться.
   */
  function askWriteAccess(wa, cb, timeoutMs) {
    var done = false;
    var finish = function () { if (!done) { done = true; try { cb(); } catch (e) {} } };
    var timer = w.setTimeout(finish, timeoutMs || 6000);

    try {
      var asked = false;
      try { asked = w.localStorage.getItem(ASKED_KEY) === '1'; } catch (e) {}
      var user = wa && wa.initDataUnsafe ? wa.initDataUnsafe.user : null;
      var supported = wa && typeof wa.requestWriteAccess === 'function'
        && (typeof wa.isVersionAtLeast !== 'function' || wa.isVersionAtLeast('6.9'));

      if (!supported || asked || (user && user.allows_write_to_pm)) {
        w.clearTimeout(timer); finish(); return;
      }
      try { w.localStorage.setItem(ASKED_KEY, '1'); } catch (e) {}
      wa.requestWriteAccess(function () { w.clearTimeout(timer); finish(); });
    } catch (e) {
      w.clearTimeout(timer); finish();
    }
  }

  w.SkazTg = {
    ensure: ensure,
    initData: initData,
    startParam: startParam,
    launchInitData: launchInitData,
    askWriteAccess: askWriteAccess
  };
})(window, document);
