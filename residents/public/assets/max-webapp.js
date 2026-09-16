/**
 * Загрузка MAX Bridge (мини-приложения MAX), по образцу assets/tg-webapp.js.
 *
 * SDK подключается асинхронно и с собственным таймаутом, колбёк вызывается
 * всегда — с объектом window.WebApp либо с null. Страница рисуется сразу, а
 * вход не зависит от доступности CDN: подписанный initData MAX кладёт прямо в
 * ссылку запуска (#WebAppData=…) — это та же строка, что отдаёт
 * window.WebApp.initData, и сервер проверяет её (MaxWebApp::verify).
 *
 * Документация: https://dev.max.ru/docs/webapps/bridge
 * ES5 — как и остальной клиентский код портала.
 */
(function (w, d) {
  'use strict';

  var SCRIPT_SRC = 'https://st.max.ru/js/max-web-app.js';
  var TIMEOUT_MS = 8000;

  var waiters = null; // колбэки, ждущие текущей загрузки

  function current() {
    return w.WebApp || null;
  }

  function flush(wa) {
    var list = waiters;
    waiters = null; // неудачу не кэшируем: повторный вызов пробует снова
    if (!list) { return; }
    for (var i = 0; i < list.length; i++) {
      try { list[i](wa); } catch (e) {}
    }
  }

  /** Вызывает cb(webApp|null). Никогда не «повисает». */
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
   * initData из параметров запуска — без участия SDK. MAX кладёт их во фрагмент
   * ссылки как #WebAppData=<url-encoded>. Декодируем вручную (не URLSearchParams:
   * тот трактует «+» как пробел и испортил бы HMAC на сервере).
   */
  function launchInitData() {
    var pick = function (src) {
      if (!src) { return ''; }
      var m = /[#&?]WebAppData=([^&]*)/.exec(src);
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

  /** start_param (deep-link) из SDK, query запуска или поля внутри initData. */
  function startParam(wa) {
    if (wa && wa.initDataUnsafe && wa.initDataUnsafe.start_param) {
      return wa.initDataUnsafe.start_param;
    }
    try {
      var qs = new URLSearchParams(w.location.search);
      var fromQuery = qs.get('startapp');
      if (fromQuery) { return fromQuery; }

      var raw = launchInitData();
      if (raw) {
        var sp = new URLSearchParams(raw).get('start_param');
        if (sp) { return sp; }
      }
    } catch (e) {}
    return '';
  }

  w.SkazMax = {
    ensure: ensure,
    initData: initData,
    startParam: startParam,
    launchInitData: launchInitData
  };
})(window, document);
