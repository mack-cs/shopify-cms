"""Regression checks for the retired Laravel adapter's actual source-only replacement."""
import sys
import unittest
import test_source_forecasting_integration as source_tests


class SharedBeadProcurementTest(unittest.TestCase):
    def setUp(self):
        self.fixture = source_tests.SourceForecastingTest()
        self.addCleanup(self.fixture.doCleanups)
        self.fixture.setUp()

    def test_only_seven_tracked_sources(self):
        self.fixture.test_seven_families_have_seven_candidates_not_twenty_eight()

    def test_physical_consumption_not_weighted_orders(self):
        self.fixture.test_physical_consumption_not_member_order_weights_and_no_native_double_count()

    def test_ordinary_products_unchanged(self):
        self.fixture.test_ordinary_products_and_stack_eligibility_unchanged()

    def test_stacks_not_counted_twice(self):
        self.fixture.test_existing_stack_expansion_is_unchanged_and_source_components_are_not_counted_twice()

    def test_non_demand_movements_ignored(self):
        self.fixture.test_stock_adjustments_reservations_restocks_and_pending_consumption_are_not_sales()


if __name__ == "__main__":
    unittest.main(testRunner=unittest.TextTestRunner(stream=sys.stdout))
