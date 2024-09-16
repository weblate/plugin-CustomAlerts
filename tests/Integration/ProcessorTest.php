<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CustomAlerts\tests\Integration;

use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Date;
use Piwik\Option;
use Piwik\Plugins\CustomAlerts\Model;
use Piwik\Plugins\CustomAlerts\Processor;
use Piwik\Scheduler\RetryableException;
use Piwik\Tests\Framework\Fixture;

class CustomProcessor extends Processor
{
    public function __construct()
    {
        $processedReport = StaticContainer::get('Piwik\Plugins\API\ProcessedReport');
        $validator       = StaticContainer::get('Piwik\Plugins\CustomAlerts\Validator');
        parent::__construct($processedReport, $validator);
    }

    public function filterDataTable($dataTable, $condition, $value)
    {
        parent::filterDataTable($dataTable, $condition, $value);
    }

    public function aggregateToOneValue($dataTable, $metric, $filterCond = '', $filterValue = '')
    {
        return parent::aggregateToOneValue($dataTable, $metric, $filterCond, $filterValue);
    }

    public function processAlert($alert, $idSite)
    {
        parent::processAlert($alert, $idSite);
    }

    public function shouldBeTriggered($alert, $metricOne, $metricTwo)
    {
        return parent::shouldBeTriggered($alert, $metricOne, $metricTwo);
    }
}

/**
 * @group CustomAlerts
 * @group ProcessorTest
 * @group Plugins
 */
class ProcessorTest extends BaseTest
{
    /**
     * @var CustomProcessor
     */
    private $processor;

    /**
     * @var Model
     */
    public $alertModel;

