-- Generated from the page copy: the FAQ and the policy pages, moved into
-- the database so the admin panel can edit them. Safe to re-run; it
-- replaces every FAQ entry.

SET NAMES utf8mb4;
DELETE FROM faq_entries;
INSERT INTO faq_entries (question, answer, sort_order, published) VALUES ('Is gelsoft legal in India?', 'Owning gel blasters and replicas is legal. Displaying, brandishing or openly carrying one in public is not — it causes genuine panic, and the charges for brandishing an imitation firearm are serious.

So: it travels fully concealed in a bag or a case, and it comes out on private property with the owner’s explicit permission. Never a park, a street or any other public space.', 10, 1);
INSERT INTO faq_entries (question, answer, sort_order, published) VALUES ('Does it hurt?', 'On bare skin at normal engagement distance, a hobby-grade blaster stings and leaves a small red welt. It does not bruise deep the way airsoft BBs do.

Point blank stings properly. Long sleeves, trousers or a chest rig drop most hits to a tap.', 20, 1);
INSERT INTO faq_entries (question, answer, sort_order, published) VALUES ('What do I need besides the blaster?', 'A battery and a balance charger, full-seal rated goggles, 7–8 mm gel, and a spare magazine. Roughly ₹3,000–₹4,000 for the lot.

Every product page lists this under “what it needs to run”, so you find out now and not on the morning of your first game.', 30, 1);
INSERT INTO faq_entries (question, answer, sort_order, published) VALUES ('Which grade should I buy?', 'Toy grade if you want something ready to shoot in a garden. Mid hobby grade if you are going to hosted games — a known gearbox generation means spares exist and cost a few hundred rupees. Premium grade is for people who tune.', 40, 1);
INSERT INTO faq_entries (question, answer, sort_order, published) VALUES ('How can I pay? Is there COD?', 'UPI, cards and net banking. No cash on delivery on blasters — the refusal rate on this category is bad and it costs everyone. COD is fine on gel, mags and small parts.', 50, 1);
INSERT INTO faq_entries (question, answer, sort_order, published) VALUES ('How long does delivery take, and how is it packed?', 'Ordered before 2pm on a working day it leaves the same day. Metros two to three days; the north-east and smaller towns, four to six.

It ships in a plain box with nothing on the outside that says what is inside. Lithium batteries travel by surface, so a battery ordered with a blaster can land a day or two behind it.', 60, 1);
INSERT INTO faq_entries (question, answer, sort_order, published) VALUES ('What if it arrives damaged?', 'We replace it, on an uncut unboxing video. Start filming with the parcel sealed, open it in one take, and send it to us the same day.

There is no warranty beyond that and no refunds — all sales are final. Every item is inspected and test-fired before dispatch, and after that, wear is wear: nozzles, O-rings and springs are consumables we keep in stock, and bench service is ₹600 for a clean and regrease, ₹1,500 for a full rebuild plus parts. Full policy.', 70, 1);
INSERT INTO faq_entries (question, answer, sort_order, published) VALUES ('How does the marketplace hold payments?', 'You pay us, not the seller. The money sits with us until you confirm the parcel arrived, or the 48-hour inspection window closes. Then it goes to the seller.

It also means a seller with two sales is no riskier than one with fifty — which matters, because most people here are selling one or two things.', 80, 1);
INSERT INTO faq_entries (question, answer, sort_order, published) VALUES ('I only want to sell one blaster. Is that worth doing?', 'That is who the marketplace is built for. No shop, no GST number, no selling history — one verification, photos from your phone, and a price.

Listing is free and there is nothing to pay unless it sells. The payout lands on UPI the working day after the buyer confirms.', 90, 1);
INSERT INTO faq_entries (question, answer, sort_order, published) VALUES ('Can I sell used eye protection?', 'No. A lens that has taken a hit can be fractured in ways you cannot see, and the buyer is the one who finds out.', 100, 1);
INSERT INTO pages (slug, title, body) VALUES ('policy', 'Replacement & refund policy', 'All sales are final. We do not accept returns for refunds or exchanges.

We replace items damaged during shipment on submission of an uncut unboxing video. Every item is inspected for function and quality before dispatch.

We do not offer warranties or guarantees on our products.

To start a replacement, email support@gelsoft.in or message gelsoftindia on Instagram. Returns without prior authorisation will not be accepted.') ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body);
INSERT INTO pages (slug, title, body) VALUES ('terms', 'Terms', 'Gelsoft India sells gel blasters, protective gear and parts within India.

Owning a gel blaster is legal here. Displaying, brandishing or carrying one in public is not. By buying from us you agree to transport it concealed and to use it only on private ground with the owner''s permission.

Prices are in Indian rupees and include GST where it applies. We may change a price at any time before an order is placed.

Marketplace listings are between the buyer and the seller. We hold the payment, review listings before they go live, and step in when a sale goes wrong, but we are not the seller.') ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body);
INSERT INTO pages (slug, title, body) VALUES ('privacy', 'Privacy', 'We keep what we need to send you an order and to answer you when you ask: your name, email address, phone number and delivery address.

Payments go through our payment provider. Card and UPI details are entered on their page and never reach our server.

We do not sell your details to anyone, and we do not send marketing you did not ask for. A restock alert is one message about the thing you asked about.

To have your account and its history deleted, email support@gelsoft.in from the address on the account.') ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body);
