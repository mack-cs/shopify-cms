"""The original two audit reproductions, corrected for the confirmed source-only architecture.

Actual external pipeline functions are exercised; data/configuration/network are isolated.
"""
import sys
import unittest
import test_source_forecasting_integration as source_tests


class ProcurementAudit(unittest.TestCase):
    def setUp(self):
        self.fixture = source_tests.SourceForecastingTest()
        self.addCleanup(self.fixture.doCleanups)
        self.fixture.setUp()

    def test_approved_unlisted_tracked_one_bead_source_reaches_candidates(self):
        self.fixture.test_valid_unlisted_source_only_and_status_is_preserved()

    def test_confirmed_physical_consumption_reaches_source_without_native_double_count(self):
        # The CMS supplies 40 confirmed physical beads. Member order quantities are
        # deliberately unrelated; Python must neither multiply them nor forecast them.
        self.fixture.test_physical_consumption_not_member_order_weights_and_no_native_double_count()


if __name__ == "__main__":
    unittest.main(testRunner=unittest.TextTestRunner(stream=sys.stdout))
