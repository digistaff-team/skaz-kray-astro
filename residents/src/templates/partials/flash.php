<?php use SkazResidents\Flash; use SkazResidents\View; ?>
<?php foreach (Flash::take() as $f): ?>
    <div class="res-flash res-flash--<?= View::e($f['type']) ?>"><?= View::e($f['message']) ?></div>
<?php endforeach; ?>
