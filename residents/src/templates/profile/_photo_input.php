<?php
// Инпут выбора фото + клиентское превью. Подключается ВНУТРИ формы (.res-form,
// enctype=multipart/form-data). Логика идентична форме дневника.
?>
<label class="file-btn">Добавить фото
    <input type="file" name="photos[]" id="profilePhotos" accept="image/*" multiple hidden>
</label>
<div id="profilePhotoPreview" class="photo-preview"></div>
