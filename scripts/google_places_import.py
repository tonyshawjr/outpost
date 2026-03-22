#!/usr/bin/env python3
"""
Google Places API → Discover Onslow DB importer
Pulls all businesses in Onslow County, NC and inserts them into the CMS database.
"""

import json
import re
import sqlite3
import time
import urllib.request
import urllib.parse
import ssl
from datetime import datetime

API_KEY = "AIzaSyBwAPYHTca6_7rL4j6qf8G7XBNRR0IjwJo"
DB_PATH = "/Users/tonyshaw/Library/Application Support/outpost-builder/sites/discover-onslow/files/outpost/content/data/cms.db"

CITIES = [
    "Jacksonville", "Swansboro", "Sneads Ferry", "Holly Ridge",
    "Richlands", "Hubert", "North Topsail Beach", "Surf City",
    "Midway Park", "Maysville", "Stella"
]

CATEGORIES = [
    "restaurants", "bars", "cafes", "bakeries",
    "auto repair", "car dealers", "gas stations",
    "lawyers", "attorneys",
    "real estate", "insurance",
    "doctors", "dentists", "chiropractors", "veterinarians",
    "hair salons", "barber shops", "spas",
    "gyms", "fitness",
    "plumbers", "electricians", "HVAC", "roofers", "landscaping",
    "churches",
    "retail stores", "shopping",
    "hotels", "lodging",
    "banks", "financial services",
    "storage", "moving companies",
    "childcare", "schools",
    "tattoo shops",
    "pet stores", "pet services"
]

# Map Google types to label IDs (folder_id=1 labels)
# Built from the existing labels query
TYPE_TO_LABELS = {
    "restaurant": [1, 7],          # Restaurants, Food & Drink
    "food": [7],                     # Food & Drink
    "cafe": [1, 7, 98],            # Restaurants, Food & Drink, Coffee
    "bakery": [104, 7],            # Bakeries, Food & Drink
    "bar": [85, 5],                 # Bars, Entertainment
    "night_club": [136, 5],        # Nightclubs, Entertainment
    "lawyer": [52, 13],            # Attorneys, Professional Services
    "real_estate_agency": [53, 13], # Real Estate, Professional Services
    "car_repair": [92, 50],        # Auto Repair, Automotive
    "car_dealer": [97, 50],        # Dealers (New), Automotive
    "gas_station": [50],           # Automotive
    "doctor": [79, 3],             # Doctors, Health & Beauty
    "dentist": [112, 3],           # Dentists, Health & Beauty
    "health": [3],                  # Health & Beauty
    "hospital": [3],                # Health & Beauty
    "pharmacy": [126, 3],          # Pharmacies, Health & Beauty
    "physiotherapist": [152, 3],   # Physical Therapy, Health & Beauty
    "hair_care": [74, 3],          # Hair Salons, Health & Beauty
    "beauty_salon": [133, 3],      # Beauty & Spa, Health & Beauty
    "spa": [109, 3],               # Day Spas, Health & Beauty
    "gym": [86, 8],                 # Gyms, Fitness
    "store": [2],                   # Shopping
    "shopping_mall": [2],          # Shopping
    "clothing_store": [101, 2],    # Clothing, Shopping
    "shoe_store": [103, 2],        # Shoes, Shopping
    "jewelry_store": [108, 2],     # Jewelry, Shopping
    "furniture_store": [76, 2],    # Furniture, Shopping
    "electronics_store": [124, 2], # Electronics, Shopping
    "book_store": [95, 2],         # Books, Shopping
    "church": [88, 10],            # Churches, Community
    "place_of_worship": [88, 10],  # Churches, Community
    "lodging": [11],               # Lodging
    "hotel": [121, 11],            # Hotels, Lodging
    "bank": [59, 13],              # Banking, Professional Services
    "insurance_agency": [56, 13],  # Insurance, Professional Services
    "accounting": [54, 13],        # Accountants, Professional Services
    "storage": [4],                 # Services
    "moving_company": [66, 4],     # Moving, Services
    "veterinary_care": [12],       # Pets
    "pet_store": [119, 12],        # Pet Services, Pets
    "school": [9],                  # Education
    "primary_school": [9],         # Education
    "secondary_school": [9],       # Education
    "university": [9],             # Education
    "plumber": [61, 137],          # Plumbing, Home Services
    "electrician": [67, 137],      # Electrical, Home Services
    "roofing_contractor": [62, 137], # Roofing, Home Services
    "general_contractor": [60, 137], # General Contractors, Home Services
    "painter": [64, 137],          # Painting, Home Services
    "locksmith": [137],            # Home Services
    "meal_delivery": [7],          # Food & Drink
    "meal_takeaway": [7],          # Food & Drink
    "florist": [83, 2],            # Florists, Shopping
    "laundry": [4],                # Services
    "parking": [4],                # Services
    "travel_agency": [13],         # Professional Services
    "real_estate_agent": [53, 13], # Real Estate, Professional Services
}

