<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // Course Module ID (cmid)

$cm = get_coursemodule_from_id('kanban', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/kanban:viewdashboard', $context);

$PAGE->set_url('/mod/kanban/dashboard.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('dashboard', 'mod_kanban') . ' - ' . format_string($kanban->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Lấy thông tin nhóm sinh viên hiện tại
$currentgroup = groups_get_activity_group($cm, true);

// 1. Truy vấn các công việc theo nhóm
$cardparams = ['kanbanid' => $kanban->id];
if ($currentgroup) {
    $cardparams['groupid'] = $currentgroup;
}
$allcards = $DB->get_records('kanban_cards', $cardparams, 'sortorder ASC');
$totalcards = count($allcards);

// 2. Cột và cột Done (cột cuối cùng)
$columns = $DB->get_records('kanban_columns', ['kanbanid' => $kanban->id], 'sortorder ASC');
$donecolumn = end($columns);
$donecolumnid = $donecolumn ? (int)$donecolumn->id : 0;

$now = time();
$donecount = 0;
$overduecount = 0;
$duesooncount = 0;
$columncounts = [];
foreach ($columns as $col) {
    $columncounts[$col->id] = ['title' => format_string($col->title), 'count' => 0];
}

// Thống kê theo thành viên (đếm qua bảng assignees để đúng multi-assignee).
$userstats = []; // userid => ['name' => ..., 'total' => 0, 'done' => 0, 'overdue' => 0]
$overduelist = [];

foreach ($allcards as $card) {
    $isdone = ($donecolumnid && (int)$card->columnid === $donecolumnid);
    if (isset($columncounts[$card->columnid])) {
        $columncounts[$card->columnid]['count']++;
    }
    if ($isdone) {
        $donecount++;
    } else {
        if (!empty($card->duedate) && (int)$card->duedate < $now) {
            $overduecount++;
        } else if (!empty($card->duedate) && (int)$card->duedate <= ($now + 48 * HOURSECS)) {
            $duesooncount++;
        }
    }

    $assigneeids = kanban_get_card_assignee_ids($card->id);
    if (empty($assigneeids)) {
        $assigneeids = [0];
    }
    foreach ($assigneeids as $uid) {
        $uid = (int)$uid;
        if (!isset($userstats[$uid])) {
            if ($uid > 0 && $user = $DB->get_record('user', ['id' => $uid], 'id, firstname, lastname')) {
                $name = fullname($user);
            } else {
                $name = get_string('dashboard_unassigned', 'mod_kanban');
            }
            $userstats[$uid] = ['name' => $name, 'total' => 0, 'done' => 0, 'overdue' => 0];
        }
        $userstats[$uid]['total']++;
        if ($isdone) {
            $userstats[$uid]['done']++;
        } else if (!empty($card->duedate) && (int)$card->duedate < $now) {
            $userstats[$uid]['overdue']++;
        }
    }

    if (!$isdone && !empty($card->duedate) && (int)$card->duedate <= ($now + 48 * HOURSECS)) {
        $overduelist[] = $card;
    }
}
usort($overduelist, function($a, $b) {
    return ((int)$a->duedate - (int)$b->duedate);
});

// Tính tỷ lệ hoàn thành (%)
$progresspercent = ($totalcards > 0) ? round(($donecount / $totalcards) * 100) : 0;

// Sắp xếp bảng thành viên theo tổng số việc giảm dần.
uasort($userstats, function($a, $b) {
    return ($b['total'] - $a['total']);
});
$totalassignments = 0;
foreach ($userstats as $stat) {
    $totalassignments += $stat['total'];
}

// 3. Biểu đồ Moodle core (tự ẩn nếu core chưa hỗ trợ).
$columnchart = null;
$progresschart = null;
if (class_exists('\core\chart_bar') && class_exists('\core\chart_pie')) {
    $barseries = new \core\chart_series(
        get_string('dashboard_kpi_total', 'mod_kanban'),
        array_values(array_map(function($c) { return $c['count']; }, $columncounts))
    );
    $columnchart = new \core\chart_bar();
    $columnchart->set_title(get_string('dashboard_column_dist', 'mod_kanban'));
    $columnchart->set_labels(array_values(array_map(function($c) { return $c['title']; }, $columncounts)));
    $columnchart->add_series($barseries);

    $progresschart = new \core\chart_pie();
    $progresschart->set_title(get_string('dashboard_progress_pie', 'mod_kanban'));
    $progresschart->set_doughnut(true);
    $progresschart->add_series(new \core\chart_series(get_string('progress', 'mod_kanban'), [$donecount, $totalcards - $donecount]));
    $progresschart->set_labels([get_string('column_done', 'mod_kanban'), get_string('dashboard_remaining', 'mod_kanban')]);
}

echo $OUTPUT->header();

// Render the activity title explicitly because some Moodle themes hide it
// while still rendering the default Dashboard heading.
echo html_writer::div(
    html_writer::tag('h2', format_string($kanban->name), ['class' => 'h4 mb-1']) .
    html_writer::tag('div', get_string('dashboard', 'mod_kanban'), ['class' => 'text-muted mb-3']),
    'kanban-dashboard-heading'
);

// Nút quay lại bảng Kanban
echo html_writer::div(
    html_writer::link(new moodle_url('/mod/kanban/view.php', ['id' => $cm->id]),
        get_string('dashboard_back', 'mod_kanban'), ['class' => 'btn btn-outline-secondary mb-3']),
    'mb-2'
);

// Bộ chọn nhóm
groups_print_activity_menu($cm, $CFG->wwwroot . '/mod/kanban/dashboard.php?id=' . $cm->id);
?>

<div class="container-fluid px-0 mt-3">
    <!-- 4 Thẻ thống kê KPI -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0 bg-primary text-white p-3 rounded">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small text-uppercase fw-bold"><?php echo get_string('dashboard_kpi_total', 'mod_kanban'); ?></div>
                        <h2 class="mb-0 fw-bold"><?php echo $totalcards; ?></h2>
                    </div>
                    <i class="fa fa-tasks fa-3x opacity-50"></i>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0 bg-success text-white p-3 rounded">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small text-uppercase fw-bold"><?php echo get_string('dashboard_kpi_progress', 'mod_kanban'); ?></div>
                        <h2 class="mb-0 fw-bold"><?php echo $progresspercent; ?>%</h2>
                    </div>
                    <i class="fa fa-check-circle fa-3x opacity-50"></i>
                </div>
                <div class="progress mt-2" style="height: 6px; background-color: rgba(255,255,255,0.3);">
                    <div class="progress-bar bg-white" style="width: <?php echo $progresspercent; ?>%"></div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0 bg-danger text-white p-3 rounded">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small text-uppercase fw-bold"><?php echo get_string('dashboard_kpi_overdue', 'mod_kanban'); ?></div>
                        <h2 class="mb-0 fw-bold"><?php echo $overduecount; ?></h2>
                    </div>
                    <i class="fa fa-exclamation-triangle fa-3x opacity-50"></i>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0 bg-warning text-dark p-3 rounded">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small text-uppercase fw-bold opacity-75"><?php echo get_string('dashboard_kpi_duesoon', 'mod_kanban'); ?></div>
                        <h2 class="mb-0 fw-bold"><?php echo $duesooncount; ?></h2>
                    </div>
                    <i class="fa fa-clock-o fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <?php if ($columnchart && $progresschart && $totalcards > 0): ?>
    <!-- Biểu đồ -->
    <div class="row g-3 mb-4">
        <div class="col-md-7">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <?php echo $OUTPUT->render($columnchart); ?>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <?php echo $OUTPUT->render($progresschart); ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bảng mức độ đóng góp cá nhân -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="card-title mb-0 fw-bold text-dark"><i class="fa fa-users text-primary me-2"></i> <?php echo get_string('dashboard_member_title', 'mod_kanban'); ?></h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo get_string('dashboard_th_member', 'mod_kanban'); ?></th>
                            <th><?php echo get_string('dashboard_th_tasks', 'mod_kanban'); ?></th>
                            <th><?php echo get_string('dashboard_th_done', 'mod_kanban'); ?></th>
                            <th><?php echo get_string('dashboard_th_overdue', 'mod_kanban'); ?></th>
                            <th><?php echo get_string('dashboard_th_percent', 'mod_kanban'); ?></th>
                            <th><?php echo get_string('dashboard_th_progress', 'mod_kanban'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($userstats)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted"><?php echo get_string('dashboard_nodata', 'mod_kanban'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($userstats as $stat): ?>
                                <?php $percent = ($totalassignments > 0) ? round(($stat['total'] / $totalassignments) * 100) : 0; ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo s($stat['name']); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo $stat['total']; ?></span></td>
                                    <td><span class="badge bg-success"><?php echo $stat['done']; ?></span></td>
                                    <td><span class="badge <?php echo $stat['overdue'] > 0 ? 'bg-danger' : 'bg-light text-dark'; ?>"><?php echo $stat['overdue']; ?></span></td>
                                    <td><?php echo $percent; ?>%</td>
                                    <td style="width: 25%;">
                                        <div class="progress" style="height: 10px; border-radius: 5px;">
                                            <div class="progress-bar bg-info" style="width: <?php echo $percent; ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Danh sách cảnh báo trễ hạn / sắp đến hạn -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="card-title mb-0 fw-bold text-dark"><i class="fa fa-bell text-warning me-2"></i> <?php echo get_string('dashboard_overdue_title', 'mod_kanban'); ?></h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo get_string('dashboard_th_card', 'mod_kanban'); ?></th>
                            <th><?php echo get_string('dashboard_th_status', 'mod_kanban'); ?></th>
                            <th><?php echo get_string('dashboard_th_assignees', 'mod_kanban'); ?></th>
                            <th><?php echo get_string('dashboard_th_duedate', 'mod_kanban'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($overduelist)): ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted"><?php echo get_string('dashboard_no_warning', 'mod_kanban'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($overduelist as $card): ?>
                                <?php
                                $isover = ((int)$card->duedate < $now);
                                $colname = isset($columns[$card->columnid]) ? format_string($columns[$card->columnid]->title) : '-';
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo format_string($card->title); ?></td>
                                    <td><?php echo s($colname); ?></td>
                                    <td><?php echo s(kanban_get_card_assignee_names($card->id)); ?></td>
                                    <td>
                                        <span class="badge <?php echo $isover ? 'bg-danger' : 'bg-warning text-dark'; ?>">
                                            <?php echo userdate($card->duedate, '%d/%m/%Y %H:%M'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();
