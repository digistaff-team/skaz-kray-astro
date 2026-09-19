<?php
/**
 * Защита от повторной отправки форм: подключается из обоих лейаутов
 * (раздел жителей и Совет), так как формы с фото есть и там, и там.
 */
?>
<script>
// Защита от повторной отправки формы. Формы с фото уходят по 10–15 секунд (снимки
// едут в Telegram-хранилище), экран при этом молчит, и житель жмёт кнопку ещё раз — так на
// Ярмарке из одного товара вышло четыре карточки и три анонса в общем чате.
(function () {
  document.addEventListener('submit', function (e) {
    var form = e.target;
    // Отменённая отправка (например, отказ в confirm при удалении) кнопку не блокирует.
    if (e.defaultPrevented || !form || form.dataset.sent) { return; }
    form.dataset.sent = '1';
    // Блокируем именно нажатую кнопку (в форме их бывает несколько — например, смена статуса закупки).
    var btn = e.submitter || form.querySelector('button[type="submit"], button:not([type])');
    if (!btn || btn.tagName !== 'BUTTON') { return; }
    // Блокируем следующим тиком: disabled до отправки выкидывает кнопку из данных формы.
    setTimeout(function () {
      btn.disabled = true;
      // Подпись меняем только у обычных кнопок — у иконок («×» на фото) она бы расползлась.
      if (btn.classList.contains('res-btn')) {
        btn.dataset.label = btn.textContent;
        btn.textContent = 'Отправляем…';
      }
    }, 0);
  });

  // Возврат «назад» достаёт страницу из кеша вместе с заблокированной кнопкой — возвращаем форме рабочий вид.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) { return; }
    Array.prototype.forEach.call(document.querySelectorAll('form[data-sent]'), function (form) {
      delete form.dataset.sent;
    });
    Array.prototype.forEach.call(document.querySelectorAll('button[disabled]'), function (btn) {
      btn.disabled = false;
      if (btn.dataset.label) { btn.textContent = btn.dataset.label; delete btn.dataset.label; }
    });
  });
})();
</script>
