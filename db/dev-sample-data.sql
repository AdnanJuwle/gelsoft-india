-- =====================================================================
--  LOCAL DEVELOPMENT FIXTURE — DO NOT RUN THIS ON THE LIVE DATABASE.
--
--  These rows are made up: the model names are real, the prices, chrono
--  figures and stock counts are not. They exist so the shop can be built
--  and tested against a shelf that is not empty.
--
--  The live shop is filled from the admin panel, one real item at a time.
--
--  Load:    mysql -u gelsoft -p gelsoft < db/dev-sample-data.sql
--  Undo:    DELETE FROM products WHERE slug LIKE 'dev-%';
-- =====================================================================

DELETE FROM products WHERE slug LIKE 'dev-%';

INSERT INTO products
  (slug, category, brand, name, summary, description, grade, type, gears, gearbox,
   fps_min, fps_max, battery, magazine, needs_to_run, price_paise, was_paise,
   stock_qty, stock_label, status, featured, sort_order)
VALUES
  ('dev-tg-gen8-m4', 'blaster', 'TG', 'TG Gen8 — M4 pattern',
   'The one most people start a hobby on. Metal gears in a nylon shell.',
   'A Gen 8 gearbox with metal gears, in an M4 body that takes the usual rails and stocks. Spares are on our shelf, which is most of why we stock it.',
   'mid', 'rifle', 'metal', 'Gen 8', 230, 250, '7.4V 1500mAh included', 'Hi-cap, 400 rounds',
   'Battery, charger and gel — all three in the box', 1249000, NULL, 6, 'in', 'published', 1, 10),

  ('dev-bohan-sr16-ks1', 'blaster', 'Bohan', 'Bohan SR16 KS1',
   'Metal gearbox, metal gears, and a chrono that does not wander.',
   'Premium grade. Full metal gearbox shell, adjustable hop-up, and a consistent figure across a magazine — which is what the price is actually for.',
   'premium', 'rifle', 'metal', 'V2 metal', 280, 300, '11.1V LiPo, not included', 'Mid-cap, 120 rounds',
   'Battery, charger and gel bought separately', 2899000, 3100000, 2, 'low', 'published', 1, 20),

  ('dev-sbl-radian-1-2', 'blaster', 'SBL', 'SBL Radian Model 1 2.0',
   'The 2.0 revision, with the gearbox people stopped having to replace.',
   'Premium grade, metal gears, metal gearbox. The 2.0 fixed the shimming the first revision shipped with.',
   'premium', 'rifle', 'metal', 'V2 metal', 270, 290, '11.1V LiPo, not included', 'Mid-cap, 150 rounds',
   'Battery, charger and gel bought separately', 3250000, NULL, 0, 'pre', 'published', 0, 30),

  ('dev-jieying-bolt', 'blaster', 'Jieying', 'Jieying bolt-action sniper',
   'Manual bolt. No motor, no gears, nothing to burn out.',
   'The exception to everything being electric. You work the bolt for each shot, which is slower and far quieter, and there is no gearbox to service.',
   'mid', 'bolt', 'manual', NULL, 200, 230, 'None — manual', 'Straight mag, 25 rounds',
   'Gel only', 899000, NULL, 4, 'in', 'published', 0, 40),

  ('dev-handi-starter', 'blaster', 'Handi', 'Handi starter pistol',
   'Cheap, light, and genuinely fine for a garden game.',
   'Toy grade: nylon gears, sealed internals, not meant to be opened. It is the one to hand somebody who has never held one.',
   'toy', 'pistol', 'nylon', NULL, 150, 170, 'Built-in, USB charged', 'Stick mag, 60 rounds',
   'Gel only — it charges off USB', 219000, NULL, 18, 'in', 'published', 0, 50),

  ('dev-lehui-smg', 'blaster', 'LeHui', 'LeHui MP5 pattern SMG',
   'Short, light, and the one people reach for indoors.',
   'Mid hobby grade with metal gears. Compact enough for tight indoor games where a rifle is a liability.',
   'mid', 'smg', 'metal', 'Gen 9', 210, 230, '7.4V 1200mAh included', 'Hi-cap, 300 rounds',
   'Battery, charger and gel — all in the box', 1099000, NULL, 0, 'soon', 'published', 0, 60);

-- Gear ----------------------------------------------------------------
INSERT INTO products
  (slug, category, brand, name, summary, description, gear_group, sizes,
   price_paise, stock_qty, stock_label, status, featured, sort_order)
VALUES
  ('dev-goggles-fullseal', 'gear', NULL, 'Full-seal impact goggles',
   'Sealed all the way round. The one thing not to save money on.',
   'Impact-rated lens, anti-fog treated, fits over prescription glasses. Mesh is for the lower face, never the eyes.',
   'eye', 'One size', 149000, 24, 'in', 'published', 1, 10),

  ('dev-mesh-lower', 'gear', NULL, 'Steel mesh lower-face mask',
   'Teeth and lips, covered. Breathes in Indian heat.',
   'Worn under goggles, not instead of them.', 'eye', 'One size', 69000, 15, 'in', 'published', 0, 20),

  ('dev-chest-rig', 'gear', NULL, 'Breathable chest rig, four mags',
   'A rig, not a plate carrier. Half the trapped heat.',
   'Four mag pouches and a small admin pocket. A full plate carrier over a long-sleeve shirt is a two-hour game at best in April.',
   'body', 'S, M, L, XL', 229000, 9, 'in', 'published', 0, 30),

  ('dev-gloves-halffinger', 'gear', NULL, 'Half-finger tactical gloves',
   'Knuckle protection, bare trigger finger.',
   NULL, 'hands', 'S, M, L, XL', 89000, 0, 'soon', 'published', 0, 40);

-- Parts ---------------------------------------------------------------
INSERT INTO products
  (slug, category, brand, name, summary, description, part_group, fits,
   price_paise, stock_qty, stock_label, status, featured, sort_order)
VALUES
  ('dev-metal-gearset', 'part', NULL, 'Metal gear set, 18:1',
   'Steel-cut replacement set for a nylon-geared box.',
   'Fits the common Gen 8 and Gen 9 boxes. Shim it properly or it will be louder than the one you took out.',
   'internals', 'gen8, gen9', 129000, 11, 'in', 'published', 1, 10),

  ('dev-hopup-adjustable', 'part', NULL, 'Adjustable hop-up unit',
   'Dial in the lift instead of living with what it came with.',
   NULL, 'internals', 'gen8, gen9, v2', 94000, 7, 'in', 'published', 0, 20),

  ('dev-mosfet', 'part', NULL, 'Inline MOSFET',
   'Keeps the trigger contacts from burning on a LiPo.',
   NULL, 'electrical', 'any', 64000, 5, 'low', 'published', 0, 30),

  ('dev-gel-75k', 'ammo', NULL, '7–8mm gel, 75,000 rounds',
   'Grows to size in four hours. Soak them the night before.',
   'Hardness suited to 200–300 FPS. Store them dry.', 'gel', 'any',
   39000, 40, 'in', 'published', 1, 10);

SELECT CONCAT('inserted ', COUNT(*), ' dev products') AS result
FROM products WHERE slug LIKE 'dev-%';
