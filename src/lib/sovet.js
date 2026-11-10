// Клиентские помощники раздела /sovet — порт того, что в легаси делали
// View::e / Flash / Csrf-редиректы на сервере. Статика их вынуждена
// повторять на клиенте: API отвечает JSON, а не редиректом со Flash.

/** Экранирование как View::e (легаси передавало текст в шаблоны).
 *  Entity-сущности склеены конкатенацией: цельный '<' в исходнике
 *  декодируется в символ ещё до записи, и esc превращался бы в пустышку. */
const AMP = '&' + 'amp;';
const LT = '&' + 'lt;';
const GT = '&' + 'gt;';
const QUOT = '&' + 'quot;';
const ESC_MAP = { '&': AMP, '<': LT, '>': GT, '"': QUOT };
export function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ESC_MAP[c]);
}

/** Одноразовое сообщение на следующей странице — аналог Flash-сессии. */
export function flash(type, msg) {
  try { sessionStorage.setItem('sovetFlash', JSON.stringify({ type: type, msg: msg })); } catch (e) {}
}

/**
 * fetch-обёртка API совета. Cookie council_token уходит сам (credentials),
 * 401 — сессии нет, как requireLogin() в легаси: на экран входа.
 */
export function api(url, opts) {
  opts = opts || {};
  opts.credentials = 'include';
  opts.cache = 'no-store';
  if (opts.body !== undefined && !(opts.body instanceof FormData)) {
    opts.headers = Object.assign({ 'Content-Type': 'application/json' }, opts.headers);
  }
  return fetch(url, opts).then(function (r) {
    if (r.status === 401) {
      window.location.replace('/sovet/vhod/');
      return Promise.reject({ auth: true });
    }
    return r.json().catch(function () { return {}; }).then(function (data) {
      return { status: r.status, ok: r.ok, data: data };
    });
  });
}

/** POST JSON-ом (тело уже объект — как форма-партал в легаси). */
export function post(url, body) {
  return api(url, { method: 'POST', body: JSON.stringify(body || {}) });
}

/**
 * Успешное действие: сообщение + переход — как Flash::set + header('Location').
 * flash переживает редирект через sessionStorage (одноразовый, читается в лэйауте).
 */
export function done(redirect, type, msg) {
  flash(type || 'success', msg || '');
  window.location.href = redirect;
}

/** Текст ошибки API: сервер отдаёт {error}, поля формы — {fields:{...}}. */
export function failText(res) {
  if (res && res.data) {
    if (res.data.error) return res.data.error;
    if (res.data.fields) {
      var keys = Object.keys(res.data.fields);
      if (keys.length) return res.data.fields[keys[0]];
    }
  }
  return 'Ошибка. Попробуйте ещё раз.';
}