# SSL context for macOS
ssl_ctx = ssl.create_default_context()

def api_get(url):
    """Make a GET request and return JSON."""
    req = urllib.request.Request(url)
    with urllib.request.urlopen(req, context=ssl_ctx) as resp:
        return json.loads(resp.read().decode())


def text_search(query, page_token=None):
    """Google Places Text Search."""
    params = {
        "query": query,
        "key": API_KEY,
    }
    if page_token:
        params["pagetoken"] = page_token
    url = "https://maps.googleapis.com/maps/api/place/textsearch/json?" + urllib.parse.urlencode(params)
    return api_get(url)


def place_details(place_id):
    """Google Places Details."""
    params = {
        "place_id": place_id,
        "fields": "name,formatted_address,formatted_phone_number,website,opening_hours,geometry,types,rating,user_ratings_total,url,address_components,photos",
        "key": API_KEY,
    }
    url = "https://maps.googleapis.com/maps/api/place/details/json?" + urllib.parse.urlencode(params)
    return api_get(url)


def convert_hours(periods):
    """Convert Google hours periods to Outpost format."""
    if not periods:
        return default_hours()

    day_names = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"]
    hours_map = {}

    # Check for 24-hour business (single period with no close)
    if len(periods) == 1 and "close" not in periods[0]:
        return [{"day": d, "open": "12:00 AM", "close": "11:59 PM", "closed": False} for d in day_names[1:] + [day_names[0]]]

    for period in periods:
        day_idx = period["open"]["day"]
        day_name = day_names[day_idx]
        open_time = format_time(period["open"]["time"])
        close_time = format_time(period["close"]["time"]) if "close" in period else "11:59 PM"
        hours_map[day_idx] = {"day": day_name, "open": open_time, "close": close_time, "closed": False}

    # Build full week (Monday first), mark missing days as closed
    result = []
    for i in [1, 2, 3, 4, 5, 6, 0]:  # Mon-Sun
        if i in hours_map:
            result.append(hours_map[i])
        else:
            result.append({"day": day_names[i], "open": "", "close": "", "closed": True})
    return result


def default_hours():
    """Return default empty hours."""
    days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
    return [{"day": d, "open": "", "close": "", "closed": False} for d in days]


def format_time(hhmm):
    """Convert '0900' to '9:00 AM'."""
    h = int(hhmm[:2])
    m = hhmm[2:]
    ampm = "AM" if h < 12 else "PM"
    if h == 0:
        h = 12
    elif h > 12:
        h -= 12
    return f"{h}:{m} {ampm}"


def make_slug(name):
    """Generate URL slug from business name."""
    slug = name.lower().strip()
    slug = re.sub(r'[^a-z0-9\s-]', '', slug)
    slug = re.sub(r'[\s-]+', '-', slug)
    slug = slug.strip('-')
    return slug


def extract_address_components(components):
    """Extract street, city, state, zip from Google address_components."""
    street_number = ""
    route = ""
    city = ""
    state = ""
    zip_code = ""

    for comp in components:
        types = comp.get("types", [])
        if "street_number" in types:
            street_number = comp["long_name"]
        elif "route" in types:
            route = comp["long_name"]
        elif "locality" in types:
            city = comp["long_name"]
        elif "administrative_area_level_1" in types:
            state = comp["short_name"]
        elif "postal_code" in types:
            zip_code = comp["long_name"]

    street = f"{street_number} {route}".strip()
    return street, city, state, zip_code


def get_label_ids_for_types(google_types):
    """Map Google place types to our label IDs."""
    label_ids = set()
    for t in google_types:
        if t in TYPE_TO_LABELS:
            for lid in TYPE_TO_LABELS[t]:
                label_ids.add(lid)
    return label_ids


