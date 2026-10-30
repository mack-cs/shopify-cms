"""Approved source eligibility and CMS physical-consumption input, not a forecasting model.

No order-line bead multipliers or member-to-family sales aggregation belong here.
The CMS has already measured every exported consumption quantity in physical beads.
"""
from __future__ import annotations

import json
import re
from pathlib import Path

import pandas as pd

from src import config
from src.utils import normalise_sku, normalise_sku_series

VERSION = "shared-beads-v1"
FORECAST_MODE = "ordinary_tracked_source"


def _raw_dir():
    return Path(getattr(config, "RAW_DIR", config.PRODUCTS_FILE.parent))


def load_sources():
    path = _raw_dir() / "engraved_bead_families.json"
    if not path.exists():
        return {}
    contract = json.loads(path.read_text(encoding="utf-8-sig"))
    if contract.get("version") != VERSION or contract.get("forecast_mode") != FORECAST_MODE:
        raise ValueError("An ordinary_tracked_source shared-beads-v1 mapping is required; weighted family contracts are retired")
    if not isinstance(contract.get("families"), list):
        raise ValueError("Invalid approved source list")
    result, seen_skus, seen_items, seen_families = {}, set(), set(), set()
    for source in contract["families"]:
        if source.get("status") != "active" or source.get("approved") is not True:
            continue
        for key, resource in (("source_product_id", "Product"), ("source_variant_id", "ProductVariant"),
                              ("source_inventory_item_id", "InventoryItem"), ("source_location_id", "Location")):
            if not re.fullmatch(r"gid://shopify/" + resource + r"/\d+", str(source.get(key, ""))):
                raise ValueError("Invalid approved source identity")
        sku = normalise_sku(source.get("source_sku"))
        family = source.get("family_tag", "")
        if (not sku or not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*-bead-family", family)
                or not source.get("mapping_hash") or type(source.get("configuration_version")) is not int
                or source["configuration_version"] < 1 or pd.isna(pd.to_datetime(source.get("activated_at"), errors="coerce", utc=True))):
            raise ValueError("Incomplete approved source mapping")
        variant, item = source["source_variant_id"], source["source_inventory_item_id"]
        if variant in result or sku in seen_skus or item in seen_items or family in seen_families:
            raise ValueError("Duplicate approved source identity or SKU")
        result[variant] = {**source, "source_sku": sku}
        seen_skus.add(sku); seen_items.add(item); seen_families.add(family)
    return result


def product_roles(raw):
    sources = load_sources()
    roles = []
    for _, row in raw.iterrows():
        tags = [t.strip() for t in re.split(r"[,;]", str(row.get("Tags", ""))) if t.strip()]
        families = [t for t in tags if t.endswith("-bead-family")]
        quantities = [t for t in tags if re.fullmatch(r"\d+-engraved-beads", t)]
        variant = str(row.get("Variant ID", "")).strip()
        source = sources.get(variant)
        identifying = {t.replace("_", "-") for t in tags}.intersection({"engraved-beads-unlisted", "engaved-unlisted-beads"})
        participating = bool(families or quantities or source or identifying)
        approved = bool(source and families == [source["family_tag"]] and quantities == ["1-engraved-beads"]
            and str(row.get("Product ID", "")).strip() == source["source_product_id"]
            and str(row.get("Inventory Item ID", "")).strip() == source["source_inventory_item_id"]
            and normalise_sku(row.get("Variant SKU")) == source["source_sku"])
        roles.append((participating, approved))
    return pd.DataFrame(roles, index=raw.index, columns=["is_engraved_bead", "is_shared_bead_source"])


def source_consumption_demand(combined, products):
    """Keep native source sales; replace its stack expansion with confirmed CMS consumption.

    Ordinary products/stacks are unchanged. Reservations, releases, restocks, corrections,
    receipts, transfers, safety-stock moves and incomplete operations are never demand.
    """
    sources = load_sources()
    active = products.loc[products.get("is_shared_bead_source", pd.Series(False, index=products.index)).fillna(False).astype(bool)
                          & products.get("is_procurement_candidate", pd.Series(False, index=products.index)).fillna(False).astype(bool)]
    candidates = {str(r["shopify_variant_id"]): r for _, r in active.iterrows()}
    source_skus = set(active["sku"])
    bead_skus = set(products.loc[products.get("is_engraved_bead", pd.Series(False, index=products.index)).fillna(False).astype(bool), "sku"])
    combined = combined.copy()
    if "shopify_variant_id" in combined:
        for variant, source in sources.items():
            native = combined["shopify_variant_id"].eq(variant) & combined["demand_source"].eq("direct_sale")
            combined.loc[native, "demand_sku"] = source["source_sku"]
    # Do not count a CMS-consumed stack component again via ordinary stack expansion.
    excluded = combined["demand_sku"].isin(bead_skus - source_skus)
    excluded |= combined["demand_sku"].isin(source_skus) & combined["demand_source"].eq("stack_component")
    combined = combined.loc[~excluded].copy()
    path = _raw_dir() / "engraved_bead_consumption.csv"
    if candidates and not path.exists():
        raise ValueError("Confirmed CMS source consumption feed is required; order lines alone omit multi-bead physical consumption")
    rows = []
    if path.exists():
        events = pd.read_csv(path, dtype=str, keep_default_na=False)
        required = {"event_id", "action", "status", "occurred_at", "order_name", "source_variant_id",
                    "source_inventory_item_id", "source_location_id", "sku", "quantity", "units"}
        if required.difference(events.columns):
            raise ValueError("Incomplete CMS source consumption feed")
        events = events.loc[events["action"].eq("consume") & events["status"].eq("completed")].copy()
        duplicates = events[events.duplicated("event_id", keep=False)]
        if not duplicates.empty and duplicates.groupby("event_id").nunique().gt(1).any().any():
            raise ValueError("Conflicting duplicate consumption event")
        for _, event in events.drop_duplicates("event_id").iterrows():
            variant = event["source_variant_id"]
            if variant not in candidates:
                continue
            source = sources[variant]
            quantity = pd.to_numeric(event["quantity"], errors="coerce")
            occurred = pd.to_datetime(event["occurred_at"], errors="coerce", utc=True)
            if (not event["event_id"].startswith("cms-bead-consume:") or event["units"] != "physical_beads"
                    or normalise_sku(event["sku"]) != source["source_sku"]
                    or event["source_inventory_item_id"] != source["source_inventory_item_id"]
                    or event["source_location_id"] != source["source_location_id"]
                    or pd.isna(quantity) or quantity <= 0 or float(quantity).is_integer() is False or pd.isna(occurred)
                    or occurred < pd.to_datetime(source["activated_at"], utc=True)):
                raise ValueError("Invalid physical consumption provenance")
            rows.append({"order_name": event["order_name"] or event["event_id"], "order_id": event["event_id"],
                         "order_date": occurred, "sku": source["source_sku"], "quantity": int(quantity),
                         "demand_sku": source["source_sku"], "demand_quantity": int(quantity),
                         "demand_name": candidates[variant]["product_name"], "demand_source": "cms_consumption",
                         "source_stack_sku": pd.NA, "source_stack_name": pd.NA, "quantity_per_stack": 0})
    physical = pd.DataFrame(rows, columns=list(dict.fromkeys(list(combined.columns) + ["order_name", "order_date", "demand_sku", "demand_quantity", "demand_source", "demand_name"])))
    physical.to_csv(config.PROCESSED_DIR / "cms_source_consumption_demand.csv", index=False)
    return pd.concat([combined, physical], ignore_index=True) if rows else combined
