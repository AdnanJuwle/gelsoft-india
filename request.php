<?php
/**
 * Request a model we do not stock, and the public board of open requests.
 *
 * A request is only useful if we can answer it, so the contact field is the
 * one thing that is really required — everything else narrows the quote.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/shop.php';
require __DIR__ . '/inc/community.php';
require __DIR__ . '/inc/mail.php';

$me = current_user();
$errors = [];
$sent = false;

$form = [
    'brand' => '', 'model' => '', 'link' => '', 'grade' => '', 'colour' => '',
    'qty' => '1', 'budget' => '5000-15000', 'when' => 'soon', 'gamedate' => '',
    'name' => $me['name'] ?? '', 'contact' => $me['email'] ?? '',
    'city' => '', 'state' => '', 'notes' => '', 'public' => '1',
];

const BUDGETS = [
    'under-5000'   => ['Under ₹5,000', 0, 500000],
    '5000-15000'   => ['₹5,000 – ₹15,000', 500000, 1500000],
    '15000-30000'  => ['₹15,000 – ₹30,000', 1500000, 3000000],
    '30000-up'     => ['₹30,000 and up', 3000000, null],
];

const WHENS = [
    'none' => ['No rush', 'Tell me when it lands.'],
    'soon' => ['Within a month', 'Happy to pay freight for speed.'],
    'date' => ['For a specific game', 'There is a date I need it by.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    // Backing somebody else's request is the other thing this page does.
    if (($_POST['action'] ?? '') === 'back') {
        $problem = request_back(
            (int) ($_POST['request_id'] ?? 0),
            (string) ($_POST['contact'] ?? ''),
            $me ? (int) $me['id'] : null
        );
        if ($problem === null) {
            redirect('/request?backed=1#board');
        }
        $errors[] = $problem;
    } else {
        /*
         * Trimmed, not cut to 500.
         *
         * One limit for every field was wrong for most of them: `model` is
         * VARCHAR(160) and `brand` is 80, so anything between their width and
         * 500 passed this clamp and was then refused by the database -- and
         * the page answered "We could not save that just now. Try again in a
         * minute", which was never going to come true.
         */
        foreach ($form as $k => $_) {
            $form[$k] = trim((string) ($_POST[$k] ?? ''));
        }

        $errors = array_merge($errors, length_errors($form, [
            'model'     => ['sourcing_requests.model',     'The model'],
            'brand'     => ['sourcing_requests.brand',     'The brand'],
            'contact'   => ['sourcing_requests.contact',   'Your contact'],
            'link'      => ['sourcing_requests.link',      'The link'],
            'wanted_by' => ['sourcing_requests.wanted_by', 'When you want it by'],
            'notes'     => ['sourcing_requests.notes',     'The note'],
        ]));

        $contact = normalise_contact($form['contact']);

        if ($form['model'] === '') {
            $errors[] = 'Tell us which model — that is the whole request.';
        }
        if ($contact === null) {
            $errors[] = 'Leave an email address or an Indian mobile number, or we cannot answer you.';
        }
        if ($form['link'] !== '' && safe_external_url($form['link']) === null) {
            $errors[] = 'That reference link needs to start with http:// or https://. '
                      . 'Leave it blank if you do not have one.';
        }
        if (empty($_POST['understood'])) {
            $errors[] = 'Confirm that a quote is not a commitment until a deposit is paid.';
        }
        if (!isset(BUDGETS[$form['budget']])) {
            $form['budget'] = '5000-15000';
        }

        if (!$errors && db_ready()) {
            [, $min, $max] = BUDGETS[$form['budget']];

            $details = [];
            if ($form['grade'] !== '')  { $details[] = 'Grade: ' . $form['grade']; }
            if ($form['colour'] !== '') { $details[] = 'Colour: ' . $form['colour']; }
            if ((int) $form['qty'] > 1) { $details[] = 'Quantity: ' . (int) $form['qty']; }
            if ($form['city'] !== '')   { $details[] = 'In ' . $form['city'] . ($form['state'] ? ', ' . $form['state'] : ''); }

            try {
                exec_sql(
                    'INSERT INTO sourcing_requests
                       (user_id, contact, name, model, brand, details, link,
                        budget_min_paise, budget_max_paise, wanted_by, notes, public)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $me ? (int) $me['id'] : null,
                        $contact,
                        $form['name'] !== '' ? $form['name'] : null,
                        $form['model'],
                        $form['brand'] !== '' ? $form['brand'] : null,
                        $details ? implode(' · ', $details) : null,
                        $form['link'] !== '' ? $form['link'] : null,
                        $min, $max,
                        $form['when'] === 'date' && $form['gamedate'] !== ''
                            ? 'By ' . $form['gamedate']
                            : (WHENS[$form['when']][0] ?? null),
                        $form['notes'] !== '' ? $form['notes'] : null,
                        $form['public'] !== '' ? 1 : 0,
                    ]
                );

                $sent = true;

                send_mail($contact, 'We have your request',
                    "Thanks — we have your request for {$form['model']}.\n\n"
                    . "We answer sourcing requests within two working days, with a real price and a\n"
                    . "real lead time, or a straight no if we cannot get it.\n\n"
                    . ($form['public'] !== '' ? "It is on the open board too, so other people can add themselves to it.\nA model with eight people behind it gets ordered.\n\n" : '')
                    . rtrim((string) cfg('site_url'), '/') . "/request#board\n\nGelsoft India\n",
                    'request_received');
            } catch (Throwable $e) {
                error_log('sourcing request failed: ' . $e->getMessage());
                $errors[] = 'We could not save that just now. Try again in a minute.';
            }
        }
    }
}

