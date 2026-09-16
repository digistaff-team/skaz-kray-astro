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
  var TIMEOUT_MS = 8000;

  var waiters = null; // колбэки, ждущие текущей загрузки

  function current() {
    return (w.Telegram && w.Telegram.WebApp) || null;
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
   */
  function launchInitData() {
    var pick = function (src) {
      if (!src) { return ''; }
      var m = /[#&?]tgWebAppData=([^&]*)/.exec(src);
      if (!m) { return ''; }
      try { return decodeURIComponent(m[1]); } catch (e) { return ''; }
    };
    return pick(w.location.hash) || pick(w.location.search);
  }

  /** initData из SDK, а если его нет — из ссылки запуска. */
  function initData(wa) {
    if (wa && wa.initData) { return wa.initData; }
    return launchInitData();
  }

  /**
   * start_param (deep-link) из всех источников: SDK, query самой ссылки,
   * hash-параметры запуска и поле start_param внутри initData.
   */
  function startParam(wa) {
    if (wa && wa.initDataUnsafe && wa.initDataUnsafe.start_param) {
      return wa.initDataUnsafe.start_param;
    }
    try {
      var qs = new URLSearchParams(w.location.search);
      var fromQuery = qs.get('startapp') || qs.get('tgWebAppStartParam');
      if (fromQuery) { return fromQuery; }

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

  w.SkazTg = {
    ensure: ensure,
    initData: initData,
    startParam: startParam,
    launchInitData: launchInitData
  };
})(window, document);
