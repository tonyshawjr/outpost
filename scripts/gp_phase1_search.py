#!/usr/bin/env python3
"""
Phase 1: Google Places Text Search — collect all unique place IDs.
Saves results to /tmp/onslow_places.json for Phase 2.
Supports resuming from a checkpoint.
"""

import json
import time
import urllib.request
import urllib.parse
import ssl
import os

API_KEY = "AIzaSyBwAPYHTca6_7rL4j6qf8G7XBNRR0IjwJo"
OUTPUT_FILE = "/tmp/onslow_places.json"
CHECKPOINT_FILE = "/tmp/onslow_checkpoint.json"

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

ssl_ctx = ssl.create_default_context()

def api_get(url):
    req = urllib.request.Request(url)
    with urllib.request.urlopen(req, context=ssl_ctx, timeout=30) as resp:
        return json.loads(resp.read().decode())

def text_search(query, page_token=None):
    params = {"query": query, "key": API_KEY}
    if page_token:
        params["pagetoken"] = page_token
    url = "https://maps.googleapis.com/maps/api/place/textsearch/json?" + urllib.parse.urlencode(params)
    return api_get(url)

def main():
    # Load checkpoint if exists
    all_places = {}
    completed_searches = set()
    api_calls = 0

    if os.path.exists(CHECKPOINT_FILE):
        with open(CHECKPOINT_FILE) as f:
            cp = json.load(f)
            all_places = cp.get("places", {})
            completed_searches = set(cp.get("completed", []))
            api_calls = cp.get("api_calls", 0)
        print(f"Resumed from checkpoint: {len(all_places)} places, {len(completed_searches)} searches done, {api_calls} API calls")

    total_searches = len(CITIES) * len(CATEGORIES)
    done_count = len(completed_searches)

    for city in CITIES:
        for category in CATEGORIES:
            search_key = f"{category}|{city}"
            if search_key in completed_searches:
                continue

            query = f"{category} in {city} NC"
            print(f"  [{done_count+1}/{total_searches}] {query}", end="", flush=True)

            try:
                result = text_search(query)
                api_calls += 1
            except Exception as e:
                print(f" ERROR: {e}")
                continue

            if result.get("status") == "OVER_QUERY_LIMIT":
                print(" RATE LIMITED - saving checkpoint and exiting")
                save_checkpoint(all_places, completed_searches, api_calls)
                return

            if result.get("status") not in ("OK", "ZERO_RESULTS"):
                print(f" status={result.get('status')}")
                completed_searches.add(search_key)
                done_count += 1
                continue

            results = result.get("results", [])
            new_count = 0
            for place in results:
                pid = place["place_id"]
                if pid not in all_places:
                    all_places[pid] = {
                        "name": place.get("name"),
                        "types": place.get("types", []),
                        "formatted_address": place.get("formatted_address", ""),
                    }
                    new_count += 1

            # Pagination (max 2 extra pages)
            page = 1
            while "next_page_token" in result and page < 3:
                page += 1
                time.sleep(2.5)
                try:
                    result = text_search(query, page_token=result["next_page_token"])
                    api_calls += 1
                except Exception as e:
                    print(f" page{page} error: {e}")
                    break
                if result.get("status") == "OVER_QUERY_LIMIT":
                    print(" RATE LIMITED on pagination")
                    save_checkpoint(all_places, completed_searches, api_calls)
                    return
                if result.get("status") not in ("OK", "ZERO_RESULTS"):
                    break
                for place in result.get("results", []):
                    pid = place["place_id"]
                    if pid not in all_places:
                        all_places[pid] = {
                            "name": place.get("name"),
                            "types": place.get("types", []),
                            "formatted_address": place.get("formatted_address", ""),
                        }
                        new_count += 1

            completed_searches.add(search_key)
            done_count += 1
            print(f" +{new_count} new (total: {len(all_places)})")

            # Save checkpoint every 20 searches
            if done_count % 20 == 0:
                save_checkpoint(all_places, completed_searches, api_calls)

            time.sleep(0.1)

    # Save final results
    save_checkpoint(all_places, completed_searches, api_calls)
    with open(OUTPUT_FILE, "w") as f:
        json.dump(all_places, f)

    print(f"\n{'='*60}")
    print(f"PHASE 1 COMPLETE")
    print(f"Total unique places: {len(all_places)}")
    print(f"Text search API calls: {api_calls}")
    print(f"Results saved to: {OUTPUT_FILE}")
    print(f"{'='*60}")

def save_checkpoint(places, completed, api_calls):
    with open(CHECKPOINT_FILE, "w") as f:
        json.dump({
            "places": places,
            "completed": list(completed),
            "api_calls": api_calls,
        }, f)
    print(f"  [checkpoint saved: {len(places)} places, {len(completed)} searches, {api_calls} calls]")

if __name__ == "__main__":
    main()
