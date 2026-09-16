@mod @mod_kanban
Feature: Kanban permissions and WIP enforcement
  In order to manage project tasks safely
  As a teacher or student in the right group
  I need to see only valid cards and be blocked by server-side WIP limits

  Scenario: A student in group A moves a card in the same group successfully
    Given I am logged in as a student in group "Group A"
    And I have a kanban activity with columns "To Do" and "In Progress"
    And a card exists in "To Do" for group "Group A"
    When I move the card to "In Progress"
    Then the card should be in the "In Progress" column

  Scenario: A student in group B cannot move a card from group A
    Given I am logged in as a student in group "Group B"
    And I have a kanban activity with columns "To Do" and "In Progress"
    And a card exists in "To Do" for group "Group A"
    When I try to move that card
    Then I should be denied access

  Scenario: Creating a card beyond the WIP limit is rejected server-side
    Given I am logged in as a student in group "Group A"
    And the "To Do" column has a WIP limit of 1
    And the column already contains one card
    When I create another card in the same column
    Then the server should reject the request with an error

  Scenario: A teacher creates a kanban activity with groups and tasks
    Given I am logged in as a teacher
    And I create a kanban activity in the course
    And I create group "Group A" and group "Group B"
    And I assign two students to different groups
    When I add a card to group "Group A"
    Then group "Group A" can see and move it
    And group "Group B" cannot see or operate on it
