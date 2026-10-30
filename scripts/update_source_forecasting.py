"""Prepare or apply narrowly scoped source-only integration changes. Never runs the pipeline."""
from pathlib import Path
import argparse
import hashlib
import shutil

CMS = Path(__file__).resolve().parents[1]
PIPELINE = Path(r"D:\python_projects\leigh_ml_procurement_v1_with_incoming_stock")
STAGE = CMS / "storage/framework/testing/source-forecast-update"


def prepare():
    (STAGE / "src").mkdir(parents=True, exist_ok=True)
    modifications = {
        "src/clean_products.py": [
            ("from src.utils import normalise_handle, normalise_sku, to_number", "from src.utils import normalise_handle, normalise_sku, to_number\nfrom src.source_inventory import product_roles"),
            ('    products["is_active"]', '    products = pd.concat([products, product_roles(raw)], axis=1)\n\n    products["is_active"]', 1),
            ('    valid["is_procurement_candidate"] = valid["candidate_reason"].eq("eligible")',
             '    # Family approval grants an unlisted exception only to the ordinary tracked source.\n'
             '    source = valid["is_shared_bead_source"] & valid["status"].isin(["active", "unlisted"])\n'
             '    source &= valid["inventory_tracked"] & valid["inventory_qty"].notna() & ~valid["is_duplicate_sku"] & ~valid["is_stack"]\n'
             '    valid.loc[valid["is_engraved_bead"], "candidate_reason"] = "not_approved_bead_source"\n'
             '    valid.loc[source, "candidate_reason"] = "eligible"\n'
             '    valid["is_procurement_candidate"] = valid["candidate_reason"].eq("eligible")'),
        ],
        "src/clean_orders.py": [
            ('        "order_id": raw.get(', '        "shopify_variant_id": raw.get("Variant id", pd.Series("", index=raw.index)).fillna("").astype(str).str.strip(),\n        "order_id": raw.get('),
        ],
        "src/expand_stack_components.py": [
            ('from src.utils import normalise_sku, normalise_sku_series', 'from src.utils import normalise_sku, normalise_sku_series\nfrom src.source_inventory import source_consumption_demand'),
            ('    combined.to_csv(PROCESSED_DIR / "component_demand_order_lines.csv", index=False)',
             '    combined = source_consumption_demand(combined, products)\n    combined.to_csv(PROCESSED_DIR / "component_demand_order_lines.csv", index=False)'),
        ],
        "src/build_daily_sales.py": [
            ('orders["demand_source"].eq("direct_sale")', 'orders["demand_source"].isin(["direct_sale", "cms_consumption"])'),
        ],
        "src/fetch_cms_inputs.py": [
            ('        "orders.csv":', '        "engraved_bead_families.json": "/api/analytics/engraved-bead-families.json",\n        "engraved_bead_consumption.csv": f"/api/analytics/engraved-bead-consumption.csv?{dated}",\n        "orders.csv":'),
            ('            "Accept": "text/csv",', '            "Accept": "application/json" if destination.suffix == ".json" else "text/csv",'),
            ('            if content_type not in {"text/csv", "application/csv"}:', '            allowed = {"application/json"} if destination.suffix == ".json" else {"text/csv", "application/csv"}\n            if content_type not in allowed:'),
        ],
        "src/publish_cms_predictions.py": [
            ('        "matching": quality,', '        "shared_bead_contract_version": "shared-beads-v1",\n        "shared_bead_forecast_mode": "ordinary_tracked_source",\n        "shared_bead_demand_source": "CONFIRMED_CMS_CONSUMPTION_PLUS_NATIVE_SOURCE_SALES",\n        "matching": quality,'),
        ],
    }
    manifest = []
    for name, edits in modifications.items():
        content = (PIPELINE / name).read_text(encoding="utf-8-sig")
        original = content
        for edit in edits:
            old, new = edit[:2]
            count = edit[2] if len(edit) == 3 else -1
            if old not in content:
                raise RuntimeError(f"Unexpected source in {name}; no operational files have been written")
            content = content.replace(old, new, count)
        (STAGE / name).write_text(content, encoding="utf-8")
        manifest.append((name, hashlib.sha256((PIPELINE / name).read_bytes()).hexdigest()))
        (STAGE / (name + ".before")).write_text(original, encoding="utf-8")
    shutil.copyfile(CMS / "integrations/procurement/source_inventory.py", STAGE / "src/source_inventory.py")
    (STAGE / "manifest.txt").write_text("\n".join(name + " " + digest for name, digest in manifest), encoding="utf-8")
    (STAGE / "forecast-before.sha256").write_text(hashlib.sha256((PIPELINE / "src/forecast.py").read_bytes()).hexdigest())
    print("Prepared six targeted pipeline edits plus the source input module; no forecasting formula changes.")


def apply():
    names = []
    for line in (STAGE / "manifest.txt").read_text().splitlines():
        name, digest = line.split()
        if hashlib.sha256((PIPELINE / name).read_bytes()).hexdigest() != digest:
            raise RuntimeError(f"Concurrent changes in {name}; refusing to overwrite")
        names.append(name)
    if hashlib.sha256((PIPELINE / "src/forecast.py").read_bytes()).hexdigest() != (STAGE / "forecast-before.sha256").read_text():
        raise RuntimeError("forecast.py changed since inspection; review required")
    for name in names + ["src/source_inventory.py"]:
        shutil.copyfile(STAGE / name, PIPELINE / name)
    print("Applied source input changes. forecast.py, models, raw/processed data, settings and publication flags were untouched.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    apply() if args.apply else prepare()
