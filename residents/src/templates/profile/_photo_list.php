<?php
use SkazResidents\{Csrf, View};
/** @var array $images @var string $photoDeleteBase — база URL удаления фото (…/foto) */
?>
<?php if (!empty($images)): ?>
    <div class="res-meta" style="margin-top:1rem">Уже загружено (нажмите ×, чтобы удалить):</div>
    <div class="photo-preview">
        <?php foreach ($images as $img): ?>
            <form class="photo-uploaded" method="post" action="<?= $photoDeleteBase ?>/<?= (int) $img['id'] ?>/udalit" onsubmit="return confirm('Удалить это фото?')">
                <?= Csrf::field() ?>
                <img class="photo-thumb" src="<?= View::e(entry_image_url($img['path'])) ?>" alt="">
                <button type="submit" class="photo-del" title="Удалить фото">×</button>
            </form>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
(function () {
  var inp = document.getElementById('profilePhotos'), box = document.getElementById('profilePhotoPreview');
  if (!inp || !box || typeof DataTransfer === 'undefined') { return; }
  var dt = new DataTransfer();
  inp.addEventListener('change', function () {
    // Накопительно добавляем выбранные фото (можно выбирать несколько раз).
    Array.prototype.forEach.call(inp.files, function (f) { if (/^image\//.test(f.type)) { dt.items.add(f); } });
    inp.files = dt.files;
    render();
  });
  function render() {
    box.innerHTML = '';
    Array.prototype.forEach.call(dt.files, function (f, idx) {
      var wrap = document.createElement('span'); wrap.className = 'photo-uploaded';
      var img = document.createElement('img'); img.className = 'photo-thumb'; img.src = URL.createObjectURL(f);
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'photo-del'; btn.textContent = '×'; btn.title = 'Убрать';
      btn.addEventListener('click', function () { dt.items.remove(idx); inp.files = dt.files; render(); });
      wrap.appendChild(img); wrap.appendChild(btn); box.appendChild(wrap);
    });
  }
})();
</script>
