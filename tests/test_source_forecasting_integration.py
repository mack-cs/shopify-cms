"""Execute actual Python pipeline functions with temporary feeds and no operational config."""
import importlib
import json
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import patch

import pandas as pd

CMS = Path(__file__).resolve().parents[1]
PIPELINE = Path(r"D:\python_projects\leigh_ml_procurement_v1_with_incoming_stock")
STAGED = "--staged" in sys.argv
if STAGED:
    sys.argv.remove("--staged")


class SourceForecastingTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for name in list(sys.modules):
            if name == "src" or name.startswith("src."):
                del sys.modules[name]
        package = types.ModuleType("src")
        package.__path__ = ([str(CMS / "storage/framework/testing/source-forecast-update/src")] if STAGED else []) + [str(PIPELINE / "src")]
        sys.modules["src"] = package
        config = types.ModuleType("src.config")
        config.RAW_DIR = self.root
        config.PROCESSED_DIR = self.root / "processed"
        config.PROCESSED_DIR.mkdir()
        config.MODEL_DIR = config.OUTPUT_DIR = config.PROCESSED_DIR
        config.PRODUCTS_FILE = self.root / "products.csv"
        config.STACK_COMPONENTS_FILE = self.root / "stacks.csv"
        config.ORDERS_FILE = self.root / "orders.csv"
        config.BUSINESS_TIMEZONE = "Africa/Johannesburg"
        config.LEAD_TIME_DAYS = 56
        config.MIN_HISTORY_DAYS = 28
        config.RECENT_WINDOWS = (28, 56, 84)
        sys.modules["src.config"] = config
        self.config = config
        self.families, self.products = [], []
        self.block_network = patch("socket.create_connection", side_effect=AssertionError("Network forbidden in tests"))
        self.block_network.start()
        self.addCleanup(self.block_network.stop)

    def module(self, name):
        return importlib.import_module("src." + name)

    def family(self, number=1, approved=True, status="active"):
        base = number * 100
        tag = f"design-{number}-bead-family"
        source = {"family_tag": tag, "status": status, "approved": approved,
                  "configuration_version": 1, "mapping_hash": "a" * 64, "activated_at": "2026-10-01T00:00:00Z",
                  "source_product_id": f"gid://shopify/Product/{base+1}",
                  "source_variant_id": f"gid://shopify/ProductVariant/{base+1}",
                  "source_inventory_item_id": f"gid://shopify/InventoryItem/{base+1}",
                  "source_location_id": "gid://shopify/Location/1", "source_sku": f"SOURCE{number}"}
        self.families.append(source)
        for q in range(1, 5):
            self.products.append({"Handle": f"design-{number}-{q}", "Product ID": f"gid://shopify/Product/{base+q}",
                "Variant ID": f"gid://shopify/ProductVariant/{base+q}", "Inventory Item ID": f"gid://shopify/InventoryItem/{base+q}",
                "Variant SKU": source["source_sku"] if q == 1 else f"MEMBER{number}-{q}", "Status": "unlisted",
                "Tags": f"{tag}, {q}-engraved-beads", "Variant Inventory Tracker": "shopify" if q == 1 else "",
                "Variant Inventory Qty": 20 if q == 1 else 0, "Product Title": "Bead source" if q == 1 else "Member"})
        return source

    def write_inputs(self):
        pd.DataFrame(self.products).to_csv(self.config.PRODUCTS_FILE, index=False)
        (self.root / "engraved_bead_families.json").write_text(json.dumps({"version": "shared-beads-v1",
            "forecast_mode": "ordinary_tracked_source", "families": self.families}))
        return self.module("clean_products").clean_products()

    def event(self, source, quantity=40, **changes):
        return {"event_id": "cms-bead-consume:one", "action": "consume", "status": "completed",
            "occurred_at": "2026-10-20T10:00:00Z", "order_name": "#10", "quantity": quantity, "units": "physical_beads",
            "source_variant_id": source["source_variant_id"], "source_inventory_item_id": source["source_inventory_item_id"],
            "source_location_id": source["source_location_id"], "sku": source["source_sku"], **changes}

    def demand(self, orders, events, mapping=None):
        pd.DataFrame(events, columns=list(self.event(self.families[0]))).to_csv(self.root / "engraved_bead_consumption.csv", index=False)
        pd.DataFrame(orders, columns=["sku", "quantity", "order_date", "order_name", "product_name", "shopify_variant_id"]).to_csv(
            self.config.PROCESSED_DIR / "clean_orders.csv", index=False)
        expand = self.module("expand_stack_components")
        mapping = mapping if mapping is not None else pd.DataFrame([{"stack_sku": "OTHER", "stack_name": "Ordinary stack",
            "component_sku": "COMP", "component_name": "Component", "quantity_per_stack": 2}])
        with patch.object(expand, "load_stack_mapping", return_value=(mapping, pd.DataFrame())):
            return expand.expand_stack_components()

    def order(self, sku, qty, variant=""):
        return {"sku": sku, "quantity": qty, "order_date": "2026-10-20T08:00:00Z", "order_name": "#10",
                "product_name": sku, "shopify_variant_id": variant}

    def test_valid_unlisted_source_only_and_status_is_preserved(self):
        self.family()
        result = self.write_inputs()
        self.assertEqual(["SOURCE1"], result.loc[result.is_procurement_candidate, "sku"].tolist())
        self.assertEqual("unlisted", result.loc[result.sku.eq("SOURCE1"), "status"].iloc[0])
        self.assertFalse(result.loc[result.sku.ne("SOURCE1"), "is_procurement_candidate"].any())

    def test_seven_families_have_seven_candidates_not_twenty_eight(self):
        for i in range(1, 8): self.family(i)
        result = self.write_inputs()
        self.assertEqual(28, len(result))
        self.assertEqual(7, int(result.is_procurement_candidate.sum()))

    def test_ordinary_products_and_stack_eligibility_unchanged(self):
        self.family()
        for sku, status, tracked in [("ACTIVE", "active", "shopify"), ("UNLISTED", "unlisted", "shopify"),
                                     ("UNTRACKED", "active", ""), ("DRAFT", "draft", "shopify")]:
            self.products.append({"Handle": sku.lower(), "Variant SKU": sku, "Status": status,
                "Variant Inventory Tracker": tracked, "Variant Inventory Qty": 10})
        result = self.write_inputs()
        self.assertEqual({"SOURCE1", "ACTIVE"}, set(result.loc[result.is_procurement_candidate, "sku"]))

    def test_inactive_unapproved_and_drifted_sources_are_excluded(self):
        self.family(1, approved=False)
        self.family(2, status="pending_validation")
        self.family(3)
        self.products[8]["Inventory Item ID"] = "gid://shopify/InventoryItem/99999"
        self.assertFalse(self.write_inputs().is_procurement_candidate.any())

    def test_duplicate_or_missing_source_skus_are_excluded(self):
        self.family()
        self.products[1]["Variant SKU"] = "SOURCE1"
        self.assertFalse(self.write_inputs().is_procurement_candidate.any())
        self.products[0]["Variant SKU"] = ""
        self.assertFalse(self.write_inputs().is_procurement_candidate.any())

    def test_temporarily_tracked_multi_bead_products_are_never_candidates(self):
        self.family()
        for row in self.products[1:]: row["Variant Inventory Tracker"] = "shopify"; row["Status"] = "active"
        self.assertEqual(["SOURCE1"], self.write_inputs().query("is_procurement_candidate").sku.tolist())

    def test_identified_but_incomplete_bead_products_are_not_ordinary_candidates(self):
        self.family()
        self.products.append({"Handle": "invalid", "Variant SKU": "INVALID", "Status": "active",
                              "Tags": "engraved_beads_unlisted", "Variant Inventory Tracker": "shopify", "Variant Inventory Qty": 100})
        self.assertEqual(["SOURCE1"], self.write_inputs().query("is_procurement_candidate").sku.tolist())

    def test_input_downloader_requests_source_metadata_and_consumption_alongside_existing_feeds(self):
        downloader = self.module("fetch_cms_inputs")
        downloads = []
        with patch.dict("os.environ", {"LEIGH_CMS_ANALYTICS_BASE_URL": "https://fixture.invalid",
                "LEIGH_CMS_ANALYTICS_TOKEN": "test-only", "LEIGH_CMS_ANALYTICS_FROM": "2026-10-01",
                "LEIGH_CMS_ANALYTICS_TO": "2026-10-29", "PROCUREMENT_RUN_UUID": "fixture-run"}), patch.object(
                downloader, "_download", side_effect=lambda url, token, path: downloads.append((url, path.name))):
            self.assertTrue(downloader.fetch_cms_inputs())
        self.assertEqual(8, len(downloads))
        self.assertIn("engraved_bead_families.json", [name for _, name in downloads])
        self.assertIn("engraved_bead_consumption.csv", [name for _, name in downloads])

    def test_physical_consumption_not_member_order_weights_and_no_native_double_count(self):
        source = self.family()
        self.write_inputs()
        # Deliberately unrelated member quantity: Python must use the 40 measured CMS units, not multiply 999.
        rows = self.demand([self.order("MEMBER1-4", 999), self.order("SOURCE1", 3, source["source_variant_id"])],
                           [self.event(source), self.event(source)])
        self.assertEqual(43, int(rows.loc[rows.demand_sku.eq("SOURCE1"), "demand_quantity"].sum()))
        self.assertNotIn("MEMBER1-4", set(rows.demand_sku))
        pd.DataFrame(columns=["date", "sku", "availability_known", "was_available"]).to_csv(self.config.PROCESSED_DIR / "availability_calendar.csv", index=False)
        daily = self.module("build_daily_sales").build_daily_sales()
        self.assertEqual({"SOURCE1"}, set(daily.sku))
        self.assertEqual(43, int(daily.units_sold.sum()))

    def test_stock_adjustments_reservations_restocks_and_pending_consumption_are_not_sales(self):
        source = self.family(); self.write_inputs()
        events = [self.event(source, 2)]
        for action in ["reserve", "release", "restock", "correction", "receipt", "transfer", "safety_stock", "reversal"]:
            events.append(self.event(source, 100, action=action, event_id=action))
        events.append(self.event(source, 100, status="pending", event_id="pending"))
        rows = self.demand([], events)
        self.assertEqual(2, int(rows.demand_quantity.sum()))

    def test_existing_stack_expansion_is_unchanged_and_source_components_are_not_counted_twice(self):
        source = self.family()
        self.products += [{"Handle": "ordinary-stack", "Variant SKU": "STACK", "Status": "active"},
                          {"Handle": "component", "Variant SKU": "COMP", "Status": "active", "Variant Inventory Tracker": "shopify", "Variant Inventory Qty": 10}]
        self.write_inputs()
        products = pd.read_csv(self.config.PROCESSED_DIR / "clean_products.csv")
        products.loc[products.sku.eq("STACK"), "is_stack"] = True
        products.to_csv(self.config.PROCESSED_DIR / "clean_products.csv", index=False)
        mapping = pd.DataFrame([{"stack_sku": "STACK", "stack_name": "Stack", "component_sku": sku,
                                 "component_name": sku, "quantity_per_stack": 2} for sku in ["SOURCE1", "COMP"]])
        rows = self.demand([self.order("STACK", 3)], [self.event(source, 6)], mapping)
        self.assertEqual(6, int(rows.loc[rows.demand_sku.eq("COMP"), "demand_quantity"].sum()))
        self.assertEqual(6, int(rows.loc[rows.demand_sku.eq("SOURCE1"), "demand_quantity"].sum()))

    def test_missing_consumption_feed_does_not_silently_forecast_incomplete_history(self):
        self.family(); products = self.write_inputs()
        with self.assertRaisesRegex(ValueError, "consumption feed is required"):
            self.module("source_inventory").source_consumption_demand(pd.DataFrame(columns=["demand_sku", "demand_source"]), products)

    def test_conflicting_duplicate_consumption_is_rejected(self):
        source = self.family(); self.write_inputs()
        with self.assertRaisesRegex(ValueError, "Conflicting duplicate"):
            self.demand([], [self.event(source, 40), self.event(source, 41)])

    def test_native_source_sku_changes_use_variant_identity_not_old_sku_guessing(self):
        source = self.family(); self.write_inputs()
        rows = self.demand([self.order("OLD-SOURCE-SKU", 3, source["source_variant_id"])], [])
        self.assertEqual(["SOURCE1"], rows.demand_sku.tolist())
        self.assertEqual(3, int(rows.demand_quantity.sum()))


if __name__ == "__main__":
    unittest.main(testRunner=unittest.TextTestRunner(stream=sys.stdout))
