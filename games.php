<?php
/**
 * Games people are hosting, and the form a host uses to add one.
 *
 * Submissions are reviewed before they appear — a public board anyone can
 * write to is a board full of spam inside a week.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/community.php';
require __DIR__ . '/inc/mail.php';
require __DIR__ . '/inc/uploads.php';

$me = current_user();
$errors = [];
$sent = false;
$listed = false;

$region = (string) ($_GET['region'] ?? '');
$region = isset(REGIONS[$region]) ? $region : '';

$form = [
    'title' => '', 'city' => '', 'state' => '', 'region' => '', 'starts_at' => '',
    'fps_cap' => '', 'fee' => '', 'venue' => '', 'details' => '', 'signup_link' => '',
    'host_name' => $me['name'] ?? '', 'contact' => $me['email'] ?? '',
    'rental' => '1', 'beginners' => '1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if (($_POST['action'] ?? '') === 'looking') {
        // "Nothing near me" — the same list as a restock alert, with a city
        // instead of a product, so it reuses the table rather than inventing one.
        $contact = normalise_contact((string) ($_POST['contact'] ?? ''));
        $where   = mb_substr(trim((string) ($_POST['where'] ?? '')), 0, 90);

        if ($contact === null) {
            $errors[] = 'Leave an email address or a phone number so we can put you in touch.';
        } elseif ($where === '') {
            $errors[] = 'Which city?';
        } elseif (db_ready()) {
            try {
                exec_sql('INSERT INTO restock_alerts (category, contact) VALUES (?, ?)',
                    ['game:' . $where, $contact]);
                $listed = true;
            } catch (Throwable $e) {
                error_log('game interest failed: ' . $e->getMessage());
                $errors[] = 'We could not save that just now.';
            }
        }
    } elseif (!$me) {
        // Hosting needs an account. A board anyone can write to anonymously is
        // a board full of spam, and a player who turns up to a game that was
        // never real has nobody to ask -- now there is always a person behind
        // it, reachable through the site.
        $errors[] = 'Sign in to put a game on the board. It takes a minute and it means '
                  . 'players can message you about it.';
    } else {
        // Trimmed, not cut to 500: `title` and `venue` are VARCHAR(160) and
        // `duration` is 60, so the single limit was wrong for all but two of
        // these and the database was left to refuse the row.
        foreach ($form as $k => $_) {
            $form[$k] = trim((string) ($_POST[$k] ?? ''));
        }

        $errors = array_merge($errors, length_errors($form, [
            'title'       => ['games.title',       'The name of the game'],
            'venue'       => ['games.venue',       'The venue'],
            'city'        => ['games.city',        'The city'],
            'duration'    => ['games.duration',    'How long it runs'],
            'signup_link' => ['games.signup_link', 'The sign-up link'],
        ]));

        // Identity comes from the account, not from what was typed into the
        // form -- otherwise the name on the board and the person we can
        // actually reach are two different things.
        $form['host_name'] = (string) $me['name'];
        $contact = normalise_contact((string) $me['email']);

        if ($form['title'] === '') {
            $errors[] = 'Give the game a name.';
        }
        if ($form['city'] === '') {
            $errors[] = 'Which city is it in?';
        }
        if ($form['starts_at'] === '' || strtotime($form['starts_at']) === false) {
            $errors[] = 'Put a date on it.';
        } elseif (strtotime($form['starts_at']) < strtotime('today')) {
            $errors[] = 'That date has passed.';
        }
        if ($contact === null) {
            $errors[] = 'Your account has no usable email address. Fix that in your account first.';
        }
        if (empty($_POST['permission'])) {
            $errors[] = 'Confirm you have permission for the ground. We will not list a game without it.';
        }
        if ($form['signup_link'] !== '' && safe_external_url($form['signup_link']) === null) {
            // Not "is it a URL" -- FILTER_VALIDATE_URL says yes to
            // javascript: and file:. See safe_external_url().
            $errors[] = 'That sign-up link needs to start with http:// or https://.';
        }

        // One optional banner. Cropped to 3:1 on the way in, so a portrait
        // photo does not cost four times the bytes to show the same strip.
        $banner = null;
        if (!$errors && ($_FILES['banner']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = upload_image($_FILES['banner'], 'game-' . ($form['city'] ?: 'india'), 'banner');
            if (isset($up['error'])) {
                $errors[] = 'Banner: ' . $up['error'];
            } else {
                $banner = $up['path'];
            }
        }

        if (!$errors && db_ready()) {
            try {
                exec_sql(
                    'INSERT INTO games
                       (host_id, host_name, contact, title, venue, city, state, region,
                        starts_at, fps_cap, fee_paise, rental, beginners, details, signup_link, banner)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        (int) $me['id'],
                        $form['host_name'],
                        $contact,
                        $form['title'],
                        $form['venue'] !== '' ? $form['venue'] : null,
                        $form['city'],
                        $form['state'] !== '' ? $form['state'] : null,
                        isset(REGIONS[$form['region']]) ? $form['region'] : null,
                        date('Y-m-d H:i:s', (int) strtotime($form['starts_at'])),
                        $form['fps_cap'] !== '' ? max(0, min(1000, (int) $form['fps_cap'])) : null,
                        $form['fee'] !== '' ? max(0, (int) $form['fee']) * 100 : null,
                        isset($_POST['rental']) ? 1 : 0,
                        isset($_POST['beginners']) ? 1 : 0,
                        $form['details'] !== '' ? $form['details'] : null,
                        $form['signup_link'] !== '' ? $form['signup_link'] : null,
                        $banner,
                    ]
                );

                $sent = true;

                send_mail($contact, 'Your game is in the queue',
                    "Thanks — we have \"{$form['title']}\" in {$form['city']}.\n\n"
                    . "We look at every submission before it goes on the board, usually within a day.\n"
                    . "Nothing to pay, and we do not take a cut of your entry fee.\n\n"
                    . "The four things players ask us about first are the FPS cap, the engagement\n"
                    . "distance, whether eye protection is provided, and whether you have rentals for\n"
                    . "someone turning up with nothing. If any of those are missing we will ask.\n\n"
                    . rtrim((string) cfg('site_url'), '/') . "/games\n\nGelsoft India\n",
                    'game_submitted');
            } catch (Throwable $e) {
                error_log('game submission failed: ' . $e->getMessage());
                // The banner is already on disk at this point; without this it
                // would sit there forever with nothing pointing at it.
                if ($banner !== null) {
                    upload_delete($banner);
                }
                $errors[] = 'We could not save that just now.';
            }
        }
    }
}

$games = games_upcoming($region !== '' ? $region : null);
$counts = games_by_region();
$total = db_ready()
    ? (int) (q1("SELECT COUNT(*) n FROM games WHERE status = 'live' AND starts_at >= (NOW() - INTERVAL 6 HOUR)")['n'] ?? 0)
    : 0;

$page = [
    'title'   => 'Games in India',
    'desc'    => 'Gelsoft games hosted across India, with the FPS cap and the fee up front. Free for hosts to list.',
    'nav'     => 'games',
];
require __DIR__ . '/inc/header.php';
?>
  <section class="wrap" style="padding-top:44px">
    <p class="eyebrow eyebrow--ember">Somewhere to actually use it</p>
    <h1 class="h-page" style="margin-top:16px;max-width:22em">Games being hosted in India</h1>
    <p class="lead measure" style="margin-top:16px">A blaster in a cupboard is an expensive paperweight. Hosts post their games here with the caps and the fee up front, and we list them whether or not anyone bought their kit from us.</p>

    <div class="row row--tight" style="margin-top:26px">
      <a class="btn btn--primary" href="#host">List your game</a>
      <a class="btn" href="/safety">Read the standards first</a>
    </div>
  </section>

  <!-- board -->
  <section class="wrap section">
    <h2 class="h-kicker" style="margin-bottom:14px">Where</h2>
    <div class="scroller" role="group" aria-label="Filter by region" style="margin-bottom:26px">
      <a class="region-pill<?= $region === '' ? ' is-on' : '' ?>" href="/games">
        Everywhere <span class="count"><?= $total ?></span>
      </a>
      <?php foreach (REGIONS as $key => $label): ?>
        <a class="region-pill<?= $region === $key ? ' is-on' : '' ?>" href="/games?region=<?= e($key) ?>">
          <?= e($label) ?> <span class="count"><?= (int) ($counts[$key] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <p class="small muted" style="margin-bottom:18px">
      <?= $games ? count($games) . ' game' . (count($games) === 1 ? '' : 's') . ' coming up' : 'No games listed yet' ?>
      <?= $region !== '' ? ' in the ' . e(strtolower(REGIONS[$region])) : '' ?>
    </p>

    <?php if ($games): ?>
      <div class="grid grid--auto-l">
        <?php foreach ($games as $g): $when = strtotime((string) $g['starts_at']); ?>
          <div class="card<?= $g['banner'] ? '' : ' card--pad' ?>">
            <?php if ($g['banner']): ?>
              <img class="game-banner" src="<?= e(asset((string) $g['banner'])) ?>" alt=""
                   width="1200" height="400" loading="lazy" decoding="async">
            <?php endif; ?>
            <div class="row row--tight"<?= $g['banner'] ? ' style="gap:14px;flex-wrap:nowrap;align-items:flex-start;padding:18px"' : ' style="gap:14px;flex-wrap:nowrap;align-items:flex-start"' ?>>
              <span class="date" style="flex:0 0 auto">
                <b><?= e(date('j', $when)) ?></b>
                <span><?= e(date('M', $when)) ?></span>
              </span>
              <div style="min-width:0">
                <h3 style="font-size:16.5px;font-weight:700;line-height:1.3"><?= e($g['title']) ?></h3>
                <p class="tiny muted" style="margin-top:5px">
                  <?= e($g['city']) ?><?= $g['venue'] ? ' &middot; ' . e($g['venue']) : '' ?>
                  &middot; <?= e(date('g:ia', $when)) ?>
                </p>
              </div>
            </div>

            <div class="chips" style="margin-top:14px">
              <span class="chip<?= $g['fps_cap'] && (int) $g['fps_cap'] <= 230 ? ' chip--ok' : '' ?>">
                <?= e(fps_cap_label($g['fps_cap'] !== null ? (int) $g['fps_cap'] : null)) ?>
              </span>
              <?php if ($g['fee_paise'] !== null): ?>
                <span class="chip"><?= (int) $g['fee_paise'] === 0 ? 'Free entry' : money((int) $g['fee_paise']) ?></span>
              <?php endif; ?>
              <?php if ($g['rental']): ?><span class="chip">Rentals</span><?php endif; ?>
              <?php if ($g['beginners']): ?><span class="chip">Beginners welcome</span><?php endif; ?>
            </div>

            <?php if ($g['details']): ?>
              <p class="small muted" style="margin-top:12px;line-height:1.6"><?= nl2br(e(mb_substr((string) $g['details'], 0, 220))) ?></p>
            <?php endif; ?>

            <p class="tiny muted" style="margin-top:12px">Hosted by <?= e($g['host_name']) ?></p>

            <?php /* Re-checked here, not trusted from the row. */ ?>
            <?php if ($signup = safe_external_url($g['signup_link'])): ?>
              <a class="btn btn--sm btn--block" style="margin-top:14px" rel="noopener noreferrer" target="_blank"
                 href="<?= e($signup) ?>">Sign up &rarr;</a>
            <?php endif; ?>

            <?php if ($g['host_id'] && (!$me || (int) $g['host_id'] !== (int) $me['id'])): ?>
              <form method="post" action="/game/message" style="margin-top:8px">
                <?= csrf_field() ?>
                <input type="hidden" name="game_id" value="<?= (int) $g['id'] ?>">
                <button class="btn btn--sm btn--block" type="submit">Message the host</button>
              </form>
              <p class="tiny muted" style="margin-top:6px">Ask about the cap, the ground or the parking. Neither of you hands over a number.</p>
            <?php elseif ($g['host_id'] && $me && (int) $g['host_id'] === (int) $me['id']): ?>
              <p class="tiny muted" style="margin-top:12px">This is yours. Messages about it are in <a href="/messages">your conversations</a>.</p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty">
        <span class="empty__icon" aria-hidden="true">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><rect x="4" y="5.5" width="16" height="14.5" rx="2.5"/><path d="M8 3v4M16 3v4M4 11h16" stroke-linecap="round"/></svg>
        </span>
        <h3>No games on the board<?= $region !== '' ? ' in the ' . e(strtolower(REGIONS[$region])) : ' yet' ?></h3>
        <p>Hosts post here with the cap and the fee up front. If you run games, yours can be the first &mdash; it is free, and we send players your way rather than taking a cut.</p>
        <div class="row row--tight" style="justify-content:center">
          <a class="btn btn--primary btn--sm" href="#host">List your game</a>
          <a class="btn btn--sm" href="#looking">Looking to play</a>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <!-- host -->
  <section class="wrap section section--flush-top" id="host">
    <div class="band row row--top row--between pad-xl" style="border-radius:var(--r-lg);gap:36px">
      <div class="col--half">
        <p class="eyebrow eyebrow--soft">For hosts</p>
        <h2 class="h-sect" style="margin-top:12px">Running a game? Put it on the board.</h2>
        <p class="on-dark" style="margin-top:14px;max-width:42em">Free, and we do not take a cut of your entry fee. We ask for four things because players ask us for them first: the cap, the engagement distance, whether eye protection is provided, and whether rentals exist for someone turning up with nothing.</p>
        <ul class="list" style="margin-top:22px;color:var(--on-dark-2)">
          <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive-soft)" stroke-width="2.2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Your published caps, chrono&rsquo;d at the gate</li>
          <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive-soft)" stroke-width="2.2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> A safety brief before the first round, every time</li>
          <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive-soft)" stroke-width="2.2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> One marshal whose only job is to stop the game</li>
          <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive-soft)" stroke-width="2.2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Permission for the ground, in writing if it is not yours</li>
        </ul>
      </div>

      <?php if (!$me): ?>
      <div class="card card--dark card--pad" style="flex:1 1 320px;min-width:0;max-width:400px">
        <h3 style="font-size:16px;font-weight:700;color:#fff;margin-bottom:14px">Submit a game</h3>
        <p class="small on-dark" style="line-height:1.65">
          Hosting needs an account. That is what lets a player message you about the cap or the
          parking without either of you handing out a phone number &mdash; and it means a game on
          the board always has a real person behind it.
        </p>
        <?php if ($errors): ?>
          <p class="tiny" role="alert" style="color:var(--bad);margin-top:12px"><?= e($errors[0]) ?></p>
        <?php endif; ?>
        <a class="btn btn--primary btn--sm btn--block" href="/login?next=%2Fgames%23host" style="margin-top:16px">Sign in</a>
        <a class="btn btn--ghost-dark btn--sm btn--block" href="/register?next=%2Fgames%23host" style="margin-top:8px">Create an account</a>
      </div>
      <?php else: ?>
      <form class="card card--dark card--pad" method="post" action="/games#host"
            enctype="multipart/form-data"
            style="flex:1 1 320px;min-width:0;max-width:400px">
        <?= csrf_field() ?>
        <h3 style="font-size:16px;font-weight:700;color:#fff;margin-bottom:14px">Submit a game</h3>

        <?php if ($sent): ?>
          <p class="small on-dark" role="status" style="line-height:1.65">
            <strong style="color:#fff">In the queue.</strong> We look at every submission before it goes on
            the board, usually within a day. Nothing to pay, and we do not take a cut of your entry fee.
          </p>
        <?php else: ?>

        <?php foreach ($errors as $err): ?>
          <p class="tiny" role="alert" style="color:var(--ember-soft);margin-bottom:10px"><?= e($err) ?></p>
        <?php endforeach; ?>

        <div class="field" style="margin:0">
          <label class="field__label" style="color:var(--on-dark-2)" for="title">Game name <span class="req">*</span></label>
          <input class="input" id="title" name="title" maxlength="160" required value="<?= e($form['title']) ?>" style="background:var(--dark);border-color:var(--dark-line-2);color:var(--on-dark)">
        </div>

        <div class="row row--tight" style="margin-top:14px">
          <div class="field" style="flex:1 1 0;min-width:0;margin:0">
            <label class="field__label" style="color:var(--on-dark-2)" for="city">City <span class="req">*</span></label>
            <input class="input" id="city" name="city" maxlength="90" required value="<?= e($form['city']) ?>" style="background:var(--dark);border-color:var(--dark-line-2);color:var(--on-dark)">
          </div>
          <div class="field" style="flex:1 1 0;min-width:0;margin:0">
            <label class="field__label" style="color:var(--on-dark-2)" for="starts_at">Date <span class="req">*</span></label>
            <input class="input" id="starts_at" name="starts_at" type="date" required value="<?= e($form['starts_at']) ?>" style="background:var(--dark);border-color:var(--dark-line-2);color:var(--on-dark)">
          </div>
        </div>

        <div class="row row--tight" style="margin-top:14px">
          <div class="field" style="flex:1 1 0;min-width:0;margin:0">
            <label class="field__label" style="color:var(--on-dark-2)" for="region">Region</label>
            <select class="select" id="region" name="region" style="background:var(--dark);border-color:var(--dark-line-2);color:var(--on-dark)">
              <option value="">Pick one</option>
              <?php foreach (REGIONS as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= $form['region'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="flex:0 1 110px;min-width:0;margin:0">
            <label class="field__label" style="color:var(--on-dark-2)" for="fps_cap">FPS cap</label>
            <input class="input" id="fps_cap" name="fps_cap" type="number" min="0" max="1000"
                   value="<?= e($form['fps_cap']) ?>" placeholder="230" style="background:var(--dark);border-color:var(--dark-line-2);color:var(--on-dark)">
          </div>
        </div>

        <div class="row row--tight" style="margin-top:14px">
          <div class="field" style="flex:1 1 0;min-width:0;margin:0">
            <label class="field__label" style="color:var(--on-dark-2)" for="venue">Venue</label>
            <input class="input" id="venue" name="venue" maxlength="160" value="<?= e($form['venue']) ?>" style="background:var(--dark);border-color:var(--dark-line-2);color:var(--on-dark)">
          </div>
          <div class="field" style="flex:0 1 110px;min-width:0;margin:0">
            <label class="field__label" style="color:var(--on-dark-2)" for="fee">Entry &#8377;</label>
            <input class="input" id="fee" name="fee" type="number" min="0" max="20000"
                   value="<?= e($form['fee']) ?>" placeholder="500" style="background:var(--dark);border-color:var(--dark-line-2);color:var(--on-dark)">
          </div>
        </div>

        <div class="field" style="margin-top:14px">
          <label class="field__label" style="color:var(--on-dark-2)" for="banner">Banner <span class="tiny" style="color:var(--on-dark-3)">optional</span></label>
          <input class="input" id="banner" name="banner" type="file" accept="image/jpeg,image/png,image/webp"
                 style="background:var(--dark);border-color:var(--dark-line-2);color:var(--on-dark);padding:10px 12px;min-height:auto">
          <p class="tiny" style="color:var(--on-dark-3);margin-top:6px">One wide photo of the ground. Cropped to a strip, so put the interesting part in the middle.</p>
        </div>

        <p class="tiny" style="color:var(--on-dark-3);margin-top:14px;line-height:1.6">
          Posting as <strong style="color:var(--on-dark)"><?= e((string) $me['name']) ?></strong>
          &middot; <?= e((string) $me['email']) ?>. Your name goes on the board; your email does not,
          and players reach you through the site.
        </p>

        <div class="field" style="margin-top:14px">
          <label class="field__label" style="color:var(--on-dark-2)" for="signup_link">Sign-up link</label>
          <input class="input" id="signup_link" name="signup_link" type="url" maxlength="500"
                 value="<?= e($form['signup_link']) ?>" placeholder="https://" style="background:var(--dark);border-color:var(--dark-line-2);color:var(--on-dark)">
        </div>

        <div class="field" style="margin-top:14px">
          <label class="field__label" style="color:var(--on-dark-2)" for="details">Anything players should know</label>
          <textarea class="textarea" id="details" name="details" rows="3" style="background:var(--dark);border-color:var(--dark-line-2);color:var(--on-dark)"
                    placeholder="Engagement distance, whether eye protection is provided, parking&hellip;"><?= e($form['details']) ?></textarea>
        </div>

        <label class="checkline checkline--top" style="color:var(--on-dark-2);margin-top:14px"><input type="checkbox" name="rental" value="1"<?= $form['rental'] !== '' ? ' checked' : '' ?>> Rentals available for first-timers</label>
        <label class="checkline checkline--top" style="color:var(--on-dark-2)"><input type="checkbox" name="beginners" value="1"<?= $form['beginners'] !== '' ? ' checked' : '' ?>> Beginners welcome</label>
        <label class="checkline checkline--top" style="color:var(--on-dark-2)"><input type="checkbox" name="permission" value="1"> I have permission for the ground <span class="req">*</span></label>

        <button class="btn btn--primary btn--sm btn--block" type="submit" style="margin-top:14px">Submit for review</button>
        <?php endif; ?>
      </form>
      <?php endif; ?>
    </div>
  </section>

  <!-- looking to play -->
  <section class="wrap section section--flush-top" id="looking" style="padding-bottom:64px">
    <div class="card card--olive card--pad-lg row row--between row--center">
      <div class="col--half">
        <h3 class="h-card" style="margin-bottom:6px">Nothing near you yet?</h3>
        <p class="small" style="color:var(--olive-dark)">Four people and a patch of private ground is a game. Tell us where you are and we will put you in touch with everyone else who has asked from the same city.</p>
      </div>
      <?php if ($listed): ?>
        <p class="small" role="status" style="color:var(--olive-dark);font-weight:500;flex:1 1 260px">
          On the list. We will put you in touch with everyone else who has asked from there.
        </p>
      <?php else: ?>
        <form class="inline-form" method="post" action="/games#looking">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="looking">
          <label class="sr-only" for="where">Your city</label>
          <input class="input" id="where" name="where" maxlength="90" required placeholder="Your city">
          <label class="sr-only" for="lcontact">Email or number</label>
          <input class="input" id="lcontact" name="contact" maxlength="190" required
                 value="<?= e($me['email'] ?? '') ?>" placeholder="Email or number">
          <button class="btn btn--olive btn--sm" type="submit">Put me on the list</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
<?php require __DIR__ . '/inc/footer.php';
