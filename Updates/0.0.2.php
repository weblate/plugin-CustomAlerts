<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\CustomAlerts;

use Piwik\Updater;
use Piwik\Updater\Migration\Factory as MigrationFactory;
use Piwik\Updates;

/**
 */
class Updates_0_0_2 extends Updates
{
    /**
     * @var MigrationFactory
     */
    private $migration;

    public function __construct(MigrationFactory $factory)
    {
        $this->migration = $factory;
    }

    public function doUpdate(Updater $updater)
    {
        $updater->executeMigrations(__FILE__, $this->getMigrations($updater));
    }

    public function getMigrations(Updater $updater)
    {
        return array(
            $this->migration->db->changeColumn('alert', 'enable_mail', 'email_me', 'BOOLEAN NOT NULL'),
            $this->migration->db->addColumn('alert', 'additional_emails', 'TEXT', 'email_me'),
            $this->migration->db->addColumn('alert', 'phone_numbers', 'TEXT', 'additional_emails'),
        );
    }
}