    /**
     * @param Fixture $fixture
     */
    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);
        $fixture->createSuperUser = true;
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->processor = new CustomProcessor();

        $this->alertModel = new Model();
    }

    public function test_filterDataTable_Condition_MatchesAny()
    {
        $this->assertFilterResult('matches_any', '3test', array(10, 33, 90, 65, 67, 100));
    }

    private function assertFilterResult($condition, $filterValue, $resultedVisits)
    {
        $dataTable = $this->getDataTable();

        $this->processor->filterDataTable($dataTable, $condition, $filterValue);

        $this->assertEquals(count($resultedVisits), $dataTable->getRowsCount());

        $rows = $dataTable->getRows();
        foreach ($resultedVisits as $resultedVisit) {
            $row = array_shift($rows);
            $this->assertEquals($resultedVisit, $row->getColumn('visits'));
        }
    }

    private function getDataTable()
    {
        $dataTable = new DataTable();
        $dataTable->addRowsFromArray(array(
            array(Row::COLUMNS => array('label' => 'ten', 'visits' => 10)),
            array(Row::COLUMNS => array('label' => '3test', 'visits' => 33)),
            array(Row::COLUMNS => array('label' => 'ninety', 'visits' => 90)),
            array(Row::COLUMNS => array('label' => '3test', 'visits' => 65)),
            array(Row::COLUMNS => array('label' => '3teste', 'visits' => 67)),
            array(Row::COLUMNS => array('label' => 'hundred', 'visits' => 100))
        ));

        return $dataTable;
    }

    public function test_filterDataTable_Condition_MatchesExactly()
    {
        $this->assertFilterResult('matches_exactly', '3test', array(33, 65));
        $this->assertFilterResult('matches_exactly', 'ninety', array(90));
        $this->assertFilterResult('matches_exactly', 'NoneMatChIng', array());
    }

    public function test_filterDataTable_MatchesExactlyIntegration()
    {
        // Use yesterday's date so that the archiving shows as complete for the report
        $date = Date::yesterday();

        $t = Fixture::getTracker($this->idSite, $date->getDatetime(), $defaultInit = true);
        $t->enableBulkTracking();

        $t->setUrlReferrer('http://www.google.com.vn/url?sa=t&rct=j&q=%3C%3E%26%5C%22the%20pdo%20extension%20is%20required%20for%20this%20adapter%20but%20the%20extension%20is%20not%20loaded&source=web&cd=4&ved=0FjAD&url=http%3A%2F%2Fforum.piwik.org%2Fread.php%3F2%2C1011&ei=y-HHAQ&usg=AFQjCN2-nt5_GgDeg&cad=rja');
        $t->setUrl('http://example.org/%C3%A9%C3%A9%C3%A9%22%27...%20%3Cthis%20is%20cool%3E!');
        $t->doTrackPageView('incredible title! <>,;');

        $t->setForceVisitDateTime($date->addHour(.1)->getDatetime());
        $t->setUrl('http://example.org/dir/file.php?foo=bar&foo2=bar');
        $t->setPerformanceTimings(0, 123, 234, 345, 456, 567);
        $t->doTrackPageView('incredible title! <>,;');

        $t->setForceVisitDateTime($date->addHour(.2)->getDatetime());
        $t->setUrl('http://example.org/dir/file/xyz.php?foo=bar&foo2=bar');
        $t->doTrackPageView('incredible title! <>,;');

        $t->setForceVisitDateTime($date->addHour(.2)->getDatetime());
        $t->setUrl('http://example.org/what-is-piwik');
        $t->doTrackPageView('incredible title! <>,;');

        $t->setForceVisitDateTime($date->addHour(.3)->getDatetime());
        $t->setUrl('http://example.org/dir/file.php?foo=bar&foo2=bar');
        $t->doTrackPageView('incredible title! <>,;');

        Fixture::checkBulkTrackingResponse($t->doBulkTrack());

        // for some reasons @dataProvider results in an "Mysql::getProfiler() undefined method" error
        $assertions = array(
            array('nb_hits', 'what-is-piwik', 1),
            // label does always start with leading slash
            array('nb_hits', '/what-is-piwik', 1),
            array('nb_hits', 'foo', 3),
            array('nb_visits', 'foo', 2),
            array('nb_hits', 'i', 5),
            array('nb_hits', 'foo2=bar', 3),
            array('nb_hits', '/', 5),
            array('nb_hits', 'foo=bar&foo2=bar', 3),
            array('nb_hits', 'php?foo=bar&foo2=bar', 3),
            array('nb_hits', 'file.php?foo=bar&foo2=bar', 2),
            array('nb_hits', 'dir/file.php?foo=bar&foo2=bar', 2),
            array('nb_hits', 'dir', 3),
            array('avg_time_server', 'dir/file.php?foo=bar&foo2=bar', 0.123),

            // sometimes 0 sometimes 360 sometimes even 7 (?) - random code here
            // array('avg_time_on_page', 'dir/file.php?foo=bar&foo2=bar', 0),
            array('bounce_rate', 'php?foo=bar', 0)
        );

        foreach ($assertions as $assert) {
            $alert = array(
                'report'           => 'Actions_getPageUrls',
                'metric'           => $assert[0],
                'period'           => 'day',
                'report_condition' => 'contains',
                'report_matched'   => Common::sanitizeInputValue($assert[1])
            );

            $value = $this->processor->getValueForAlertInPast($alert, $this->idSite, 1);

            $this->assertEquals($assert[2], $value, $assert[0] . ':' . $assert[1] . ' should return value ' . $assert[2] . ' but returns ' . $value);
        }
    }

    public function testGetValueForAlertInPastIncompleteArchive()
    {
        // Use today's date so that the archiving shows as incomplete for the report
        $date = Date::today();

        $t = Fixture::getTracker($this->idSite, $date->getDatetime(), $defaultInit = true);
        $t->enableBulkTracking();

        $t->setUrlReferrer('http://www.google.com.vn/url?sa=t&rct=j&q=%3C%3E%26%5C%22the%20pdo%20extension%20is%20required%20for%20this%20adapter%20but%20the%20extension%20is%20not%20loaded&source=web&cd=4&ved=0FjAD&url=http%3A%2F%2Fforum.piwik.org%2Fread.php%3F2%2C1011&ei=y-HHAQ&usg=AFQjCN2-nt5_GgDeg&cad=rja');
        $t->setUrl('http://example.org/%C3%A9%C3%A9%C3%A9%22%27...%20%3Cthis%20is%20cool%3E!');
        $t->doTrackPageView('incredible title! <>,;');

        $t->setForceVisitDateTime($date->addHour(.1)->getDatetime());
        $t->setUrl('http://example.org/dir/file.php?foo=bar&foo2=bar');
        $t->setPerformanceTimings(0, 123, 234, 345, 456, 567);
        $t->doTrackPageView('incredible title! <>,;');

        $t->setForceVisitDateTime($date->addHour(.2)->getDatetime());
        $t->setUrl('http://example.org/dir/file/xyz.php?foo=bar&foo2=bar');
        $t->doTrackPageView('incredible title! <>,;');

        $t->setForceVisitDateTime($date->addHour(.2)->getDatetime());
        $t->setUrl('http://example.org/what-is-piwik');
        $t->doTrackPageView('incredible title! <>,;');

        $t->setForceVisitDateTime($date->addHour(.3)->getDatetime());
        $t->setUrl('http://example.org/dir/file.php?foo=bar&foo2=bar');
        $t->doTrackPageView('incredible title! <>,;');

        Fixture::checkBulkTrackingResponse($t->doBulkTrack());

        $alert = [
            'name'             => 'Test alert name',
            'report'           => 'Actions_getPageUrls',
            'metric'           => 'nb_hits',
            'period'           => 'day',
            'report_condition' => 'contains',
            'report_matched'   => 'foo'
        ];

        // Should just return no data if the archive status isn't present
        $result = $this->processor->getValueForAlertInPast($alert, $this->idSite, 1);
        $this->assertNull($result);

        // Should throw an exception if the archive state is incomplete
        $isNewEnoughMatomo = version_compare(\Piwik\Version::VERSION, '5.1.0-b1', '>=');
        if ($isNewEnoughMatomo) {
            $this->expectException(RetryableException::class);
        }
        $value = $this->processor->getValueForAlertInPast($alert, $this->idSite, 0);

        if (!$isNewEnoughMatomo) {
            $this->assertEquals(3, $value, $alert['metric'] . ':' . $alert['report_matched'] . ' should return value 3 but returns ' . $value);
        }
    }

    public function test_filterDataTable_Condition_DoesNotMatchExactly()
    {
        $this->assertFilterResult('does_not_match_exactly', '3test', array(10, 90, 67, 100));
        $this->assertFilterResult('does_not_match_exactly', 'ninety', array(10, 33, 65, 67, 100));
        $this->assertFilterResult('does_not_match_exactly', 'AllMatChIng', array(10, 33, 90, 65, 67, 100));
    }

    public function test_filterDataTable_Condition_MatchesRegex()
    {
        $this->assertFilterResult('matches_regex', '3test', array(33, 65, 67));
        $this->assertFilterResult('matches_regex', '3te', array(33, 65, 67));
        $this->assertFilterResult('matches_regex', '(.*t)', array(10, 33, 90, 65, 67));
    }

    public function test_filterDataTable_Condition_DoesNotMatchRegex()
    {
        $this->assertFilterResult('does_not_match_regex', '3test', array(10, 90, 100));
        $this->assertFilterResult('does_not_match_regex', '3te', array(10, 90, 100));
        $this->assertFilterResult('does_not_match_regex', '(.*t)', array(100));
    }

    public function test_filterDataTable_Condition_Contains()
    {
        $this->assertFilterResult('contains', '3test', array(33, 65, 67));
        $this->assertFilterResult('contains', '3te', array(33, 65, 67));
        $this->assertFilterResult('contains', 'ninety', array(90));
        $this->assertFilterResult('contains', 'NoneMatChIng', array());
    }

    public function test_filterDataTable_Condition_DoesNotContain()
    {
        $this->assertFilterResult('does_not_contain', '3test', array(10, 90, 100));
        $this->assertFilterResult('does_not_contain', '3te', array(10, 90, 100));
        $this->assertFilterResult('does_not_contain', 'ninety', array(10, 33, 65, 67, 100));
        $this->assertFilterResult('does_not_contain', 'AllMatching', array(10, 33, 90, 65, 67, 100));
    }

    public function test_filterDataTable_Condition_StartsWith()
    {
        $this->assertFilterResult('starts_with', '3test', array(33, 65, 67));
        $this->assertFilterResult('starts_with', '3te', array(33, 65, 67));
        $this->assertFilterResult('starts_with', 'ninety', array(90));
        $this->assertFilterResult('starts_with', 't', array(10));
        $this->assertFilterResult('starts_with', 'NoneMatChIng', array());
    }

    public function test_filterDataTable_Condition_DoesNotStartWith()
    {
        $this->assertFilterResult('does_not_start_with', '3test', array(10, 90, 100));
        $this->assertFilterResult('does_not_start_with', '3te', array(10, 90, 100));
        $this->assertFilterResult('does_not_start_with', 'ninety', array(10, 33, 65, 67, 100));
        $this->assertFilterResult('does_not_start_with', 't', array(33, 90, 65, 67, 100));
        $this->assertFilterResult('does_not_start_with', 'AllMatchIng', array(10, 33, 90, 65, 67, 100));
    }

    public function test_filterDataTable_Condition_EndsWith()
    {
        $this->assertFilterResult('ends_with', 't', array(33, 65));
        $this->assertFilterResult('ends_with', 'n', array(10));
        $this->assertFilterResult('ends_with', 'ninety', array(90));
        $this->assertFilterResult('ends_with', 'NoneMatChIng', array());
    }

    public function test_filterDataTable_Condition_DoesNotEndWith()
    {
        $this->assertFilterResult('does_not_end_with', 't', array(10, 90, 67, 100));
        $this->assertFilterResult('does_not_end_with', 'n', array(33, 90, 65, 67, 100));
        $this->assertFilterResult('does_not_end_with', 'ninety', array(10, 33, 65, 67, 100));
        $this->assertFilterResult('does_not_end_with', 'NoneMatChIng', array(10, 33, 90, 65, 67, 100));
    }

    public function test_filterDataTable_shouldThrowException_IfConditionIsInvalid()
    {
        $this->expectException(\Exception::class);

        $this->assertFilterResult('noTValIdConDitiOn', 't', array());
    }

    public function test_getMetricFromTable()
    {
        $this->assertAggregateToOneValue('visits', '', '', 365);
        $this->assertAggregateToOneValue('visits', 'contains', '3test', 165);
        $this->assertAggregateToOneValue('visits', 'matches_exactly', 'ten', 10);
        $this->assertAggregateToOneValue('visits', 'matches_exactly', 'NonE', null);
    }

    private function assertAggregateToOneValue($metric, $filterCondition, $filterValue, $result)
    {
        $dataTable = $this->getDataTable();

        $metric = $this->processor->aggregateToOneValue($dataTable, $metric, $filterCondition, $filterValue);

        $this->assertEquals($result, $metric);
    }

    public function test_getMetricFromTable_invalidMetric()
    {
        $this->assertAggregateToOneValue('NotValidMeTriC', '', '', null);
    }

    public function test_shouldBeTriggered_GreaterThan()
    {
        $this->assertShouldBeTriggered('greater_than', 20, 30, null);
        $this->assertShouldBeTriggered('greater_than', 20, 30, 30);
        $this->assertShouldBeTriggered('greater_than', 20, 30, 15);

        $this->assertShouldNotBeTriggered('greater_than', 20, 10, null);
        $this->assertShouldNotBeTriggered('greater_than', 20, 10, 30);
        $this->assertShouldNotBeTriggered('greater_than', 20, 10, 15);
    }

    private function assertShouldBeTriggered($metricCondition, $metricMatched, $metricPast1, $metricPast2)
    {
        $result = $this->shouldBeTriggered($metricCondition, $metricMatched, $metricPast1, $metricPast2);

        $this->assertTrue($result);
    }

    private function shouldBeTriggered($metricCondition, $metricMatched, $metricPast1, $metricPast2)
    {
        $alert = array(
            'metric_condition' => $metricCondition,
            'metric_matched'   => $metricMatched
        );

        return $this->processor->shouldBeTriggered($alert, $metricPast1, $metricPast2);
    }

    private function assertShouldNotBeTriggered($metricCondition, $metricMatched, $metricPast1, $metricPast2)
    {
        $result = $this->shouldBeTriggered($metricCondition, $metricMatched, $metricPast1, $metricPast2);

        $this->assertFalse($result);
    }

    public function test_shouldBeTriggered_LessThan()
    {
        $this->assertShouldBeTriggered('less_than', 20, 10, null);
        $this->assertShouldBeTriggered('less_than', 20, 10, 15);
        $this->assertShouldBeTriggered('less_than', 20, 10, 30);
        $this->assertShouldBeTriggered('less_than', 20, 0, 0);
        $this->assertShouldBeTriggered('less_than', 20, null, null);

        $this->assertShouldNotBeTriggered('less_than', 20, 30, null);
        $this->assertShouldNotBeTriggered('less_than', 20, 30, 30);
        $this->assertShouldNotBeTriggered('less_than', 20, 30, 15);
    }

    public function test_shouldBeTriggered_DecreaseMoreThan()
    {
        $this->assertShouldBeTriggered('decrease_more_than', 29, 70, 100);
        $this->assertShouldBeTriggered('decrease_more_than', 1, 70, 200);
        $this->assertShouldBeTriggered('decrease_more_than', 1, null, 200);

        $this->assertShouldNotBeTriggered('decrease_more_than', 30, 70, 100);
        $this->assertShouldNotBeTriggered('decrease_more_than', 31, 70, 100);
        $this->assertShouldNotBeTriggered('decrease_more_than', 29, 70, null);
        $this->assertShouldNotBeTriggered('decrease_more_than', 29, 70, 70);
        $this->assertShouldNotBeTriggered('decrease_more_than', 29, 100, 70);
        $this->assertShouldNotBeTriggered('decrease_more_than', 31, 100, 70);
    }

    public function test_shouldBeTriggered_IncreaseMoreThan()
    {
        $this->assertShouldBeTriggered('increase_more_than', 29, 100, 70);
        $this->assertShouldBeTriggered('increase_more_than', 1, 200, 70);
        $this->assertShouldBeTriggered('increase_more_than', 1, 200, null);

        $this->assertShouldNotBeTriggered('increase_more_than', 30, 100, 70);
        $this->assertShouldNotBeTriggered('increase_more_than', 31, 100, 70);
        $this->assertShouldNotBeTriggered('increase_more_than', 29, null, 70);
        $this->assertShouldNotBeTriggered('increase_more_than', 29, 70, 70);
        $this->assertShouldNotBeTriggered('increase_more_than', 29, 70, 100);
        $this->assertShouldNotBeTriggered('increase_more_than', 31, 70, 100);
    }

    public function test_shouldBeTriggered_PercentageDecreaseMoreThan()
    {
        $this->assertShouldBeTriggered('percentage_decrease_more_than', 15, 70, 100);
        $this->assertShouldBeTriggered('percentage_decrease_more_than', 29, 70, 100);
        $this->assertShouldBeTriggered('percentage_decrease_more_than', 29, null, 30);

        $this->assertShouldNotBeTriggered('percentage_decrease_more_than', 30, 70, 100);
        $this->assertShouldNotBeTriggered('percentage_decrease_more_than', 31, 70, 100);
        $this->assertShouldNotBeTriggered('percentage_decrease_more_than', 31, 100, 70);
        $this->assertShouldNotBeTriggered('percentage_decrease_more_than', 31, null, null);
    }

    public function test_shouldBeTriggered_PercentageIncreaseMoreThan()
    {
        $this->assertShouldBeTriggered('percentage_increase_more_than', 30, 100, 70);
        $this->assertShouldBeTriggered('percentage_increase_more_than', 41, 100, 70);
        $this->assertShouldBeTriggered('percentage_increase_more_than', 42, 100, 70);
        $this->assertShouldBeTriggered('percentage_increase_more_than', 43, 44, null);

        $this->assertShouldNotBeTriggered('percentage_increase_more_than', 43, 100, 70);
        $this->assertShouldNotBeTriggered('percentage_increase_more_than', 43, null, null);
    }

    public function test_processAlert_shouldTriggerAlertIfMatchAndRunOnlyForGivenWebsite()
    {
        $alert = $this->buildAlert();

        $methods       = array('getValueForAlertInPast', 'triggerAlert');
        $processorMock = $this->getMockBuilder('Piwik\Plugins\CustomAlerts\tests\Integration\CustomProcessor')
            ->setMethods($methods)
            ->getMock();

        $idSite = 1;
        $processorMock->expects($this->at(0))
            ->method('getValueForAlertInPast')
            ->with($this->equalTo($alert), $this->equalTo($idSite), $this->equalTo(1))
            ->will($this->returnValue(13));

        $processorMock->expects($this->at(1))
            ->method('getValueForAlertInPast')
            ->with($this->equalTo($alert), $this->equalTo($idSite), $this->equalTo(13))
            ->will($this->returnValue(10));

        $processorMock->expects($this->never())->method('triggerAlert');

        $processorMock->expects($this->exactly(2))
            ->method('getValueForAlertInPast');

        $processorMock->processAlert($alert, $idSite);

        $idSite        = 2;
        $processorMock = $this->getMockBuilder('Piwik\Plugins\CustomAlerts\tests\Integration\CustomProcessor')
            ->setMethods($methods)
            ->getMock();
        $processorMock->expects($this->at(0))
            ->method('getValueForAlertInPast')
            ->with($this->equalTo($alert), $this->equalTo($idSite), $this->equalTo(1))
            ->will($this->returnValue(15));

        $processorMock->expects($this->at(1))
            ->method('getValueForAlertInPast')
            ->with($this->equalTo($alert), $this->equalTo($idSite), $this->equalTo(13))
            ->will($this->returnValue(10));

        $processorMock->expects($this->exactly(2))
            ->method('getValueForAlertInPast');

        $processorMock->expects($this->once())
            ->method('triggerAlert')
            ->with($this->equalTo($alert), $this->equalTo($idSite), $this->equalTo(15), $this->equalTo(10));

        $processorMock->processAlert($alert, $idSite);
    }

    private function buildAlert(
        $idSites = array(1, 2),
        $report = 'MultiSites_getAll',
        $metric = 'nb_visits',
        $metricMatched = '4',
        $period = 'month',
        $comparedTo = 12
    )
    {
        return array(
            'idalert'          => 1,
            'period'           => $period,
            'id_sites'         => $idSites,
            'metric_condition' => 'increase_more_than',
            'metric_matched'   => $metricMatched,
            'report'           => $report,
            'metric'           => $metric,
            'compared_to'      => $comparedTo
        );
    }

    public function test_processAlert_shouldNotFail_IfReportDoesNotExist()
    {
        $alert = $this->buildAlert(array(1, 2), 'NotExistingReport_Action');

        $this->assertProcessNotRun($alert, array(1, 2, 3));
    }

    private function assertProcessNotRun($alert, $idSites)
    {
        $methods       = array('getValueForAlertInPast', 'triggerAlert');
        $processorMock = $this->getMockBuilder('Piwik\Plugins\CustomAlerts\tests\Integration\CustomProcessor')
            ->setMethods($methods)
            ->getMock();

        $processorMock->expects($this->never())
            ->method('getValueForAlertInPast');

        $processorMock->expects($this->never())
            ->method('triggerAlert');

        foreach ($idSites as $idSite) {
            $processorMock->processAlert($alert, $idSite);
        }
    }

    public function test_processAlert_shouldNotFail_IfMetricDoesNotBelongToTheReport()
    {
        $alert = $this->buildAlert(array(1, 2), 'MultiSites_getAll', 'not_existing_metric');

        $this->assertProcessNotRun($alert, array(1, 2, 3));
    }

    public function test_processAlert_shouldNotRun_IfNoWebsitesDefined()
    {
        $alert = $this->buildAlert(array());

        $this->assertProcessNotRun($alert, array(1, 2));
    }

    public function test_processAlert_shouldNotRun_IfWebsiteDoesNotMatch()
    {
        $alert = $this->buildAlert();

        $this->assertProcessNotRun($alert, array(99, 85));
    }

    public function test_processAlert_shouldOnlyBeTriggeredIfAlertMatches()
    {
        $alert = $this->buildAlert(array(1), 'MultiSites_getAll', 'nb_visits', '5', 'day', $comparedTo = 7);

        $methods       = array('getValueForAlertInPast', 'triggerAlert');
        $processorMock = $this->getMockBuilder('Piwik\Plugins\CustomAlerts\tests\Integration\CustomProcessor')
            ->setMethods($methods)
            ->getMock();
        $processorMock->expects($this->at(0))
            ->method('getValueForAlertInPast')
            ->with($this->equalTo($alert), $this->equalTo(1), $this->equalTo(1))
            ->will($this->returnValue(15));

        $processorMock->expects($this->at(1))
            ->method('getValueForAlertInPast')
            ->with($this->equalTo($alert), $this->equalTo(1), $this->equalTo(8))
            ->will($this->returnValue(10));

        $processorMock->expects($this->never())
            ->method('triggerAlert');

        $processorMock->processAlert($alert, 1);
    }

    public function test_shouldBeTriggered_ShouldFail_IfInvalidConditionGiven()
    {
        $this->expectException(\Exception::class);

        $this->assertShouldBeTriggered('NotExistInG', 30, 100, 70);
    }

    public function testProcessAlerts()
    {
        $alertId = $this->createAlert('TestAlert1');
        $alert = $this->alertModel->getAlert($alertId);

        $mockProcessor = $this->createPartialMock(Processor::class, ['processAlert']);
        $mockProcessor->expects($this->once())->method('processAlert')->with($this->equalTo($alert), $this->equalTo(1));

        $mockProcessor->processAlerts('day', $this->idSite);

        $this->assertEmpty(Option::get(Processor::CUSTOM_ALERTS_SCHEDULED_TASK_RETRY_OPTION_PREFIX . 'daily_1'));
    }

    private function createAlert(
        $name,
        $period = 'day',
        $idSites = null,
        $metric = 'nb_visits',
        $report = 'MultiSites_getOne',
        $login = 'superUserLogin'
    )
    {
        if (is_null($idSites)) {
            $idSites = $this->idSite;
        }
        if (!is_array($idSites)) {
            $idSites = [$idSites];
        }

        $emails       = ['test1@example.com', 'test2@example.com'];
        $phoneNumbers = ['0123456789'];

        return $this->alertModel->createAlert($name, $idSites, $login, $period, 0, $emails, $phoneNumbers, $metric, 'less_than', 5, $comparedTo = 1, $report, 'matches_exactly', 'Piwik');
    }
}
