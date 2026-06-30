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
      | student2 | Alex      | Other    | student2@test.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activity" exists:
      | course | activity | name   | timelimit |
      | C1     | quiz     | Quiz 1 | 600       |
    And the following "questions" exist:
      | questioncategory | qtype     | name  | questiontext    |
      | Test             | shortanswer | SA1 | What is 2+2?    |
    And quiz "Quiz 1" contains the following questions:
      | question |
      | SA1      |
    And I log in as "teacher1"

  Scenario: Teacher opens Live Monitor report
    When I am on the "Quiz 1" "quiz activity" page
    And I navigate to "Reports" in current page administration
    And I follow "Live Monitor"
    Then I should see "Live Monitor"
    And I should see "Sam Student"
    And I should see "Not started"
    And I should see "In progress"
    And I should see "Completed"
    And "div.livequizmonitor-progress .progress-bar" "css_element" should exist

  @javascript
  Scenario: Search narrows the student list
    When I am on the "Quiz 1" "quiz activity" page
    And I navigate to "Reports" in current page administration
    And I follow "Live Monitor"
    And I set the field "Search students…" to "Sam"
    Then I should see "Sam Student"
    And I should not see "Alex Other"

  @javascript
  Scenario: Clear filters restores the full student list
    When I am on the "Quiz 1" "quiz activity" page
    And I navigate to "Reports" in current page administration
    And I follow "Live Monitor"
    And I set the field "Search students…" to "Sam"
    And I click on "Clear filters" "button"
    Then I should see "Sam Student"
    And I should see "Alex Other"

  @javascript
  Scenario: Status filter shows only in-progress students
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz now"
    And I log in as "teacher1"
    When I am on the "Quiz 1" "quiz activity" page
    And I navigate to "Reports" in current page administration
    And I follow "Live Monitor"
    And I click on "In progress (1)" "button"
    Then I should see "Sam Student"
    And I should not see "Alex Other"

  @javascript @extend
  Scenario: Individual extend modal opens from row action menu
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz now"
    And I log in as "teacher1"
    When I am on the "Quiz 1" "quiz activity" page
    And I navigate to "Reports" in current page administration
    And I follow "Live Monitor"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    And I click on "Extend time" "link"
    Then I should see "Extend quiz time"
    And I should see "Add time"

  @javascript @extend
  Scenario: Bulk extend button opens modal when students are in progress
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz now"
    And I log in as "teacher1"
    When I am on the "Quiz 1" "quiz activity" page
    And I navigate to "Reports" in current page administration
    And I follow "Live Monitor"
    And I click on "Extend time" "button"
    Then I should see "Extend quiz time"
    And I should see "Add time"

  @javascript @notes
  Scenario: Teacher adds and edits a student note
    When I am on the "Quiz 1" "quiz activity" page
    And I navigate to "Reports" in current page administration
    And I follow "Live Monitor"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    And I click on "Add note" "link"
    And I set the field "Note for Sam Student" to "Requested bathroom break"
    And I click on "Save" "button"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    Then I should see "Edit note" "link"
    When I click on "Edit note" "link"
    Then the field "Note for Sam Student" should have the value "Requested bathroom break"
