// Кастомный виджет Decap CMS «tgimage» — загрузка фото прямо из формы
// редактора в приватный Telegram-канал «Skaz-Kray Media». Выбор файла →
// POST на /tg-media-admin/upload.php (гейт паролем редактора, тем же, что
// у входа в /editor/) → в поле подставляется постоянный URL
// https://skaz-kray.ru/tg-media/<file_id>.jpg. Можно и вставить ссылку
// вручную. Подключается из public/editor/index.html и public/admin/index.html
// после decap-cms.js. Серверная часть (upload/serve/канал) — см. память
// skaz-kray-tg-media-storage, живёт вне git на сервере.
(function () {
  var h = window.h;
  var createClass = window.createClass;
  if (!window.CMS || !h || !createClass) { return; }

  var UPLOAD_URL = '/tg-media-admin/upload.php';
  var PW_KEY = 'tgUploadPw'; // пароль редактора кэшируется на сессию

  var Control = createClass({
    getInitialState: function () {
      return { uploading: false, error: null };
    },

    handleFile: function (e) {
      var self = this;
      var file = e.target.files && e.target.files[0];
      e.target.value = ''; // позволить повторный выбор того же файла
      if (!file) { return; }

      var pw = window.sessionStorage.getItem(PW_KEY);
      if (!pw) {
        pw = window.prompt('Пароль редактора для загрузки фото (тот же, что для входа):');
        if (!pw) { return; }
        window.sessionStorage.setItem(PW_KEY, pw);
      }

      var fd = new FormData();
      fd.append('photo', file);
      fd.append('password', pw);

      self.setState({ uploading: true, error: null });
      fetch(UPLOAD_URL, { method: 'POST', body: fd })
        .then(function (r) {
          return r.json().then(function (d) { return { ok: r.ok, data: d }; });
        })
        .then(function (res) {
          if (!res.ok || !res.data || res.data.error) {
            var msg = (res.data && res.data.error) || ('Ошибка ' + '(' + 'HTTP' + ')');
            // неверный пароль — сбрасываем кэш, чтобы спросить заново
            if (/парол/i.test(msg)) { window.sessionStorage.removeItem(PW_KEY); }
            self.setState({ uploading: false, error: msg });
            return;
          }
          self.props.onChange(res.data.url);
          self.setState({ uploading: false, error: null });
        })
        .catch(function (err) {
          self.setState({ uploading: false, error: 'Сеть: ' + err.message });
        });
    },

    handleText: function (e) {
      this.props.onChange(e.target.value);
    },

    render: function () {
      var value = this.props.value || '';
      var children = [
        h('input', {
          type: 'file',
          accept: 'image/jpeg,image/png,image/webp',
          onChange: this.handleFile,
          disabled: this.state.uploading,
          key: 'file'
        })
      ];

      if (this.state.uploading) {
        children.push(h('p', { key: 'up', style: { margin: '6px 0', color: '#555' } }, 'Загружаю фото…'));
      }
      if (this.state.error) {
        children.push(h('p', { key: 'err', style: { margin: '6px 0', color: '#b00020' } }, this.state.error));
      }

      children.push(h('input', {
        type: 'text',
        value: value,
        placeholder: 'или вставьте URL картинки вручную',
        onChange: this.handleText,
        className: this.props.classNameWrapper,
        key: 'text',
        style: { display: 'block', width: '100%', marginTop: '8px', boxSizing: 'border-box' }
      }));

      if (value) {
        children.push(h('img', {
          src: value,
          key: 'preview',
          style: { display: 'block', maxWidth: '260px', marginTop: '8px', borderRadius: '6px' }
        }));
      }

      return h('div', { style: { padding: '4px 0' } }, children);
    }
  });

  var Preview = function (props) {
    return props.value ? h('img', { src: props.value, style: { maxWidth: '100%' } }) : null;
  };

  window.CMS.registerWidget('tgimage', Control, Preview);
})();
