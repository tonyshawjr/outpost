#!/usr/bin/env python3
"""
Phase 2: Get Place Details for all places from Phase 1, then insert/update DB.
Supports resuming via checkpoint.
"""

import json
import re
import sqlite3
import time
import urllib.request
import urllib.parse
import ssl
import os
from datetime import datetime

API_KEY = "AIzaSyBwAPYHTca6_7rL4j6qf8G7XBNRR0IjwJo"
DB_PATH = "/Users/tonyshaw/Library/Application Support/outpost-builder/sites/discover-onslow/files/outpost/content/data/cms.db"
PLACES_FILE = "/tmp/onslow_places.json"
DETAILS_CHECKPOINT = "/tmp/onslow_details_checkpoint.json"

ssl_ctx = ssl.create_default_context()

TYPE_TO_LABELS = {
    "restaurant": [1, 7], "food": [7], "cafe": [1, 7, 98], "bakery": [104, 7],
    "bar": [85, 5], "night_club": [136, 5],
    "lawyer": [52, 13], "real_estate_agency": [53, 13],
    "car_repair": [92, 50], "car_dealer": [97, 50], "gas_station": [50],
    "doctor": [79, 3], "dentist": [112, 3], "health": [3], "hospital": [3],
    "pharmacy": [126, 3], "physiotherapist": [152, 3],
    "hair_care": [74, 3], "beauty_salon": [133, 3], "spa": [109, 3],
    "gym": [86, 8], "store": [2], "shopping_mall": [2],
    "clothing_store": [101, 2], "shoe_store": [103, 2], "jewelry_store": [108, 2],
    "furniture_store": [76, 2], "electronics_store": [124, 2], "book_store": [95, 2],
    "church": [88, 10], "place_of_worship": [88, 10],
    "lodging": [11], "hotel": [121, 11],
    "bank": [59, 13], "insurance_agency": [56, 13], "accounting": [54, 13],
    "storage": [4], "moving_company": [66, 4],
    "veterinary_care": [12], "pet_store": [119, 12],
    "school": [9], "primary_school": [9], "secondary_school": [9], "university": [9],
    "plumber": [61, 137], "electrician": [67, 137],
    "roofing_contractor": [62, 137], "general_contractor": [60, 137],
    "painter": [64, 137], "locksmith": [137],
    "meal_delivery": [7], "meal_takeaway": [7],
    "florist": [83, 2], "laundry": [4], "travel_agency": [13],
    "real_estate_agent": [53, 13],
}

def api_get(url):
    req = urllib.request.Request(url)
    with urllib.request.urlopen(req, context=ssl_ctx, timeout=30) as resp:
        return json.loads(resp.read().decode())

def place_details(place_id):
    params = {
        "place_id": place_id,
        "fields": "name,formatted_address,formatted_phone_number,website,opening_hours,geometry,types,rating,user_ratings_total,url,address_components,photos",
        "key": API_KEY,
    }
    url = "https://maps.googleapis.com/maps/api/place/details/json?" + urllib.parse.urlencode(params)
    return api_get(url)

def convert_hours(periods):
    if not periods:
        return default_hours()
    day_names = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"]
    if len(periods) == 1 and "close" not in periods[0]:
        return [{"day": d, "open": "12:00 AM", "close": "11:59 PM", "closed": False} for d in day_names[1:] + [day_names[0]]]
    hours_map = {}
    for period in periods:
        day_idx = period["open"]["day"]
        open_time = format_time(period["open"]["time"])
        close_time = format_time(period["close"]["time"]) if "close" in period else "11:59 PM"
        hours_map[day_idx] = {"day": day_names[day_idx], "open": open_time, "close": close_time, "closed": False}
    result = []
    for i in [1, 2, 3, 4, 5, 6, 0]:
        if i in hours_map:
            result.append(hours_map[i])
        else:
            result.append({"day": day_names[i], "open": "", "close": "", "closed": True})
    return result

def default_hours():
    return [{"day": d, "open": "", "close": "", "closed": False}
            for d in ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]]

def format_time(hhmm):
    h = int(hhmm[:2])
    m = hhmm[2:]
    ampm = "AM" if h < 12 else "PM"
    if h == 0: h = 12
    elif h > 12: h -= 12
    return f"{h}:{m} {ampm}"

