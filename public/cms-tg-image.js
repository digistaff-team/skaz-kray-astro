// Кастомные виджеты Decap CMS для загрузки фото прямо из формы редактора
// в приватный Telegram-канал «Skaz-Kray Media»:
//   • tgimage   — одно фото (поле «Обложка»);
//   • tggallery — галерея: выбор сразу нескольких файлов (multiple),
//                 пакетная загрузка с прогрессом, миниатюры, удаление,
//                 изменение порядка. Значение — массив URL.
// Каждый файл уходит POST на /api/tg-media/upload (гейт паролем редактора,
// тем же, что у входа в /editor/) → постоянный URL
// https://skaz-kray.ru/tg-media/<file_id>.jpg. Оба пути обслуживает API
// residents-v2 на Vercel: /api/* ревритится на него напрямую, /tg-media/* —
// тоже (PHP-раздача и /tg-media-admin больше не существуют). Подключается из
// public/{editor,admin}/index.html после decap-cms.js.
(function () {
  var h = window.h;
  var createClass = window.createClass;
  if (!window.CMS || !h || !createClass) { return; }

  var UPLOAD_URL = '/api/tg-media/upload';
  var PW_KEY = 'tgUploadPw'; // пароль редактора кэшируется на сессию
  var ACCEPT = 'image/jpeg,image/png,image/webp';

  // ── Клиентское сжатие ─────────────────────────────────────────────────────
  // Снимок с телефона (12 Мп, HDR) весит 3–8 МБ и упирается в потолок тела
  // запроса Vercel (4.5 МБ): редактор получал бы 413 или сетевую ошибку.
  // canvas → JPEG q0.85, длинная сторона до 2000px: тот же снимок — 200–700 КБ,
  // на глаз для веба неотличимо.
  var MAX_EDGE = 2000;
  var QUALITY = 0.85;
  var TARGET_BYTES = 4 * 1024 * 1024; // с запасом под платформенные 4.5 МБ

  function compress(file) {
    if (!/^image\//.test(file.type)) {
      return Promise.resolve(file);
    }
    var url = URL.createObjectURL(file);
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload = function () { resolve(img); };
      img.onerror = function () { reject(new Error('decode')); };
      img.src = url;
    }).then(function (img) {
      var w = img.naturalWidth || img.width;
      var h = img.naturalHeight || img.height;
      var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
      var cw = Math.max(1, Math.round(w * scale));
      var ch = Math.max(1, Math.round(h * scale));
      var canvas = document.createElement('canvas');
      canvas.width = cw;
      canvas.height = ch;
      var ctx = canvas.getContext('2d');
      if (!ctx) { return null; }
      // JPEG не хранит прозрачность — белая подложка, иначе PNG с альфой
      // получит чёрный фон.
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, cw, ch);
      ctx.drawImage(img, 0, 0, cw, ch);
      return new Promise(function (resolve) {
        canvas.toBlob(function (b) { resolve(b); }, 'image/jpeg', QUALITY);
      });
    }).then(function (blob) {
      // Сжатие не всегда выгодно (скриншоты уже оптимизированы): берём
      // результат, только если он реально меньше исходника.
      if (!blob || blob.size >= file.size) { return file; }
      return new File([blob], String(file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
    }).catch(function () {
      // Сжатие не должно ломать загрузку: не смогли — отдаём исходник,
      // о размере и формате отчитается сервер.
      return file;
    }).then(function (out) {
      URL.revokeObjectURL(url);
      return out;
    });
  }

  // Пароль редактора: из кэша сессии, иначе спрашиваем один раз.
  function getPassword() {
    var pw = window.sessionStorage.getItem(PW_KEY);
    if (!pw) {
      pw = window.prompt('Пароль редактора для загрузки фото (тот же, что для входа):');
      if (pw) { window.sessionStorage.setItem(PW_KEY, pw); }
    }
    return pw;
  }

  // Загрузка одного файла. resolve({url}) | reject(Error). При неверном
  // пароле сбрасываем кэш, чтобы спросить заново на следующей попытке.
  function uploadOne(file, pw) {
    return compress(file).then(function (small) {
      // Остаток сверх лимита обрезать нельзя, а запрос к Vercel оборвётся
      // невнятной сетевой ошибкой — честнее сказать это до отправки.
      if (small.size > TARGET_BYTES) {
        throw new Error('Файл больше 4 МБ даже после сжатия — уменьшите фото перед загрузкой.');
      }
      var fd = new FormData();
      fd.append('photo', small);
      fd.append('password', pw);
      return fetch(UPLOAD_URL, { method: 'POST', body: fd });
    })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
      .then(function (res) {
        if (!res.ok || !res.data || res.data.error) {
          var msg = (res.data && res.data.error) || 'ошибка загрузки';
          if (/парол/i.test(msg)) { window.sessionStorage.removeItem(PW_KEY); }
          throw new Error(msg);
        }
        return res.data.url;
      });
  }

  // Нормализация значения виджета в JS-массив строк (Decap отдаёт
  // Immutable.List при загрузке существующей записи).
  function toArr(v) {
    if (!v) { return []; }
    if (Array.isArray(v)) { return v.slice(); }
    if (typeof v.toArray === 'function') { return v.toArray(); }
    return [];
  }

  // --- Виджет одиночного фото (обложка) --------------------------------------
  var Single = createClass({
    getInitialState: function () { return { uploading: false, error: null }; },

    handleFile: function (e) {
      var self = this;
      var file = e.target.files && e.target.files[0];
      e.target.value = '';
      if (!file) { return; }
      var pw = getPassword();
      if (!pw) { return; }
      self.setState({ uploading: true, error: null });
      uploadOne(file, pw).then(function (url) {
        self.props.onChange(url);
        self.setState({ uploading: false, error: null });
      }).catch(function (err) {
        self.setState({ uploading: false, error: err.message });
      });
    },

    handleText: function (e) { this.props.onChange(e.target.value); },

    render: function () {
      var value = this.props.value || '';
      var kids = [
        h('input', { type: 'file', accept: ACCEPT, onChange: this.handleFile, disabled: this.state.uploading, key: 'file' })
      ];
      if (this.state.uploading) { kids.push(h('p', { key: 'up', style: { margin: '6px 0', color: '#555' } }, 'Загружаю фото…')); }
      if (this.state.error) { kids.push(h('p', { key: 'err', style: { margin: '6px 0', color: '#b00020' } }, this.state.error)); }
      kids.push(h('input', {
        type: 'text', value: value, placeholder: 'или вставьте URL картинки вручную',
        onChange: this.handleText, className: this.props.classNameWrapper, key: 'text',
        style: { display: 'block', width: '100%', marginTop: '8px', boxSizing: 'border-box' }
      }));
      if (value) { kids.push(h('img', { src: value, key: 'prev', style: { display: 'block', maxWidth: '260px', marginTop: '8px', borderRadius: '6px' } })); }
      return h('div', { style: { padding: '4px 0' } }, kids);
    }
  });

  // --- Виджет галереи (пакетная загрузка) ------------------------------------
  var miniBtn = {
    border: 'none', background: '#e8efe9', color: '#1f2a20', borderRadius: '4px',
    cursor: 'pointer', width: '26px', height: '22px', lineHeight: '20px', fontSize: '15px'
  };

  var GalleryW = createClass({
    getInitialState: function () { return { uploading: false, done: 0, total: 0, error: null }; },

    handleFiles: function (e) {
      var self = this;
      var files = Array.prototype.slice.call(e.target.files || []);
      e.target.value = '';
      if (!files.length) { return; }
      var pw = getPassword();
      if (!pw) { return; }

      var current = toArr(self.props.value);
      self.setState({ uploading: true, done: 0, total: files.length, error: null });

      var i = 0;
      function next() {
        if (i >= files.length) { self.setState({ uploading: false }); return; }
        uploadOne(files[i], pw).then(function (url) {
          current = current.concat([url]);
          self.props.onChange(current);           // сохраняем после каждого файла
          i += 1;
          self.setState({ done: i });
          next();
        }).catch(function (err) {
          // прерываем пакет на первой ошибке, уже загруженные — сохранены
          self.setState({ uploading: false, error: err.message + ' (загружено ' + i + ' из ' + files.length + ')' });
        });
      }
      next();
    },

    remove: function (idx) {
      var arr = toArr(this.props.value);
      arr.splice(idx, 1);
      this.props.onChange(arr);
    },

    move: function (idx, dir) {
      var arr = toArr(this.props.value);
      var j = idx + dir;
      if (j < 0 || j >= arr.length) { return; }
      var t = arr[idx]; arr[idx] = arr[j]; arr[j] = t;
      this.props.onChange(arr);
    },

    render: function () {
      var self = this;
      var arr = toArr(this.props.value);
      var kids = [
        h('input', { type: 'file', accept: ACCEPT, multiple: true, onChange: this.handleFiles, disabled: this.state.uploading, key: 'file' }),
        h('p', { key: 'hint', style: { margin: '4px 0', color: '#777', fontSize: '13px' } },
          'Можно выбрать сразу несколько фото — загрузятся пакетом.')
      ];

      if (this.state.uploading) {
        kids.push(h('p', { key: 'up', style: { margin: '6px 0', color: '#555' } },
          'Загружаю ' + Math.min(this.state.done + 1, this.state.total) + ' из ' + this.state.total + '…'));
      }
      if (this.state.error) {
        kids.push(h('p', { key: 'err', style: { margin: '6px 0', color: '#b00020' } }, this.state.error));
      }

      var thumbs = arr.map(function (url, idx) {
        return h('div', { key: 't' + idx, style: { width: '96px' } }, [
          h('div', { key: 'w', style: { position: 'relative' } }, [
            h('img', { key: 'i', src: url, style: { width: '96px', height: '96px', objectFit: 'cover', borderRadius: '6px', display: 'block' } }),
            h('button', {
              key: 'x', type: 'button', title: 'Удалить', onClick: function () { self.remove(idx); },
              style: { position: 'absolute', top: '3px', right: '3px', border: 'none', background: 'rgba(0,0,0,0.6)', color: '#fff', borderRadius: '50%', width: '22px', height: '22px', lineHeight: '20px', cursor: 'pointer' }
            }, '×')
          ]),
          h('div', { key: 'm', style: { display: 'flex', justifyContent: 'space-between', marginTop: '3px' } }, [
            h('button', { key: 'l', type: 'button', title: 'Левее', onClick: function () { self.move(idx, -1); }, disabled: idx === 0, style: miniBtn }, '‹'),
            h('button', { key: 'r', type: 'button', title: 'Правее', onClick: function () { self.move(idx, 1); }, disabled: idx === arr.length - 1, style: miniBtn }, '›')
          ])
        ]);
      });
      kids.push(h('div', { key: 'grid', style: { display: 'flex', flexWrap: 'wrap', gap: '10px', marginTop: '10px' } }, thumbs));

      return h('div', { style: { padding: '4px 0' } }, kids);
    }
  });

  var singlePreview = function (props) {
    return props.value ? h('img', { src: props.value, style: { maxWidth: '100%' } }) : null;
  };
  var galleryPreview = function (props) {
    var arr = toArr(props.value);
    if (!arr.length) { return null; }
    return h('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '6px' } },
      arr.map(function (url, i) { return h('img', { key: i, src: url, style: { width: '80px', height: '80px', objectFit: 'cover', borderRadius: '4px' } }); }));
  };

  window.CMS.registerWidget('tgimage', Single, singlePreview);
  window.CMS.registerWidget('tggallery', GalleryW, galleryPreview);
})();
