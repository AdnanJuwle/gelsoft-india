<?php
require __DIR__ . '/inc/bootstrap.php';

$entries = db_ready()
    ? q('SELECT id, question, answer FROM faq_entries WHERE published = 1 ORDER BY sort_order, id')
    : [];

$page = [
    'title'   => 'FAQ',
    'desc'    => 'Legality, what it feels like to get hit, what you need besides the blaster, delivery, grades and how the marketplace holds payments.',
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap" style="padding-top:44px">
    <h1 class="h-page">Frequently asked questions</h1>
    <p style="font-size:13px;letter-spacing:.14em;text-transform:uppercase;font-weight:700;color:var(--ember-dark);margin-top:16px">This is not legal advice</p>
  </div>

  <div class="wrap layout section" style="padding-top:28px">
    <div class="col">
      <div class="acc" data-accordion="single">
        <?php foreach ($entries as $i => $entry): $id = 'a' . (int) $entry['id']; ?>
        <div class="acc__item">
          <h2><button class="acc__q" type="button" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="<?= e($id) ?>"><?= e($entry['question']) ?>
            <span class="acc__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 7.5l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </button></h2>
          <div class="acc__a" id="<?= e($id) ?>"<?= $i === 0 ? '' : ' hidden' ?>>
            <?php foreach (preg_split('/\n\s*\n/', (string) $entry['answer']) as $para): ?>
              <p><?= nl2br(e(trim($para))) ?></p>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if (!$entries): ?>
          <div class="empty empty--sm">
            <h3>Nothing here yet</h3>
            <p>The FAQ is written from the admin panel.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <aside class="rail" style="max-width:340px" aria-label="More">
      <div class="card card--pad card--olive">
        <h2 class="h-kicker" style="margin-bottom:12px;color:var(--olive-ink)">Field rules</h2>
        <p class="small" style="color:var(--olive-dark)">The FPS caps, engagement distances and eye-protection rules we expect &mdash; and what most hosts here run.</p>
        <a class="btn btn--olive btn--sm" href="/safety" style="margin-top:16px">Safety standards</a>
      </div>

      <div class="card card--pad" style="margin-top:20px">
        <h2 class="h-kicker" style="margin-bottom:12px">All sales are final</h2>
        <p class="small muted">No returns, no refunds, no warranties. Shipping damage is replaced on an uncut unboxing video &mdash; the full policy says exactly what that means.</p>
        <a class="small" style="font-weight:600;display:inline-block;margin-top:14px" href="/policy">Replacement &amp; refund policy &rarr;</a>
      </div>

      <div class="card card--pad" style="margin-top:20px;background:var(--dark);border-color:var(--dark);color:var(--on-dark)">
        <h2 class="h-kicker" style="margin-bottom:12px;color:#fff">Still stuck?</h2>
        <p class="small on-dark">Message us with the model you are looking at. Two lines from someone who owns one beats twenty minutes of searching.</p>
        <div class="row row--tight" style="margin-top:16px">
          <a class="btn btn--light btn--sm" href="mailto:<?= e(cfg('contact.email')) ?>?subject=Question%20about%20a%20blaster"><?= e(cfg('contact.email')) ?></a>
          <?php if (cfg('contact.instagram')): ?>
            <a class="btn btn--ghost-dark btn--sm" href="https://instagram.com/<?= e(cfg('contact.instagram')) ?>" rel="noopener noreferrer" target="_blank">Instagram DM</a>
          <?php endif; ?>
        </div>
      </div>
    </aside>
  </div>
<?php require __DIR__ . '/inc/footer.php';