$board = request_board();

$page = [
    'title'   => 'Request a model',
    'desc'    => 'Not stocked in India? Tell us the model and we quote it with a real lead time, or tell you honestly that we cannot get it.',
    'nav'     => 'request',
];
require __DIR__ . '/inc/header.php';
?>
  <section class="wrap" id="top" style="padding-top:44px">
    <p class="eyebrow eyebrow--ember">Sourcing</p>
    <h1 class="h-page" style="margin-top:16px;max-width:22em">Can&rsquo;t find it in India? Tell us what to source.</h1>
    <p class="lead measure" style="margin-top:16px">Half the models people want here have never been stocked in the country. Put in a request and you get a real quote with a real lead time &mdash; or an honest no, which is worth more than six weeks of silence.</p>

    <ol class="grid grid--auto" style="margin-top:34px">
      <li class="card card--pad" style="border-left:3px solid var(--ember)">
        <p class="eyebrow eyebrow--ember" style="font-size:11.5px;margin-bottom:8px">Step 1 &middot; now</p>
        <h2 style="font-size:16px;font-weight:700;line-height:1.3">You tell us the model</h2>
        <p class="tiny muted" style="margin-top:7px">Brand, pattern, and a link if you have one.</p>
      </li>
      <li class="card card--pad">
        <p class="eyebrow" style="font-size:11.5px;margin-bottom:8px">Step 2</p>
        <h2 style="font-size:16px;font-weight:700;line-height:1.3">We quote it in 48 hours</h2>
        <p class="tiny muted" style="margin-top:7px">Landed price, lead time, and what spares exist for it.</p>
      </li>
      <li class="card card--pad">
        <p class="eyebrow" style="font-size:11.5px;margin-bottom:8px">Step 3</p>
        <h2 style="font-size:16px;font-weight:700;line-height:1.3">50% deposit to order</h2>
        <p class="tiny muted" style="margin-top:7px">Returned in full if the shipment never lands.</p>
      </li>
      <li class="card card--pad">
        <p class="eyebrow" style="font-size:11.5px;margin-bottom:8px">Step 4</p>
        <h2 style="font-size:16px;font-weight:700;line-height:1.3">Chrono, then courier</h2>
        <p class="tiny muted" style="margin-top:7px">It is tested here before it goes out to you.</p>
      </li>
    </ol>
  </section>

  <div class="wrap layout section">

    <?php if ($sent): ?>
      <div class="col">
        <div class="notice notice--olive" role="status">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12.4l2.6 2.6L16 9.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <div>
            <h2 style="font-size:19px;font-weight:700;margin-bottom:4px">We have it</h2>
            <p class="small">We answer within two working days with a real price and a real lead time, or a straight no if we cannot get it. Nothing is owed either way until you pay a deposit.</p>
            <div class="row row--tight" style="margin-top:14px">
              <a class="btn btn--sm" href="#board">See the open board</a>
              <a class="btn btn--sm" href="/blasters">Look at what is in stock</a>
            </div>
          </div>
        </div>
      </div>
    <?php else: ?>
    <form class="col" method="post" action="/request" style="display:flex;flex-direction:column;gap:20px">
      <?= csrf_field() ?>

      <?php foreach ($errors as $err): ?>
        <p class="notice notice--paper" role="alert" style="color:var(--bad)"><span class="small"><?= e($err) ?></span></p>
      <?php endforeach; ?>

      <fieldset class="fieldset--card">
        <legend>What do you want</legend>
        <div class="row row--tight" style="margin-top:18px">
          <div class="field" style="flex:1 1 200px;min-width:0;margin:0">
            <label class="field__label" for="brand">Brand</label>
            <select class="select" id="brand" name="brand">
              <option value="">Not sure</option>
              <?php foreach (['TG','Bohan','SBL','Jieying','LeHui','Handi','Other'] as $b): ?>
                <option value="<?= e($b) ?>"<?= $form['brand'] === $b ? ' selected' : '' ?>><?= e($b) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="flex:1 1 200px;min-width:0;margin:0">
            <label class="field__label" for="model">Model or pattern</label>
            <input class="input" id="model" name="model" maxlength="160" required value="<?= e($form['model']) ?>" placeholder="e.g. SR16 KS1, Radian Model 1 2.0">
          </div>
        </div>

        <div class="field">
          <label class="field__label" for="link">Reference link <span class="opt">&mdash; optional, but it halves the back-and-forth</span></label>
          <input class="input" id="link" name="link" type="url" maxlength="500" value="<?= e($form['link']) ?>" placeholder="A listing, a review video, a forum thread&hellip;">
        </div>

        <div class="row row--tight" style="margin-top:18px">
          <div class="field" style="flex:1 1 170px;min-width:0;margin:0">
            <label class="field__label" for="grade">Grade you expect</label>
            <select class="select" id="grade" name="grade">
              <option value="">Not sure &mdash; advise me</option>
              <?php foreach (['Toy grade','Mid hobby grade','Premium grade'] as $g): ?>
                <option value="<?= e($g) ?>"<?= $form['grade'] === $g ? ' selected' : '' ?>><?= e($g) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="flex:1 1 130px;min-width:0;margin:0">
            <label class="field__label" for="colour">Colourway</label>
            <input class="input" id="colour" name="colour" maxlength="60" value="<?= e($form['colour']) ?>" placeholder="Any">
          </div>
          <div class="field" style="flex:0 1 110px;min-width:0;margin:0">
            <label class="field__label" for="qty">Quantity</label>
            <input class="input" id="qty" name="qty" type="number" min="1" max="20" value="<?= e($form['qty']) ?>">
          </div>
        </div>
      </fieldset>

      <fieldset class="fieldset--card">
        <legend>Budget and timing</legend>

        <div class="field" style="margin-top:18px">
          <label class="field__label" for="budget">Budget band</label>
          <select class="select" id="budget" name="budget" style="max-width:320px">
            <?php foreach (BUDGETS as $key => [$label, , ]): ?>
              <option value="<?= e($key) ?>"<?= $form['budget'] === $key ? ' selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
          <p class="field__hint">We will tell you plainly if the model you named does not exist at this price.</p>
        </div>

        <div style="margin-top:20px">
          <p class="field__label">How soon</p>
          <div class="grid grid--auto-s">
            <?php foreach (WHENS as $key => [$title, $blurb]): ?>
              <label class="radio-card">
                <input type="radio" name="when" value="<?= e($key) ?>"<?= $form['when'] === $key ? ' checked' : '' ?>>
                <span style="display:flex;flex-direction:column;gap:5px"><strong><?= e($title) ?></strong><span><?= e($blurb) ?></span></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="field" style="max-width:280px">
            <label class="field__label" for="gamedate">Game date <span class="opt">&mdash; if you picked one</span></label>
            <input class="input" id="gamedate" name="gamedate" type="date" value="<?= e($form['gamedate']) ?>">
            <p class="field__hint" style="color:var(--warn)">If the date is inside four weeks we will say so straight away rather than take a deposit and hope.</p>
          </div>
        </div>
      </fieldset>

      <fieldset class="fieldset--card">
        <legend>Where to reach you</legend>

        <div class="row row--tight" style="margin-top:18px">
          <div class="field" style="flex:1 1 200px;min-width:0;margin:0">
            <label class="field__label" for="name">Name</label>
            <input class="input" id="name" name="name" maxlength="120" autocomplete="name" value="<?= e($form['name']) ?>">
          </div>
          <div class="field" style="flex:1 1 200px;min-width:0;margin:0">
            <label class="field__label" for="contact">Email or WhatsApp number <span class="req">*</span></label>
            <input class="input" id="contact" name="contact" maxlength="190" required value="<?= e($form['contact']) ?>" placeholder="you@example.com or 98765 43210">
          </div>
        </div>

        <div class="row row--tight" style="margin-top:18px">
          <div class="field" style="flex:1 1 200px;min-width:0;margin:0">
            <label class="field__label" for="city">City</label>
            <input class="input" id="city" name="city" maxlength="90" autocomplete="address-level2" value="<?= e($form['city']) ?>">
          </div>
          <div class="field" style="flex:1 1 200px;min-width:0;margin:0">
            <label class="field__label" for="state">State</label>
            <select class="select" id="state" name="state" autocomplete="address-level1">
              <option value="">Select your state</option>
              <option>Andhra Pradesh</option><option>Arunachal Pradesh</option><option>Assam</option>
              <option>Bihar</option><option>Chhattisgarh</option><option>Delhi NCT</option>
              <option>Goa</option><option>Gujarat</option><option>Haryana</option>
              <option>Himachal Pradesh</option><option>Jammu &amp; Kashmir</option><option>Jharkhand</option>
              <option>Karnataka</option><option>Kerala</option><option>Madhya Pradesh</option>
              <option>Maharashtra</option><option>Manipur</option><option>Meghalaya</option>
              <option>Mizoram</option><option>Nagaland</option><option>Odisha</option>
              <option>Puducherry</option><option>Punjab</option><option>Rajasthan</option>
              <option>Sikkim</option><option>Tamil Nadu</option><option>Telangana</option>
              <option>Tripura</option><option>Uttar Pradesh</option><option>Uttarakhand</option>
              <option>West Bengal</option><option>Other union territory</option>
            </select>
          </div>
        </div>

        <div class="field">
          <label class="field__label" for="notes">Anything else</label>
          <textarea class="textarea" id="notes" name="notes" rows="3" maxlength="500" placeholder="Will accept a different colour, want it chrono&rsquo;d to an indoor cap, already own the mags&hellip;"><?= e($form['notes']) ?></textarea>
        </div>

        <div style="display:flex;flex-direction:column;gap:13px;margin-top:18px">
          <label class="checkline checkline--top"><input type="checkbox" name="public" value="1"<?= $form['public'] !== '' ? ' checked' : '' ?>> Add this to the public board so other people can back it &mdash; a model with eight backers gets ordered, one with a single backer usually doesn&rsquo;t</label>
          <label class="checkline checkline--top"><input type="checkbox" name="understood" value="1" required data-gates="request-submit"> I understand a quote is not a commitment on either side until the deposit is paid</label>
        </div>
      </fieldset>

      <div class="row row--tight row--center">
        <button class="btn btn--primary" type="submit" id="request-submit" disabled>Send request</button>
        <a class="small" style="font-weight:600;margin-left:4px" href="#board">Or back a request someone already made &darr;</a>
      </div>
    </form>
    <?php endif; ?>

    <aside class="rail" style="max-width:380px" aria-label="Sourcing notes">
      <div class="card card--pad">
        <h2 class="h-kicker" style="margin-bottom:16px">What usually happens</h2>
        <dl style="display:flex;flex-direction:column;gap:14px;font-size:14px">
          <div class="row row--between" style="gap:12px;border-bottom:1px solid #EDEAE3;padding-bottom:13px">
            <dt class="muted">Quote back within</dt><dd style="margin:0;font-weight:600">48 hours</dd>
          </div>
          <div class="row row--between" style="gap:12px;border-bottom:1px solid #EDEAE3;padding-bottom:13px">
            <dt class="muted">Deposit to order</dt><dd style="margin:0;font-weight:600">50%</dd>
          </div>
          <div class="row row--between" style="gap:12px;border-bottom:1px solid #EDEAE3;padding-bottom:13px">
            <dt class="muted">Typical lead time</dt><dd style="margin:0;font-weight:600">3&ndash;6 weeks</dd>
          </div>
          <div class="row row--between" style="gap:12px">
            <dt class="muted">If it never arrives</dt><dd style="margin:0;font-weight:600">Deposit returned</dd>
          </div>
        </dl>
        <p class="tiny muted" style="margin-top:14px">Lead times are honest estimates, not promises. If a shipment stalls we tell you the week it stalls, not the week it was due.</p>
      </div>

      <div class="card card--pad card--olive" style="margin-top:20px">
        <h2 class="h-kicker" style="margin-bottom:14px;color:var(--olive-ink)">What we will not quote</h2>
        <ul class="list" style="color:var(--olive-dark)">
          <li><span class="x" aria-hidden="true">&times;</span> Anything built or modified to pass as a real firearm</li>
          <li><span class="x" aria-hidden="true">&times;</span> Models we cannot get a single spare part for &mdash; you would own a sealed box</li>
          <li><span class="x" aria-hidden="true">&times;</span> Anything we cannot legally import and clear</li>
        </ul>
      </div>

      <div class="card card--pad" style="margin-top:20px;background:var(--dark);border-color:var(--dark);color:var(--on-dark)">
        <h2 class="h-kicker" style="margin-bottom:12px;color:#fff">Try the marketplace first</h2>
        <p class="small on-dark">Plenty of models nobody imports anymore are already in the country, sitting in somebody&rsquo;s cupboard. It is worth a look before you wait six weeks.</p>
        <a class="btn btn--light btn--sm" href="/marketplace" style="margin-top:16px">Browse listings</a>
      </div>
    </aside>
  </div>

  <!-- board -->
  <section class="wrap section section--flush-top" id="board" style="padding-bottom:64px">
    <h2 class="h-sect">Open requests</h2>
    <p class="muted" style="margin-top:10px;max-width:54em">Back one and you get told when it is quoted. Enough backers on a model and we order a batch, which is the only way the price on a low-volume import ever comes down.</p>

    <?php if (isset($_GET['backed'])): ?>
      <p class="notice notice--olive" role="status" style="margin-top:20px">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12.4l2.6 2.6L16 9.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="small">You are behind that one. We will tell you the moment it is quoted.</span>
      </p>
    <?php endif; ?>

    <?php if ($board): ?>
      <div class="grid grid--auto" style="margin-top:24px">
        <?php foreach ($board as $r): ?>
          <div class="card card--pad">
            <div class="row row--between" style="gap:10px;align-items:flex-start">
              <div style="min-width:0">
                <?php if ($r['brand']): ?><p class="prod__brand"><?= e($r['brand']) ?></p><?php endif; ?>
                <h3 style="font-size:16px;font-weight:700;margin-top:4px"><?= e($r['model']) ?></h3>
              </div>
              <span class="chip chip--pill<?= $r['status'] === 'ordered' ? ' chip--ok' : '' ?>">
                <?= e(request_status_label((string) $r['status'])) ?>
              </span>
            </div>

            <?php if ($r['details']): ?>
              <p class="tiny muted" style="margin-top:8px"><?= e($r['details']) ?></p>
            <?php endif; ?>

            <p class="small" style="margin-top:12px;color:var(--ink-3)">
              <strong><?= (int) $r['backers'] + 1 ?></strong> <?= (int) $r['backers'] === 0 ? 'person wants' : 'people want' ?> this
              <?php if ($r['wanted_by']): ?><span class="muted">&middot; <?= e($r['wanted_by']) ?></span><?php endif; ?>
            </p>

            <form method="post" action="/request" class="inline-form" style="margin-top:14px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="back">
              <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
              <label class="sr-only" for="back-<?= (int) $r['id'] ?>">Your email or number</label>
              <input class="input" id="back-<?= (int) $r['id'] ?>" name="contact" required
                     style="flex:1 1 150px" value="<?= e($me['email'] ?? '') ?>"
                     placeholder="Email or number">
              <button class="btn btn--sm" type="submit">I want one too</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty" style="margin-top:24px">
        <h3>No open requests yet</h3>
        <p>Yours would be the first. Put it in and it sits here for other people to back &mdash; and a model several people want is the one that actually gets ordered.</p>
        <a class="btn btn--primary btn--sm" href="#top">Put in the first request</a>
      </div>
    <?php endif; ?>
  </section>
<?php require __DIR__ . '/inc/footer.php';
