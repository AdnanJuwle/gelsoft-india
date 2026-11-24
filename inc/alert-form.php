<?php
/**
 * "Tell me when it lands." Set $alert_category (blaster|gear|part|ammo) or
 * $alert_product (an id) before requiring this.
 *
 * It posts to /api/alerts.php and comes back here, so it works with no
 * JavaScript; site.js upgrades it to a fetch that does not reload the page.
 */
$alert_category = $alert_category ?? null;
$alert_product  = $alert_product ?? null;
$alert_done     = isset($_GET['alerted']);
?>
<?php if ($alert_done): ?>
  <p class="notice notice--olive alert-form__done" role="status">
    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2.2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <span class="small">You are on the list. One message when it lands, nothing else.</span>
  </p>
<?php else: ?>
  <form class="inline-form alert-form" method="post" action="/api/alerts" data-alert style="justify-content:center;margin-bottom:18px">
    <?= csrf_field() ?>
    <input type="hidden" name="return" value="<?= e(strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?')) ?>">
    <?php if ($alert_product): ?><input type="hidden" name="product_id" value="<?= (int) $alert_product ?>"><?php endif; ?>
    <?php if ($alert_category): ?><input type="hidden" name="category" value="<?= e($alert_category) ?>"><?php endif; ?>
    <label class="sr-only" for="alert-contact">Email or WhatsApp number</label>
    <input class="input" id="alert-contact" name="contact" type="text" required maxlength="190" placeholder="Email or WhatsApp number">
    <button class="btn btn--dark btn--sm" type="submit">Alert me when it lands</button>
    <p class="tiny muted alert-form__msg" data-alert-msg hidden role="status"></p>
  </form>
<?php endif; ?>
