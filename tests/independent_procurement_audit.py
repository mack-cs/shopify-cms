"""Read production pipeline functions; write fixtures only into a temporary directory."""
import importlib.util
import sys
import tempfile
import types
import unittest
from pathlib import Path
sys.dont_write_bytecode = True
PIPELINE = Path(r'D:\python_projects\leigh_ml_procurement_v1_with_incoming_stock')
import pandas as pd

class ProcurementAudit(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        package = types.ModuleType('src')
        package.__path__ = [str(PIPELINE / 'src')]
        sys.modules['src'] = package
        config = types.ModuleType('src.config')
        config.PROCESSED_DIR = self.root
        config.PRODUCTS_FILE = self.root / 'products.csv'
        config.STACK_COMPONENTS_FILE = self.root / 'stacks.csv'
        sys.modules['src.config'] = config
        self.config = config

    def load(self, name):
        spec = importlib.util.spec_from_file_location('audit_' + name, PIPELINE / 'src' / (name + '.py'))
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_unlisted_quantity_tag_reaches_candidates(self):
        pd.DataFrame([{'Handle':'engraved','Variant SKU':'BEAD','Status':'unlisted',
            'Tags':'4-engraved-beads','Variant Inventory Tracker':'shopify','Variant Inventory Qty':100}]
        ).to_csv(self.config.PRODUCTS_FILE,index=False)
        products = self.load('clean_products').clean_products()
        self.assertTrue(bool(products.iloc[0]['is_procurement_candidate']))

    def test_ten_four_bead_products_produce_forty_demand_units(self):
        pd.DataFrame([{'sku':'BEAD','is_stack':False,'tags':'4-engraved-beads'}]).to_csv(self.root/'clean_products.csv',index=False)
        pd.DataFrame([{'sku':'BEAD','quantity':10,'order_date':'2026-10-01','product_name':'Bead'}]).to_csv(self.root/'clean_orders.csv',index=False)
        module = self.load('expand_stack_components')
        mapping = pd.DataFrame([{'stack_sku':'OTHER','stack_name':'Other', 'component_sku':'COMP',
            'component_name':'Component','quantity_per_stack':1}])
        module.load_stack_mapping = lambda: (mapping, pd.DataFrame())
        demand = module.expand_stack_components()
        self.assertEqual(40,int(demand.loc[demand['demand_sku'].eq('BEAD'),'demand_quantity'].sum()))

if __name__ == '__main__':
    unittest.main()