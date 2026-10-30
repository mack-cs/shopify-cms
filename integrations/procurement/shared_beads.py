"""Retired weighted-family adapter.

Python forecasts only the approved tracked source as an ordinary product.
The operational integration lives in the external pipeline's src/source_inventory.py.
This module deliberately contains no member sales aggregation or bead multipliers.
"""
VERSION = "shared-beads-v1"
FORECAST_MODE = "ordinary_tracked_source"
