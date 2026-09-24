<?php
/**
 * Подтверждение опасных действий («Удалить?») своим окном вместо window.confirm.
 * Подключается из обоих лейаутов (жители и Совет) и обязательно ПЕРЕД
 * photo-shrink и submit-guard: они пропускают отменённую отправку.
 *
 * Разметка: data-confirm="Текст вопроса" на форме.
 *
 * Почему не window.confirm: в мини-приложениях Telegram и MAX системное окно
 * выглядит чужим (с адресом сайта в заголовке), а часть клиентов его вовсе
 * глушит — confirm сразу возвращает false, и кнопка «Удалить» молча ничего
 * не делает. Своё окно одинаково работает везде и не ждёт SDK мессенджера.
 */
?>
<div class="res-confirm" id="resConfirm" hidden>
    <div class="res-confirm-box" role="alertdialog" aria-modal="true" aria-labelledby="resConfirmText">
        <p class="res-confirm-text" id="resConfirmText"></p>
        <div class="res-confirm-actions">
            <button type="button" class="res-btn res-btn--ghost js-confirm-no">Отмена</button>
            <button type="button" class="res-btn js-confirm-yes">Да</button>
        </div>
    </div>
</div>
<script>
(function () {
  var box = document.getElementById('resConfirm');
  if (!box) { return; }
  var text = box.querySelector('.res-confirm-text');
  var yes = box.querySelector('.js-confirm-yes');
  var no = box.querySelector('.js-confirm-no');
  var pending = null;   // {form, submitter}, пока окно открыто

  function close(ok) {
    var p = pending;
    pending = null;
    box.hidden = true;
    if (!p) { return; }
    if (!ok) { if (p.submitter && p.submitter.focus) { p.submitter.focus(); } return; }
    // Повторная отправка проходит мимо окна по отметке — и дальше её как обычно
    // подхватывают photo-shrink и submit-guard.
    p.form.dataset.confirmed = '1';
    if (p.form.requestSubmit) {
      p.form.requestSubmit(p.submitter && p.submitter.form === p.form ? p.submitter : undefined);
    } else {
      p.form.submit();
    }
  }

  // Захват: срабатываем раньше любых других обработчиков отправки.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') { return; }
    if (form.dataset.confirmed) { delete form.dataset.confirmed; return; }
    var msg = form.getAttribute('data-confirm');
    if (!msg) { return; }
    e.preventDefault();
    pending = { form: form, submitter: e.submitter || null };
    text.textContent = msg;
    box.hidden = false;
    yes.focus();
  }, true);

  yes.addEventListener('click', function () { close(true); });
  no.addEventListener('click', function () { close(false); });
  box.addEventListener('click', function (e) { if (e.target === box) { close(false); } });   // тап мимо окна
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !box.hidden) { close(false); } });
})();
</script>
