<?php
$page = [
    'title'   => 'Replacement & refund policy',
    'desc'    => 'All sales are final. We replace items damaged in shipment on submission of an uncut unboxing video. No warranties or guarantees.',
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap" style="padding-top:44px">
    <nav class="breadcrumb" aria-label="Breadcrumb" style="margin-bottom:18px">
      <a href="/">Home</a><span>/</span><span class="here">Replacement &amp; refund policy</span>
    </nav>
    <h1 class="h-page">Replacement &amp; refund policy</h1>
    <p class="lead measure" style="margin-top:16px">Read this before you order, not after. Every item is inspected and test-fired before it leaves us, and in exchange all sales are final &mdash; the one thing we do replace is a parcel damaged in transit, and that needs an uncut unboxing video.</p>
  </div>

  <!-- three facts -->
  <section class="wrap" style="padding-top:32px">
    <div class="grid grid--auto-l">
      <div class="card card--pad-lg">
        <span class="empty__icon" style="width:38px;height:38px;background:var(--ember-tint);border:1px solid var(--ember-line);margin-bottom:16px" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="var(--bad)" stroke-width="2.2"><path d="M5 5l10 10M15 5L5 15" stroke-linecap="round"/></svg>
        </span>
        <h2 class="h-card" style="margin-bottom:10px">No returns or exchanges</h2>
        <p class="small" style="color:var(--ink-3)">Once an item is bought it is a final sale. We cannot take it back for another model, for a different size, or for store credit.</p>
      </div>

      <div class="card card--pad-lg">
        <span class="empty__icon" style="width:38px;height:38px;background:var(--ember-tint);border:1px solid var(--ember-line);margin-bottom:16px" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="var(--bad)" stroke-width="2.2"><path d="M5 5l10 10M15 5L5 15" stroke-linecap="round"/></svg>
        </span>
        <h2 class="h-card" style="margin-bottom:10px">No refunds</h2>
        <p class="small" style="color:var(--ink-3)">We do not refund a purchase. Ask us anything you are unsure about before you pay &mdash; by email or on Instagram &mdash; and we will answer it straight.</p>
      </div>

      <div class="card card--pad-lg card--olive">
        <span class="empty__icon" style="width:38px;height:38px;background:#fff;border:1px solid var(--olive-line);margin-bottom:16px" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2.2"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        <h2 class="h-card" style="margin-bottom:10px;color:var(--olive-ink)">We replace shipping damage</h2>
        <p class="small" style="color:var(--olive-dark)">If it arrives broken, we replace it &mdash; on an uncut unboxing video. That video is the whole process, so start filming before you open the parcel.</p>
      </div>
    </div>
  </section>

  <!-- the video -->
  <section class="wrap" style="padding-top:32px">
    <div class="band row row--top pad-xl" style="border-radius:var(--r-lg);gap:36px">
      <div class="col">
        <p class="eyebrow eyebrow--soft">The one rule that matters</p>
        <h2 class="h-sect" style="margin-top:12px">Film the unboxing. Uncut.</h2>
        <p class="on-dark" style="margin-top:14px;max-width:44em">A courier can drop a parcel and nobody tells us. The video is how we tell transit damage apart from everything else, and without one we cannot approve a replacement &mdash; not because we think you are lying, but because we have nothing to show the courier.</p>
      </div>

      <ol class="steps steps--dark" style="flex:1 1 320px;min-width:0">
        <li><span class="n">1</span> Start recording with the parcel sealed, label visible.</li>
        <li><span class="n">2</span> One continuous take &mdash; no cuts, no edits, no stopping.</li>
        <li><span class="n">3</span> Open it on camera and show the damage clearly.</li>
        <li><span class="n">4</span> Send it to us the same day you receive the order.</li>
      </ol>
    </div>
  </section>

  <!-- detail -->
  <section class="wrap layout" style="padding-top:32px;padding-bottom:0">
    <div class="col" style="display:flex;flex-direction:column;gap:20px">

      <div class="panel">
        <h2 class="h-sect" style="font-size:22px;margin-bottom:12px">No warranties or guarantees</h2>
        <p style="color:var(--ink-3);line-height:1.75">We do not offer a warranty or a guarantee on anything we sell. What we do instead: every item is inspected for function and quality before dispatch, every blaster is test-fired, and a parcel damaged in transit gets replaced on the video above.</p>
        <p style="color:var(--ink-3);line-height:1.75;margin-top:14px">After that, wear is wear. Nozzles, O-rings, springs and piston heads are consumables and we keep them on the shelf, and we service blasters on the bench for a flat fee &mdash; including ones we did not sell.</p>
        <a class="small" style="font-weight:600;display:inline-block;margin-top:14px" href="/parts">Spares and bench service &rarr;</a>
      </div>

      <div class="panel">
        <h2 class="h-sect" style="font-size:22px;margin-bottom:16px">Starting a replacement</h2>
        <ol class="steps">
          <li><span class="n">1</span><span><strong>Message us the same day.</strong> Email <strong><?= e(cfg('contact.email')) ?></strong>, or Instagram DM to <strong><?= e(cfg('contact.instagram')) ?></strong>. Damaged, defective or wrong item &mdash; tell us immediately on receiving it.</span></li>
          <li><span class="n">2</span><span><strong>Send the uncut video and photos.</strong> We look at it the same day wherever we can.</span></li>
          <li><span class="n">3</span><span><strong>We authorise it and send instructions.</strong> Nothing comes back to us without that authorisation &mdash; a parcel returned on your own initiative will not be accepted.</span></li>
          <li><span class="n">4</span><span><strong>We replace the item.</strong> Same model where we have it; if it is out of stock we tell you how long, in weeks, not &ldquo;soon&rdquo;.</span></li>
        </ol>
      </div>

      <div class="panel">
        <h2 class="h-sect" style="font-size:22px;margin-bottom:12px">Items we can never take back</h2>
        <ul class="list" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px 24px">
          <li><span class="x" aria-hidden="true">&times;</span> Used eye protection</li>
          <li><span class="x" aria-hidden="true">&times;</span> Gel that has been soaked</li>
          <li><span class="x" aria-hidden="true">&times;</span> Custom or personalised builds</li>
          <li><span class="x" aria-hidden="true">&times;</span> Batteries once charged</li>
          <li><span class="x" aria-hidden="true">&times;</span> Sale and clearance items</li>
          <li><span class="x" aria-hidden="true">&times;</span> Gift cards</li>
        </ul>
      </div>
    </div>

    <aside class="rail" style="max-width:360px" aria-label="Contact">
      <div class="card card--pad">
        <h2 class="h-kicker" style="margin-bottom:16px">Reach us</h2>
        <dl style="display:flex;flex-direction:column;gap:16px">
          <div>
            <dt class="tiny muted" style="margin-bottom:5px">Instagram &mdash; fastest</dt>
            <dd style="margin:0;font-weight:600"><?= e(cfg('contact.instagram')) ?></dd>
          </div>
          <div style="border-top:1px solid #EDEAE3;padding-top:15px">
            <dt class="tiny muted" style="margin-bottom:5px">Email</dt>
            <dd style="margin:0;font-weight:600;word-break:break-all"><a href="mailto:<?= e(cfg('contact.email')) ?>"><?= e(cfg('contact.email')) ?></a></dd>
          </div>
          <div style="border-top:1px solid #EDEAE3;padding-top:15px">
            <dt class="tiny muted" style="margin-bottom:5px">Phone</dt>
            <dd style="margin:0;font-weight:600">On the contact page</dd>
          </div>
        </dl>
      </div>

      <div class="card card--pad card--surface" style="margin-top:20px">
        <h2 class="h-kicker" style="margin-bottom:12px">Before you order</h2>
        <p class="small" style="color:var(--ink-3)">Final sale cuts both ways: it keeps prices down, and it means a question asked beforehand is worth more than a complaint afterwards. Ask us which grade suits how you actually play.</p>
        <a class="small" style="font-weight:600;display:inline-block;margin-top:14px" href="/faq">Read the FAQ &rarr;</a>
      </div>
    </aside>
  </section>

  <div style="height:64px"></div>
<?php require __DIR__ . '/inc/footer.php';
