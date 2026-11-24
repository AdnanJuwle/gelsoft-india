exec(open('e2e_market.py').read().split('# ---- accounts')[0])
import re, subprocess

# Rebuild a held sale and try the buyer posting 'shipped'.
def sql(q):
    r = subprocess.run([MYSQL,"-u","gelsoft","-pdevpass","gelsoft","-sN","-e",q], capture_output=True)
    return r.stdout.decode("utf-8","replace").strip()

for email, name in [("p.seller@example.com","P Seller"),("p.buyer@example.com","P Buyer")]:
    c = C(); t = c.token("/register")
    c.post("/register", {"_token": t, "name": name, "email": email, "password": "marsh thicket vellum", "next": "/account"})

seller = C(); seller.login("p.seller@example.com","marsh thicket vellum")
buyer  = C(); buyer.login("p.buyer@example.com","marsh thicket vellum")

sid_user = sql("SELECT id FROM users WHERE email='p.seller@example.com'")
bid_user = sql("SELECT id FROM users WHERE email='p.buyer@example.com'")
sql(f"INSERT INTO listings (slug,seller_id,title,description,item_condition,price_paise,postage_paise,status,allow_inspection) VALUES ('probe-item',{sid_user},'Probe item','x',{'\"good\"'},100000,0,'sold',0)")
lid = sql("SELECT id FROM listings WHERE slug='probe-item'")
sql(f"INSERT INTO listing_orders (reference,listing_id,buyer_id,seller_id,price_paise,postage_paise,inspection_paise,total_paise,seller_fee_paise,handling_paise,payout_paise,inspected,status,ship_name,ship_phone,ship_line1,ship_city,ship_state,ship_pin) VALUES ('MK-PROBE-1',{lid},{bid_user},{sid_user},100000,0,0,100000,8000,2300,89700,0,'held','B','9876500000','1 Rd','Pune','Maharashtra','411001')")

t = buyer.token("/sale?ref=MK-PROBE-1")
print("token:", bool(t))
code, html, loc = buyer.post("/sale", {"_token": t, "ref": "MK-PROBE-1", "to": "shipped"})
m = re.findall(r'role="alert"[^>]*>(.*?)</p>', html, re.S)
print("code:", code, "loc:", loc)
print("says:", re.sub(r"\s+"," ", m[0])[:200] if m else "(no alert)")
print("status now:", sql("SELECT status FROM listing_orders WHERE reference='MK-PROBE-1'"))

sql("DELETE FROM listing_orders WHERE reference='MK-PROBE-1'")
sql("DELETE FROM listings WHERE slug='probe-item'")
sql("DELETE FROM users WHERE email LIKE 'p.%@example.com'")