def make_slug(name):
    slug = name.lower().strip()
    slug = re.sub(r'[^a-z0-9\s-]', '', slug)
    slug = re.sub(r'[\s-]+', '-', slug)
    return slug.strip('-')

def extract_address_components(components):
    street_number = route = city = state = zip_code = ""
    for comp in components:
        types = comp.get("types", [])
        if "street_number" in types: street_number = comp["long_name"]
        elif "route" in types: route = comp["long_name"]
        elif "locality" in types: city = comp["long_name"]
        elif "administrative_area_level_1" in types: state = comp["short_name"]
        elif "postal_code" in types: zip_code = comp["long_name"]
    return f"{street_number} {route}".strip(), city, state, zip_code

def main():
    # Load places from Phase 1
    with open(PLACES_FILE) as f:
        all_places = json.load(f)
    place_ids = list(all_places.keys())
    total = len(place_ids)
    print(f"Loaded {total} places from Phase 1")

    # Load checkpoint
    completed_pids = set()
    api_calls = 0
    if os.path.exists(DETAILS_CHECKPOINT):
        with open(DETAILS_CHECKPOINT) as f:
            cp = json.load(f)
            completed_pids = set(cp.get("completed", []))
            api_calls = cp.get("api_calls", 0)
        print(f"Resumed: {len(completed_pids)} already processed, {api_calls} API calls")

    # Connect to DB
    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA busy_timeout=30000")
    cur = conn.cursor()

    # Load existing businesses
    cur.execute("SELECT id, slug, data FROM collection_items WHERE collection_id = 1")
    existing = {}
    existing_by_slug = {}
    for row in cur.fetchall():
        item_id, slug, data_json = row
        try:
            data = json.loads(data_json)
        except: continue
        key = (data.get("title", "").lower().strip(), data.get("city", "").lower().strip())
        existing[key] = (item_id, data)
        existing_by_slug[slug] = item_id

    cur.execute("SELECT COALESCE(MAX(sort_order), 0) FROM collection_items WHERE collection_id = 1")
    next_sort = cur.fetchone()[0] + 1

    stats = {"inserted": 0, "updated": 0, "skipped": 0, "errors": 0, "out_of_state": 0}
    processed = len(completed_pids)

    for pid in place_ids:
        if pid in completed_pids:
            continue

        processed += 1
        if processed % 100 == 0:
            print(f"  Progress: {processed}/{total} | inserted={stats['inserted']} updated={stats['updated']} skipped={stats['skipped']} errors={stats['errors']} oos={stats['out_of_state']}")

        try:
            resp = place_details(pid)
            api_calls += 1
        except Exception as e:
            if "timed out" in str(e).lower():
                time.sleep(2)
                try:
                    resp = place_details(pid)
                    api_calls += 1
                except:
                    stats["errors"] += 1
                    completed_pids.add(pid)
                    continue
            else:
                stats["errors"] += 1
                completed_pids.add(pid)
                continue

        if resp.get("status") == "OVER_QUERY_LIMIT":
            print(f"  RATE LIMITED at {processed}/{total}. Saving checkpoint.")
            save_checkpoint(completed_pids, api_calls)
            conn.commit()
            conn.close()
            print(f"  Stats so far: {stats}")
            return

        if resp.get("status") != "OK":
            stats["errors"] += 1
            completed_pids.add(pid)
            continue

        detail = resp.get("result", {})
        name = detail.get("name", "").strip()
        if not name:
            stats["skipped"] += 1
            completed_pids.add(pid)
            continue

        addr_comps = detail.get("address_components", [])
        street, city, state, zip_code = extract_address_components(addr_comps)

        if state and state != "NC":
            stats["out_of_state"] += 1
            completed_pids.add(pid)
            continue

        geo = detail.get("geometry", {}).get("location", {})
        lat = str(geo.get("lat", ""))
        lng = str(geo.get("lng", ""))

        periods = detail.get("opening_hours", {}).get("periods", [])
        hours = convert_hours(periods) if periods else default_hours()

        data = {
            "title": name, "description": "", "address": street,
            "city": city, "state": state or "NC", "zip": zip_code,
            "phone": detail.get("formatted_phone_number", ""),
            "email": "", "website": detail.get("website", ""),
            "latitude": lat, "longitude": lng,
            "featured": False, "plan_tier": "free", "status": "active",
            "recommend_count": "0",
            "social_facebook": "", "social_instagram": "", "social_twitter": "",
            "logo": "", "featured_image": "", "photos": [], "hours": hours,
        }

        google_types = detail.get("types", []) + all_places.get(pid, {}).get("types", [])
        label_ids = set()
        for t in google_types:
            if t in TYPE_TO_LABELS:
                for lid in TYPE_TO_LABELS[t]:
                    label_ids.add(lid)

        dedup_key = (name.lower().strip(), city.lower().strip())

        if dedup_key in existing:
            item_id, old_data = existing[dedup_key]
            updated = False
            for field in ["address", "phone", "website", "latitude", "longitude", "zip"]:
                if not old_data.get(field) and data.get(field):
                    old_data[field] = data[field]
                    updated = True
            old_hours = old_data.get("hours", [])
            has_real = any(h.get("open") for h in old_hours) if old_hours else False
            new_has = any(h.get("open") for h in hours) if hours else False
            if not has_real and new_has:
                old_data["hours"] = hours
                updated = True
            if updated:
                now = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
                try:
                    cur.execute("UPDATE collection_items SET data = ?, updated_at = ? WHERE id = ?",
                               (json.dumps(old_data), now, item_id))
                    stats["updated"] += 1
                except sqlite3.OperationalError:
                    stats["errors"] += 1
            else:
                stats["skipped"] += 1
            if label_ids:
                for lid in label_ids:
                    try: cur.execute("INSERT OR IGNORE INTO item_labels (item_id, label_id) VALUES (?, ?)", (item_id, lid))
                    except: pass
        else:
            slug = make_slug(name)
            base_slug = slug
            counter = 1
            while slug in existing_by_slug:
                slug = f"{base_slug}-{counter}"
                counter += 1
            now = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
            try:
                cur.execute(
                    """INSERT INTO collection_items (collection_id, slug, status, data, sort_order, created_at, updated_at, published_at)
                       VALUES (1, ?, 'published', ?, ?, ?, ?, ?)""",
                    (slug, json.dumps(data), next_sort, now, now, now))
                item_id = cur.lastrowid
                existing_by_slug[slug] = item_id
                existing[dedup_key] = (item_id, data)
                next_sort += 1
                stats["inserted"] += 1
                if label_ids:
                    for lid in label_ids:
                        try: cur.execute("INSERT OR IGNORE INTO item_labels (item_id, label_id) VALUES (?, ?)", (item_id, lid))
                        except: pass
            except (sqlite3.IntegrityError, sqlite3.OperationalError) as e:
                stats["errors"] += 1

        completed_pids.add(pid)

        # Commit + checkpoint every 200
        if processed % 200 == 0:
            try:
                conn.commit()
            except sqlite3.OperationalError:
                time.sleep(2)
                try: conn.commit()
                except: pass
            save_checkpoint(completed_pids, api_calls)

        time.sleep(0.08)

    conn.commit()
    conn.close()
    save_checkpoint(completed_pids, api_calls)

    # Clean up checkpoint file
    if os.path.exists(DETAILS_CHECKPOINT):
        os.remove(DETAILS_CHECKPOINT)

    print(f"\n{'='*60}")
    print("PHASE 2 COMPLETE")
    print(f"{'='*60}")
    print(f"Detail API calls: {api_calls}")
    print(f"Inserted (new):   {stats['inserted']}")
    print(f"Updated:          {stats['updated']}")
    print(f"Skipped:          {stats['skipped']}")
    print(f"Out of state:     {stats['out_of_state']}")
    print(f"Errors:           {stats['errors']}")
    print(f"{'='*60}")

def save_checkpoint(completed, api_calls):
    with open(DETAILS_CHECKPOINT, "w") as f:
        json.dump({"completed": list(completed), "api_calls": api_calls}, f)

if __name__ == "__main__":
    main()