def main():
    conn = sqlite3.connect(DB_PATH)
    conn.execute("PRAGMA journal_mode=WAL")
    cur = conn.cursor()

    # Load existing businesses for dedup (name+city, case-insensitive)
    cur.execute("SELECT id, slug, data FROM collection_items WHERE collection_id = 1")
    existing = {}
    existing_by_slug = {}
    for row in cur.fetchall():
        item_id, slug, data_json = row
        try:
            data = json.loads(data_json)
        except:
            continue
        key = (data.get("title", "").lower().strip(), data.get("city", "").lower().strip())
        existing[key] = (item_id, data)
        existing_by_slug[slug] = item_id

    # Get next sort_order
    cur.execute("SELECT COALESCE(MAX(sort_order), 0) FROM collection_items WHERE collection_id = 1")
    next_sort = cur.fetchone()[0] + 1

    # Track unique place_ids to avoid duplicate detail calls
    seen_place_ids = set()
    all_places = {}  # place_id -> text search result

    # Stats
    stats = {
        "text_searches": 0,
        "detail_lookups": 0,
        "inserted": 0,
        "updated": 0,
        "skipped": 0,
        "errors": 0,
    }

    # Step 1: Collect all unique place_ids via Text Search
    print("=" * 60)
    print("STEP 1: Text Search — collecting place IDs")
    print("=" * 60)

    for city in CITIES:
        for category in CATEGORIES:
            query = f"{category} in {city} NC"
            print(f"\n  Searching: {query}")

            try:
                result = text_search(query)
                stats["text_searches"] += 1
            except Exception as e:
                print(f"    ERROR: {e}")
                stats["errors"] += 1
                continue

            if result.get("status") not in ("OK", "ZERO_RESULTS"):
                print(f"    API status: {result.get('status')} - {result.get('error_message', '')}")
                if result.get("status") == "OVER_QUERY_LIMIT":
                    print("    Rate limited! Waiting 60 seconds...")
                    time.sleep(60)
                continue

            results = result.get("results", [])
            new_in_batch = 0
            for place in results:
                pid = place["place_id"]
                if pid not in seen_place_ids:
                    seen_place_ids.add(pid)
                    all_places[pid] = place
                    new_in_batch += 1

            print(f"    Got {len(results)} results, {new_in_batch} new unique")

            # Handle pagination
            page = 1
            while "next_page_token" in result and page < 3:  # max 3 pages = 60 results
                page += 1
                print(f"    Fetching page {page}...")
                time.sleep(2.5)  # Google requires ~2s delay for next_page_token
                try:
                    result = text_search(query, page_token=result["next_page_token"])
                    stats["text_searches"] += 1
                except Exception as e:
                    print(f"    ERROR on page {page}: {e}")
                    stats["errors"] += 1
                    break

                if result.get("status") not in ("OK", "ZERO_RESULTS"):
                    print(f"    Page {page} status: {result.get('status')}")
                    break

                results = result.get("results", [])
                new_in_batch = 0
                for place in results:
                    pid = place["place_id"]
                    if pid not in seen_place_ids:
                        seen_place_ids.add(pid)
                        all_places[pid] = place
                        new_in_batch += 1
                print(f"    Got {len(results)} results, {new_in_batch} new unique")

            # Small delay between searches
            time.sleep(0.15)

    print(f"\n{'=' * 60}")
    print(f"Text search complete: {len(all_places)} unique places found")
    print(f"Text search API calls: {stats['text_searches']}")
    print(f"{'=' * 60}")

    # Step 2: Get details for each place and insert/update
    print(f"\nSTEP 2: Place Details + DB Insert/Update")
    print("=" * 60)

    place_ids = list(all_places.keys())
    total = len(place_ids)

    for i, pid in enumerate(place_ids):
        if (i + 1) % 50 == 0 or i == 0:
            print(f"\n  Progress: {i + 1}/{total}")

        try:
            detail_resp = place_details(pid)
            stats["detail_lookups"] += 1
        except Exception as e:
            print(f"  ERROR getting details for {pid}: {e}")
            stats["errors"] += 1
            continue

        if detail_resp.get("status") != "OK":
            status = detail_resp.get("status")
            if status == "OVER_QUERY_LIMIT":
                print("  Rate limited! Waiting 60 seconds...")
                time.sleep(60)
                try:
                    detail_resp = place_details(pid)
                    stats["detail_lookups"] += 1
                except:
                    continue
                if detail_resp.get("status") != "OK":
                    stats["errors"] += 1
                    continue
            else:
                stats["errors"] += 1
                continue

        detail = detail_resp.get("result", {})
        name = detail.get("name", "").strip()
        if not name:
            stats["skipped"] += 1
            continue

        # Extract address components
        addr_comps = detail.get("address_components", [])
        street, city, state, zip_code = extract_address_components(addr_comps)

        # Skip if not in NC
        if state and state != "NC":
            stats["skipped"] += 1
            continue

        # Get coordinates
        geo = detail.get("geometry", {}).get("location", {})
        lat = str(geo.get("lat", ""))
        lng = str(geo.get("lng", ""))

        # Convert hours
        hours_data = detail.get("opening_hours", {})
        periods = hours_data.get("periods", [])
        hours = convert_hours(periods) if periods else default_hours()

        # Build data object
        data = {
            "title": name,
            "description": "",
            "address": street,
            "city": city,
            "state": state or "NC",
            "zip": zip_code,
            "phone": detail.get("formatted_phone_number", ""),
            "email": "",
            "website": detail.get("website", ""),
            "latitude": lat,
            "longitude": lng,
            "featured": False,
            "plan_tier": "free",
            "status": "active",
            "recommend_count": "0",
            "social_facebook": "",
            "social_instagram": "",
            "social_twitter": "",
            "logo": "",
            "featured_image": "",
            "photos": [],
            "hours": hours,
        }

        # Get Google types for label mapping
        google_types = detail.get("types", []) + all_places.get(pid, {}).get("types", [])
        label_ids = get_label_ids_for_types(google_types)

        # Dedup check: name + city
        dedup_key = (name.lower().strip(), city.lower().strip())

        if dedup_key in existing:
            # UPDATE: fill in missing fields only
            item_id, old_data = existing[dedup_key]
            updated = False
            for field in ["address", "phone", "website", "latitude", "longitude", "zip"]:
                if not old_data.get(field) and data.get(field):
                    old_data[field] = data[field]
                    updated = True
            # Always update hours if old hours are empty/default
            old_hours = old_data.get("hours", [])
            has_real_hours = any(h.get("open") for h in old_hours) if old_hours else False
            new_has_hours = any(h.get("open") for h in hours) if hours else False
            if not has_real_hours and new_has_hours:
                old_data["hours"] = hours
                updated = True

            if updated:
                now = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
                cur.execute(
                    "UPDATE collection_items SET data = ?, updated_at = ? WHERE id = ?",
                    (json.dumps(old_data), now, item_id)
                )
                stats["updated"] += 1
            else:
                stats["skipped"] += 1

            # Still add any new labels
            if label_ids:
                for lid in label_ids:
                    try:
                        cur.execute("INSERT OR IGNORE INTO item_labels (item_id, label_id) VALUES (?, ?)", (item_id, lid))
                    except:
                        pass
        else:
            # INSERT new business
            slug = make_slug(name)
            # Ensure unique slug
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
                    (slug, json.dumps(data), next_sort, now, now, now)
                )
                item_id = cur.lastrowid
                existing_by_slug[slug] = item_id
                existing[dedup_key] = (item_id, data)
                next_sort += 1
                stats["inserted"] += 1

                # Add labels
                if label_ids:
                    for lid in label_ids:
                        try:
                            cur.execute("INSERT OR IGNORE INTO item_labels (item_id, label_id) VALUES (?, ?)", (item_id, lid))
                        except:
                            pass
            except sqlite3.IntegrityError as e:
                print(f"  DB error inserting {name}: {e}")
                stats["errors"] += 1

        # Commit every 100 records
        if (i + 1) % 100 == 0:
            conn.commit()

        # Rate limit: small delay between detail requests
        time.sleep(0.12)

    # Final commit
    conn.commit()
    conn.close()

    # Final report
    print(f"\n{'=' * 60}")
    print("IMPORT COMPLETE")
    print(f"{'=' * 60}")
    print(f"Unique places found:    {len(all_places)}")
    print(f"Text search API calls:  {stats['text_searches']}")
    print(f"Detail lookup API calls:{stats['detail_lookups']}")
    print(f"Inserted (new):         {stats['inserted']}")
    print(f"Updated (existing):     {stats['updated']}")
    print(f"Skipped (no change):    {stats['skipped']}")
    print(f"Errors:                 {stats['errors']}")
    print(f"{'=' * 60}")


if __name__ == "__main__":
    main()
