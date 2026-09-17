<?php
/**
 * Pied de page commun. Variable optionnelle : $useCharts (bool)
 */
defined('APP_ROOT') || exit;

$lastSync = (int) Db::meta('last_sync', 0);
$syncError = (string) Db::meta('sync_error', '');
$syncWarning = (string) Db::meta('sync_warning', '');
?>
</main>
<footer class="footer">
  <div class="container footer__inner">
    <span>Données mises à jour <?= h(fmt_ago($lastSync)) ?><?php if ($syncError !== ''): ?> · <span class="text-danger" title="<?= h($syncError) ?>">erreur de synchronisation</span><?php endif; ?><?php if ($syncError === '' && $syncWarning !== ''): ?> · <span class="text-warning" title="<?= h($syncWarning) ?>">⚠ données partielles</span><?php endif; ?></span>
    <span class="muted">Site non officiel, non affilié à Mojang ou Microsoft.</span>
  </div>
</footer>
<div class="tooltip" id="tooltip" role="tooltip" hidden></div>
<?php if (!empty($useCharts)): ?>
<script src="assets/vendor/chart.umd.min.js" defer></script>
<?php endif; ?>
<?php if (!empty($useMap)): ?>
<script src="assets/vendor/leaflet.js" defer></script>
<script src="<?= h(asset_url('assets/js/map.js')) ?>" defer></script>
<?php endif; ?>
<script src="<?= h(asset_url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
