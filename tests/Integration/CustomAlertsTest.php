<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CustomAlerts\tests\Integration;

use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Option;
use Piwik\Piwik;
use Piwik\Plugins\CustomAlerts\CustomAlerts;
use Piwik\Scheduler\Task;
use Piwik\Scheduler\Timetable;

/**
 * @group CustomAlerts
 * @group CustomAlertsTest
 * @group Plugins
 */
class CustomAlertsTest extends BaseTest
{
    /**
     * @var \Piwik\Plugins\CustomAlerts\CustomAlerts
     */
    private $plugin = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->plugin = new CustomAlerts();
    }

    private function getTestTask(): Task
    {
        $schedule = \Piwik\Scheduler\Schedule\Schedule::factory('daily');
        return new Task(\Piwik\Plugins\CustomAlerts\Tasks::class, 'runAlertsDaily', 1, $schedule, \Piwik\Plugin\Tasks::NORMAL_PRIORITY);
    }

    private function checkOptionStringValue(int $expectedRetryCount = 0)
    {
        $optionString = Option::get(CustomAlerts::CUSTOM_ALERTS_CURRENT_SCHEDULED_TASK_OPTION);
        $this->assertNotEmpty($optionString);
        $optionArray = json_decode($optionString, true);
        $this->assertSame('Piwik\Plugins\CustomAlerts\Tasks.runAlertsDaily_1', $optionArray['taskName']);
        $this->assertSame($expectedRetryCount, $optionArray['retryCount']);
    }

    public function test_getSiteIdsHavingAlerts()
    {
        $siteIds = $this->plugin->getSiteIdsHavingAlerts();
        $this->assertEquals(array(), $siteIds);


        $this->createAlert('Initial1', array(), array(1));
        $siteIds = $this->plugin->getSiteIdsHavingAlerts();
        $this->assertEquals(array(1), $siteIds);


        $this->createAlert('Initial2', array(), array(1, 3));
        $siteIds = $this->plugin->getSiteIdsHavingAlerts();
        $this->assertEquals(array(1, 3), $siteIds);


        $this->createAlert('Initial3', array(), array(2));
        $siteIds = $this->plugin->getSiteIdsHavingAlerts();
        $this->assertEquals(array(1, 3, 2), $siteIds);
    }

    private function createAlert($name, $phoneNumbers, $idSites = array(1), $login = false)
    {
        $report = 'MultiSites_getOne';
        $emails = array('test1@example.com', 'test2@example.com');

        if (false === $login) {
            $login = Piwik::getCurrentUserLogin();
        }

        $id = $this->model->createAlert($name, $idSites, $login, 'week', 0, $emails, $phoneNumbers, 'nb_visits',
            'less_than', 5, $comparedTo = 7, $report, 'matches_exactly', 'Piwik');

        return $this->model->getAlert($id);
    }

    public function test_removePhoneNumberFromAllAlerts()
    {
        $alert1 = $this->createAlert('Initial1', array());
        $alert2 = $this->createAlert('Initial2', null);
        $alert3 = $this->createAlert('Initial3', array('+123445679'));
        $alert4 = $this->createAlert('Initial4', array('123445679'));
        $alert5 = $this->createAlert('Initial5', array('123445679', '2384'));
        $alert6 = $this->createAlert('Initial6', array('+123445679', '123445679'));

        $this->plugin->removePhoneNumberFromAllAlerts('+123445679');

        $this->assertOnlyPhoneNumberChanged(1, $alert1, array());
        $this->assertOnlyPhoneNumberChanged(2, $alert2, null);
        $this->assertOnlyPhoneNumberChanged(3, $alert3, array());
        $this->assertOnlyPhoneNumberChanged(4, $alert4, array('123445679'));
        $this->assertOnlyPhoneNumberChanged(5, $alert5, array('123445679', '2384'));
        $this->assertOnlyPhoneNumberChanged(6, $alert6, array('123445679'));
    }

    private function assertOnlyPhoneNumberChanged($id, $alertBefore, $phoneNumbers)
    {
        $alert = $this->model->getAlert($id);

        $this->assertSame($phoneNumbers, $alert['phone_numbers']);

        $alertBefore['phone_numbers'] = $phoneNumbers;

        $this->assertSame($alertBefore, $alert);
    }

    public function test_deleteAlertsForWebsite()
    {
        $this->createAlert('Initial1', array(), array(2));
        $this->createAlert('Initial2', array(), array(1, 2, 3));
        $this->createAlert('Initial3', array(), array(1));
        $this->createAlert('Initial4', array(), array(1, 3));
        $this->createAlert('Initial5', array(), array(2));
        $this->createAlert('Initial6', array(), array(2));

        $this->model->triggerAlert(1, 1, 99, 48, Date::now()->getDatetime());
        $this->model->triggerAlert(1, 2, 99, 48, Date::now()->getDatetime());
        $this->model->triggerAlert(2, 3, 99, 48, Date::now()->getDatetime());
        $this->model->triggerAlert(3, 2, 99, 48, Date::now()->getDatetime());

        $alerts = $this->model->getTriggeredAlerts(array(1, 2, 3), 'superUserLogin');
        $this->assertCount(4, $alerts);

        $this->plugin->deleteAlertsForSite(2);

        $alerts = $this->model->getAllAlerts();

        $this->assertCount(6, $alerts);
        $this->assertEquals($alerts[0]['id_sites'], array());
        $this->assertEquals($alerts[1]['id_sites'], array(1, 3));
        $this->assertEquals($alerts[2]['id_sites'], array(1));
        $this->assertEquals($alerts[3]['id_sites'], array(1, 3));
        $this->assertEquals($alerts[4]['id_sites'], array());
        $this->assertEquals($alerts[5]['id_sites'], array());

        $alerts = $this->model->getTriggeredAlerts(array(1, 2, 3), 'superUserLogin');
        $this->assertCount(2, $alerts);
    }

    public function test_deleteAlertsForLogin()
    {
        $this->createAlert('Initial1', array(), array(), 'testlogin1');
        $this->createAlert('Initial2', array(), array(), 'testlogin2');
        $this->createAlert('Initial3', array(), array(), 'testlogin3');
        $this->createAlert('Initial4', array(), array(), 'testlogin2');
        $this->createAlert('Initial5', array(), array(), 'testlogin2');
        $this->createAlert('Initial6', array(), array(), 'testlogin1');

        $this->plugin->deleteAlertsForLogin('testlogin2');

        $alerts = $this->model->getAllAlerts();

        $this->assertCount(3, $alerts);
        $this->assertEquals('Initial1', $alerts[0]['name']);
        $this->assertEquals('Initial3', $alerts[1]['name']);
        $this->assertEquals('Initial6', $alerts[2]['name']);
    }

    public function testStartingScheduledTask()
    {
        $this->assertEmpty(Option::get(CustomAlerts::CUSTOM_ALERTS_CURRENT_SCHEDULED_TASK_OPTION));

        $task = $this->getTestTask();
        $this->plugin->startingScheduledTask($task);

        $this->checkOptionStringValue();
    }

    public function testStartingScheduledTaskAsRetry()
    {
        $this->assertEmpty(Option::get(CustomAlerts::CUSTOM_ALERTS_CURRENT_SCHEDULED_TASK_OPTION));

        $task = $this->getTestTask();

        // Increment the retry count
        StaticContainer::get(Timetable::class)->incrementRetryCount($task->getName());

        $this->plugin->startingScheduledTask($task);

        $this->checkOptionStringValue(1);

        // Increment and check again
        StaticContainer::get(Timetable::class)->incrementRetryCount($task->getName());

        $this->plugin->startingScheduledTask($task);

        $this->checkOptionStringValue(2);
    }

    public function testEndingScheduledTask()
    {
        $this->assertEmpty(Option::get(CustomAlerts::CUSTOM_ALERTS_CURRENT_SCHEDULED_TASK_OPTION));

        $task = $this->getTestTask();

        // Create the record and confirm that it's there
        $this->plugin->startingScheduledTask($task);
        $this->checkOptionStringValue();

        // Call the method to delete the option and confirm that it has been deleted
        $this->plugin->endingScheduledTask($task);
        $this->assertEmpty(Option::get(CustomAlerts::CUSTOM_ALERTS_CURRENT_SCHEDULED_TASK_OPTION));
    }
}
