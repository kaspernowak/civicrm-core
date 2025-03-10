<?php

/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */


namespace api\v4\Custom;

use Civi\Api4\Contact;
use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;
use api\v4\Traits\QueryCounterTrait;

/**
 * @group headless
 */
class CustomValuePerformanceTest extends CustomTestBase {

  use QueryCounterTrait;

  public function testQueryCount() {

    $this->markTestIncomplete();

    $customGroupId = CustomGroup::create(FALSE)
      ->addValue('name', 'MyContactFields')
      ->addValue('title', 'MyContactFields')
      ->addValue('extends', 'Contact')
      ->execute()
      ->first()['id'];

    CustomField::create(FALSE)
      ->addValue('label', 'FavColor')
      ->addValue('custom_group_id', $customGroupId)
      ->addValue('options', ['r' => 'Red', 'g' => 'Green', 'b' => 'Blue'])
      ->addValue('html_type', 'Select')
      ->addValue('data_type', 'String')
      ->execute();

    CustomField::create(FALSE)
      ->addValue('label', 'FavAnimal')
      ->addValue('custom_group_id', $customGroupId)
      ->addValue('html_type', 'Text')
      ->addValue('data_type', 'String')
      ->execute();

    CustomField::create(FALSE)
      ->addValue('label', 'FavLetter')
      ->addValue('custom_group_id', $customGroupId)
      ->addValue('html_type', 'Text')
      ->addValue('data_type', 'String')
      ->execute();

    CustomField::create(FALSE)
      ->addValue('label', 'FavFood')
      ->addValue('custom_group_id', $customGroupId)
      ->addValue('html_type', 'Text')
      ->addValue('data_type', 'String')
      ->execute();

    $this->beginQueryCount();

    $this->createTestRecord('Contact', [
      'first_name' => 'Red',
      'last_name' => 'Tester',
      'contact_type' => 'Individual',
      'MyContactFields.FavColor' => 'r',
      'MyContactFields.FavAnimal' => 'Sheep',
      'MyContactFields.FavLetter' => 'z',
      'MyContactFields.FavFood' => 'Coconuts',
    ]);

    Contact::get(FALSE)
      ->addSelect('display_name')
      ->addSelect('MyContactFields.FavColor.label')
      ->addSelect('MyContactFields.FavColor.weight')
      ->addSelect('MyContactFields.FavColor.is_default')
      ->addSelect('MyContactFields.FavAnimal')
      ->addSelect('MyContactFields.FavLetter')
      ->addWhere('MyContactFields.FavColor', '=', 'r')
      ->addWhere('MyContactFields.FavFood', '=', 'Coconuts')
      ->addWhere('MyContactFields.FavAnimal', '=', 'Sheep')
      ->addWhere('MyContactFields.FavLetter', '=', 'z')
      ->execute()
      ->first();

    // FIXME: This count is artificially high due to the line
    // $this->entity = Tables::getBriefName(Tables::getClassForTable($targetTable));
    // In class Joinable. TODO: Investigate why.
    // $this->assertLessThan(10, $this->getQueryCount());

  }

}
