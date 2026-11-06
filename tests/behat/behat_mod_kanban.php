<?php

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Behat steps for Kanban group permissions and WIP limits.
 *
 * @package mod_kanban
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_kanban extends behat_base {

    /** @var stdClass */
    private $course;

    /** @var stdClass */
    private $module;

    /** @var stdClass */
    private $cm;

    /** @var array */
    private $groups = [];

    /** @var array */
    private $users = [];

    /** @var array */
    private $columns = [];

    /** @var int */
    private $cardid;

    /** @var int */
    private $cardcolumnbefore;

    /** @var int */
    private $cardcountbefore;

    /** @var array|null */
    private $lastajaxresult;

    /**
     * Create a separate-groups board fixture and log in as its student.
     *
     * @Given /^I am logged in as a student in group "(?P<groupname>[^"]*)"$/
     * @param string $groupname
     */
    public function i_am_logged_in_as_a_student_in_group(string $groupname): void {
        $this->create_fixture();
        $this->login_and_open_board($this->get_student_username($groupname));
    }

    /**
     * Check the board has the requested columns.
     *
     * @Given /^I have a kanban activity with columns "(?P<first>[^"]*)" and "(?P<second>[^"]*)"$/
     * @param string $first
     * @param string $second
     */
    public function i_have_a_kanban_activity_with_columns(string $first, string $second): void {
        $this->assert_column_title($first);
        $this->assert_column_title($second);
    }

    /**
     * Create a card for the specified group.
     *
     * @Given /^a card exists in "(?P<column>[^"]*)" for group "(?P<group>[^"]*)"$/
     * @param string $column
     * @param string $group
     */
    public function a_card_exists_in_column_for_group(string $column, string $group): void {
        $this->cardid = $this->create_card($column, $group, 'Behat Kanban card');
        $this->reload_board();
    }

    /**
     * Move the current card using the registered Moodle AJAX service.
     *
     * @When /^I move the card to "(?P<column>[^"]*)"$/
     * @param string $column
     */
    public function i_move_the_card_to_column(string $column): void {
        $this->lastajaxresult = $this->call_ajax('mod_kanban_move_card', [
            'cardid' => $this->cardid,
            'targetcolumnid' => $this->get_column_id($column),
            'cmid' => (int)$this->cm->id,
            'newposition' => 0,
        ]);
        if (empty($this->lastajaxresult['ok'])) {
            throw new ExpectationException('Kanban card move failed: ' .
                ($this->lastajaxresult['error'] ?? 'Unknown AJAX error'), $this->getSession());
        }
        $this->reload_board();
    }

    /**
     * Try moving a card across group boundaries.
     *
     * @When /^I try to move that card$/
     */
    public function i_try_to_move_that_card(): void {
        global $DB;

        $this->cardcountbefore = $DB->count_records('kanban_cards', ['kanbanid' => $this->module->id]);
        $this->lastajaxresult = $this->call_ajax('mod_kanban_move_card', [
            'cardid' => $this->cardid,
            'targetcolumnid' => $this->get_column_id('In Progress'),
            'cmid' => (int)$this->cm->id,
            'newposition' => 0,
        ]);
    }

    /**
     * Confirm the card is rendered in its destination column.
     *
     * @Then /^the card should be in the "(?P<column>[^"]*)" column$/
     * @param string $column
     */
    public function the_card_should_be_in_column(string $column): void {
        $cardid = (int)$this->cardid;
        $columnid = $this->get_column_id($column);
        $found = $this->getSession()->wait(10000, sprintf(
            "document.querySelector('.kanban-card-list[data-columnid=\"%d\"] .kanban-card[data-cardid=\"%d\"]') !== null",
            $columnid,
            $cardid
        ));
        if (!$found) {
            throw new ExpectationException("Card {$cardid} was not rendered in column {$column}.", $this->getSession());
        }
    }

    /**
     * Set WIP limit on the requested column.
     *
     * @Given /^the "(?P<column>[^"]*)" column has a WIP limit of (?P<limit>\d+)$/
     * @param string $column
     * @param int $limit
     */
    public function the_column_has_a_wip_limit(string $column, int $limit): void {
        global $DB;

        $DB->set_field('kanban_columns', 'wip_limit', $limit, ['id' => $this->get_column_id($column)]);
        $this->reload_board();
    }

    /**
     * Add the card which fills the WIP-limited column.
     *
     * @Given /^the column already contains one card$/
     */
    public function the_column_already_contains_one_card(): void {
        $this->cardid = $this->create_card('To Do', 'Group A', 'Existing WIP card');
        $this->reload_board();
    }

    /**
     * Attempt to create a second card through the external AJAX service.
     *
     * @When /^I create another card in the same column$/
     */
    public function i_create_another_card_in_the_same_column(): void {
        global $DB;

        $this->cardcountbefore = $DB->count_records('kanban_cards', ['kanbanid' => $this->module->id]);
        $this->lastajaxresult = $this->call_ajax('mod_kanban_create_card', [
            'kanbanid' => (int)$this->module->id,
            'columnid' => $this->get_column_id('To Do'),
            'cmid' => (int)$this->cm->id,
            'title' => 'Card beyond WIP limit',
            'description' => '',
            'duedate' => 0,
            'submissionurl' => '',
            'assignees' => [],
        ]);
    }

    /**
     * Assert the service rejected a request and did not add a card.
     *
     * @Then /^I should be denied access$/
     * @Then /^the server should reject the request with an error$/
     */
    public function the_last_request_should_be_rejected(): void {
        global $DB;

        if (!empty($this->lastajaxresult['ok'])) {
            throw new ExpectationException('The Kanban AJAX request unexpectedly succeeded.', $this->getSession());
        }
        $this->assert_card_still_exists();
    }

    /**
     * Prepare teacher fixture.
     *
     * @Given /^I am logged in as a teacher$/
     */
    public function i_am_logged_in_as_a_teacher(): void {
        $this->create_fixture();
        $this->login_and_open_board('kanban_teacher');
    }

    /**
     * Confirm the test board exists.
     *
     * @Given /^I create a kanban activity in the course$/
     */
    public function i_create_a_kanban_activity_in_the_course(): void {
        $this->assert_column_title('To Do');
        $this->assert_column_title('In Progress');
    }

    /**
     * Confirm the fixture groups exist.
     *
     * @Given /^I create group "(?P<first>[^"]*)" and group "(?P<second>[^"]*)"$/
     * @param string $first
     * @param string $second
     */
    public function i_create_groups(string $first, string $second): void {
        $this->get_group_id($first);
        $this->get_group_id($second);
    }

    /**
     * Confirm each student belongs to a different group.
     *
     * @Given /^I assign two students to different groups$/
     */
    public function i_assign_two_students_to_different_groups(): void {
        global $DB;

        foreach (['Group A', 'Group B'] as $groupname) {
            $groupid = $this->get_group_id($groupname);
            $userid = $this->users[$groupname];
            if (!$DB->record_exists('groups_members', ['groupid' => $groupid, 'userid' => $userid])) {
                throw new ExpectationException("The student is not assigned to {$groupname}.", $this->getSession());
            }
        }
    }

    /**
     * Create a card for the teacher's selected group.
     *
     * @When /^I add a card to group "(?P<group>[^"]*)"$/
     * @param string $group
     */
    public function i_add_a_card_to_group(string $group): void {
        $this->cardid = $this->create_card('To Do', $group, 'Teacher-created group card');
        $this->reload_board();
    }

    /**
     * Verify the group member sees the card and can move it.
     *
     * @Then /^group "(?P<group>[^"]*)" can see and move it$/
     * @param string $group
     */
    public function group_can_see_and_move_card(string $group): void {
        $this->login_and_open_board($this->get_student_username($group));
        $this->assert_card_visible(true);
        $this->i_move_the_card_to_column('In Progress');
        $this->the_card_should_be_in_column('In Progress');
    }

    /**
     * Verify a different group cannot see the card or move it.
     *
     * @Then /^group "(?P<group>[^"]*)" cannot see or operate on it$/
     * @param string $group
     */
    public function group_cannot_see_or_operate_on_card(string $group): void {
        $this->login_and_open_board($this->get_student_username($group));
        $this->assert_card_visible(false);
        $this->i_try_to_move_that_card();
        if (!empty($this->lastajaxresult['ok'])) {
            throw new ExpectationException('A user in another group moved the Kanban card.', $this->getSession());
        }
    }

    /**
     * Create course, users, groups, board and columns for this scenario.
     */
    private function create_fixture(): void {
        global $DB;

        if ($this->course) {
            return;
        }

        $generator = testing_util::get_data_generator();
        $this->course = $generator->create_course();
        $this->users['Group A'] = $generator->create_user([
            'username' => 'kanban_student_a',
            'password' => 'kanban_student_a',
        ])->id;
        $this->users['Group B'] = $generator->create_user([
            'username' => 'kanban_student_b',
            'password' => 'kanban_student_b',
        ])->id;
        $teacher = $generator->create_user([
            'username' => 'kanban_teacher',
            'password' => 'kanban_teacher',
        ]);
        $this->users['teacher'] = $teacher->id;

        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $generator->enrol_user($this->users['Group A'], $this->course->id, $studentrole->id);
        $generator->enrol_user($this->users['Group B'], $this->course->id, $studentrole->id);
        $generator->enrol_user($teacher->id, $this->course->id, $teacherrole->id);

        foreach (['Group A', 'Group B'] as $groupname) {
            $this->groups[$groupname] = $generator->create_group([
                'courseid' => $this->course->id,
                'name' => $groupname,
            ])->id;
            groups_add_member($this->groups[$groupname], $this->users[$groupname]);
        }

        $this->module = $generator->create_module('kanban', [
            'course' => $this->course->id,
            'name' => 'Kanban Behat board',
            'groupmode' => SEPARATEGROUPS,
        ]);
        $this->cm = get_coursemodule_from_instance('kanban', $this->module->id, $this->course->id, false, MUST_EXIST);
        $columns = $DB->get_records('kanban_columns', ['kanbanid' => $this->module->id], 'sortorder ASC');
        $columns = array_values($columns);
        $columns[0]->title = 'To Do';
        $columns[1]->title = 'In Progress';
        $DB->update_record('kanban_columns', $columns[0]);
        $DB->update_record('kanban_columns', $columns[1]);
        $this->columns = [
            'To Do' => (int)$columns[0]->id,
            'In Progress' => (int)$columns[1]->id,
        ];
    }

    /**
     * Create a card in a column and group.
     *
     * @param string $columnname
     * @param string $groupname
     * @param string $title
     * @return int
     */
    private function create_card(string $columnname, string $groupname, string $title): int {
        global $DB;

        $this->cardcolumnbefore = $this->get_column_id($columnname);
        $id = $DB->insert_record('kanban_cards', (object)[
            'kanbanid' => $this->module->id,
            'columnid' => $this->cardcolumnbefore,
            'groupid' => $this->get_group_id($groupname),
            'title' => $title,
            'description' => '',
            'assigned_to' => $this->users[$groupname],
            'duedate' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        return (int)$id;
    }

    /**
     * Log in and open the board directly.
     *
     * @param string $username
     */
    private function login_and_open_board(string $username): void {
        $url = new moodle_url('/mod/kanban/view.php', ['id' => $this->cm->id]);
        $this->execute('behat_auth::i_log_in_as', [$username, $url]);
    }

    /**
     * Make a Moodle external AJAX call in the current browser session.
     *
     * @param string $methodname
     * @param array $args
     * @return array
     */
    private function call_ajax(string $methodname, array $args): array {
        $request = json_encode([[
            'index' => 0,
            'methodname' => $methodname,
            'args' => $args,
        ]], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $this->getSession()->executeScript(
            'window.kanbanBehatAjaxResult = null;' .
            'require(["core/ajax"], function(ajax) {' .
            '  ajax.call(' . $request . ')[0]' .
            '    .done(function(result) { window.kanbanBehatAjaxResult = {ok: true, result: result}; })' .
            '    .fail(function(error) { window.kanbanBehatAjaxResult = {ok: false, error: error.message || String(error)}; });' .
            '});'
        );

        if (!$this->getSession()->wait(10000, 'window.kanbanBehatAjaxResult !== null')) {
            throw new ExpectationException('Timed out waiting for the Kanban AJAX response.', $this->getSession());
        }
        $result = $this->getSession()->evaluateScript('return JSON.stringify(window.kanbanBehatAjaxResult);');
        return json_decode($result, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Reload the board and wait for its root element.
     */
    private function reload_board(): void {
        $this->getSession()->reload();
        if (!$this->getSession()->wait(10000, 'document.querySelector(".kanban-board-container") !== null')) {
            throw new ExpectationException('Kanban board did not reload.', $this->getSession());
        }
    }

    /**
     * Check card visibility in the current browser page.
     *
     * @param bool $visible
     */
    private function assert_card_visible(bool $visible): void {
        $script = sprintf(
            'return document.querySelector(".kanban-card[data-cardid=\\"%d\\"]") !== null;',
            (int)$this->cardid
        );
        if ((bool)$this->getSession()->evaluateScript($script) !== $visible) {
            throw new ExpectationException('Kanban card visibility did not match the group access expectation.',
                $this->getSession());
        }
    }

    /**
     * Check the card remains in the database following a denied request.
     */
    private function assert_card_still_exists(): void {
        global $DB;

        $card = $DB->get_record('kanban_cards', ['id' => $this->cardid]);
        if (!$card || (int)$card->columnid !== $this->cardcolumnbefore) {
            throw new ExpectationException('The denied request unexpectedly removed the Kanban card.',
                $this->getSession());
        }
        if ($this->cardcountbefore !== null &&
                $DB->count_records('kanban_cards', ['kanbanid' => $this->module->id]) !== $this->cardcountbefore) {
            throw new ExpectationException('The rejected request changed the number of Kanban cards.',
                $this->getSession());
        }
    }

    /**
     * Assert a column with the requested title exists on the board.
     *
     * @param string $title
     */
    private function assert_column_title(string $title): void {
        if (!isset($this->columns[$title])) {
            throw new ExpectationException("Kanban column {$title} does not exist in this fixture.",
                $this->getSession());
        }
        $columnid = $this->columns[$title];
        $actualtitle = $this->getSession()->evaluateScript(sprintf(
            'return document.querySelector(".kanban-column[data-columnid=\\"%d\\"] .card-title") ? ' .
                'document.querySelector(".kanban-column[data-columnid=\\"%d\\"] .card-title").textContent.trim() : null;',
            $columnid,
            $columnid
        ));
        if ($actualtitle !== $title) {
            throw new ExpectationException("Kanban column {$title} is not visible on the board.",
                $this->getSession());
        }
    }

    /**
     * Get a known column ID.
     *
     * @param string $title
     * @return int
     */
    private function get_column_id(string $title): int {
        $this->assert_column_title($title);
        return $this->columns[$title];
    }

    /**
     * Get a known group ID.
     *
     * @param string $name
     * @return int
     */
    private function get_group_id(string $name): int {
        if (!isset($this->groups[$name])) {
            throw new ExpectationException("Kanban test group {$name} does not exist.",
                $this->getSession());
        }
        return $this->groups[$name];
    }

    /**
     * Get the username for a group's student.
     *
     * @param string $groupname
     * @return string
     */
    private function get_student_username(string $groupname): string {
        $this->get_group_id($groupname);
        return $groupname === 'Group A' ? 'kanban_student_a' : 'kanban_student_b';
    }
}
