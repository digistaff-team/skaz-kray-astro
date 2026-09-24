<?php
/**
 * Сжатие снимков в браузере перед отправкой формы: подключается из обоих лейаутов
 * (формы с фото есть и у жителей, и в Совете) — и обязательно ПЕРЕД submit-guard,
 * чтобы наш обработчик успел перехватить отправку первым.
 */
?>
<script>
// Телефон снимает кадр на 4–8 МБ, а сервер принимает заметно меньше (upload_max_filesize):
// такой снимок PHP выбрасывает молча — товар сохранялся, а фото к нему не появлялось.
// Ужимаем до тех же 1600 px, до которых доводит Upload::saveImage, прямо на телефоне:
// заодно форма с тремя фото уходит по мобильному интернету секунды, а не минуту.
(function () {
  var MAX_DIM    = 1600;    // как MAX_DIM в Upload::saveImage
  var QUALITY    = 0.82;
  var SHRINK_OVER = 1048576; // 1 МБ: что легче — и так пролезает, трогать незачем

  // Нет DataTransfer или createImageBitmap — молча отходим в сторону: форма
  // отправится как раньше, а слишком тяжёлое вложение отсечёт сервер с внятным текстом.
  if (typeof DataTransfer === 'undefined' || typeof window.createImageBitmap !== 'function') { return; }

  /** Поля с картинками, среди которых есть что ужимать. */
  function heavyInputs(form) {
    return Array.prototype.filter.call(form.querySelectorAll('input[type="file"]'), function (inp) {
      return Array.prototype.some.call(inp.files || [], needsShrink);
    });
  }

  function needsShrink(file) {
    return /^image\/(jpeg|png|webp)$/.test(file.type) && file.size > SHRINK_OVER;
  }

  function shrink(file) {
    return new Promise(function (resolve) {
      if (!needsShrink(file)) { resolve(file); return; }
      // imageOrientation: снимок с телефона несёт поворот в EXIF, а пересохранение
      // его теряет — «запекаем» поворот в сами пиксели, иначе фото ляжет на бок.
      window.createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
        var scale = Math.min(1, MAX_DIM / Math.max(bmp.width, bmp.height));
        var w = Math.round(bmp.width * scale), h = Math.round(bmp.height * scale);
        var canvas = document.createElement('canvas');
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(bmp, 0, 0, w, h);
        if (bmp.close) { bmp.close(); }
        canvas.toBlob(function (blob) {
          // Не стало легче (бывает с мелкими PNG) — отправляем оригинал.
          if (!blob || blob.size >= file.size) { resolve(file); return; }
          resolve(new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg',
            { type: 'image/jpeg', lastModified: Date.now() }));
        }, 'image/jpeg', QUALITY);
      }).catch(function () { resolve(file); }); // не прочитали (HEIC и прочая экзотика) — пусть решает сервер
    });
  }

  function shrinkInput(inp) {
    return Promise.all(Array.prototype.map.call(inp.files, shrink)).then(function (files) {
      var dt = new DataTransfer();
      files.forEach(function (f) { dt.items.add(f); });
      inp.files = dt.files;
    });
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (e.defaultPrevented || !form || form.dataset.shrunk) { return; }
    var inputs = heavyInputs(form);
    if (!inputs.length) { return; }

    // Придерживаем отправку на время пережатия (доли секунды на снимок).
    e.preventDefault();
    var submitter = e.submitter;
    var btn = submitter || form.querySelector('button[type="submit"], button:not([type])');
    var label = null;
    if (btn && btn.tagName === 'BUTTON') {
      btn.disabled = true;
      if (btn.classList.contains('res-btn')) { label = btn.textContent; btn.textContent = 'Готовим фото…'; }
    }

    Promise.all(inputs.map(shrinkInput)).catch(function () {}).then(function () {
      // Кнопку возвращаем в строй: дальше форму подхватывает submit-guard и блокирует её сам.
      if (btn && btn.tagName === 'BUTTON') {
        btn.disabled = false;
        if (label !== null) { btn.textContent = label; }
      }
      form.dataset.shrunk = '1';
      if (form.requestSubmit) { form.requestSubmit(submitter && submitter.form === form ? submitter : undefined); }
      else { form.submit(); }
    });
  });

  // Возврат «назад» достаёт форму из кеша вместе с отметкой — иначе следующая
  // отправка уйдёт с неужатыми снимками.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) { return; }
    Array.prototype.forEach.call(document.querySelectorAll('form[data-shrunk]'), function (form) {
      delete form.dataset.shrunk;
    });
  });
})();
</script>
