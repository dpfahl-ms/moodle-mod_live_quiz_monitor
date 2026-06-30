@quiz_livequizmonitor @mod @quiz @report
Feature: Live quiz monitor report
  In order to monitor quiz participation live
  As a teacher
  I need to open the Live Monitor report and see student statuses

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email           |
      | teacher1 | Terry     | Teacher  | teacher1@test.com |
      | student1 | Sam       | Student  | student1@test.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activity" exists:
      | course | activity | name   |
      | C1     | quiz     | Quiz 1 |
    And I log in as "teacher1"

  Scenario: Teacher opens Live Monitor report
    When I am on the "Quiz 1" "quiz activity" page
    And I navigate to "Reports" in current page administration
    And I follow "Live Monitor"
    Then I should see "Live Monitor"
    And I should see "Sam Student"
