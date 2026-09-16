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
$allcards = $DB->get_records('kanban_cards', $cardparams);
$totalcards = count($allcards);

// 2. Tìm ID cột "Done" (Cột cuối cùng)
$columns = $DB->get_records('kanban_columns', ['kanbanid' => $kanban->id], 'sortorder ASC');
$donecolumn = end($columns);
$donecolumnid = $donecolumn ? $donecolumn->id : 0;

$donecount = 0;
$overduecount = 0;
$usercontribution = [];

foreach ($allcards as $card) {
    if ($card->columnid == $donecolumnid) {
        $donecount++;
    } else {
        if ($card->duedate > 0 && $card->duedate < time()) {
            $overduecount++;
        }
    }

    $uid = $card->assigned_to ? $card->assigned_to : 0;
    if (!isset($usercontribution[$uid])) {
        $usercontribution[$uid] = 0;
    }
    $usercontribution[$uid]++;
}

// Tính tỷ lệ hoàn thành (%)
$progresspercent = ($totalcards > 0) ? round(($donecount / $totalcards) * 100) : 0;

// Thống kê theo thành viên
$userstats = [];
foreach ($usercontribution as $userid => $count) {
    if ($userid > 0 && $user = $DB->get_record('user', ['id' => $userid])) {
        $name = fullname($user);
    } else {
        $name = 'Chưa phân công';
    }
    $userstats[] = [
        'name' => $name,
        'task_count' => $count,
        'percent' => ($totalcards > 0) ? round(($count / $totalcards) * 100) : 0
    ];
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
    html_writer::link(new moodle_url('/mod/kanban/view.php', ['id' => $cm->id]), '← Quay lại Bảng Kanban', ['class' => 'btn btn-outline-secondary mb-3']),
    'mb-2'
);

// Bộ chọn nhóm
groups_print_activity_menu($cm, $CFG->wwwroot . '/mod/kanban/dashboard.php?id=' . $cm->id);
?>

<div class="container-fluid px-0 mt-3">
    <!-- 3 Thẻ thống kê KPI -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 bg-primary text-white p-3 rounded">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small text-uppercase fw-bold">Tổng số công việc</div>
                        <h2 class="mb-0 fw-bold"><?php echo $totalcards; ?></h2>
                    </div>
                    <i class="fa fa-tasks fa-3x opacity-50"></i>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0 bg-success text-white p-3 rounded">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small text-uppercase fw-bold">Tỷ lệ hoàn thành</div>
                        <h2 class="mb-0 fw-bold"><?php echo $progresspercent; ?>%</h2>
                    </div>
                    <i class="fa fa-check-circle fa-3x opacity-50"></i>
                </div>
                <div class="progress mt-2" style="height: 6px; background-color: rgba(255,255,255,0.3);">
                    <div class="progress-bar bg-white" style="width: <?php echo $progresspercent; ?>%"></div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0 bg-danger text-white p-3 rounded">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small text-uppercase fw-bold">Công việc trễ hạn</div>
                        <h2 class="mb-0 fw-bold"><?php echo $overduecount; ?></h2>
                    </div>
                    <i class="fa fa-exclamation-triangle fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Bảng mức độ đóng góp cá nhân -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="card-title mb-0 fw-bold text-dark"><i class="fa fa-users text-primary me-2"></i> Mức độ đóng góp của từng thành viên trong nhóm</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Thành viên</th>
                            <th>Số lượng Task phụ trách</th>
                            <th>Tỷ lệ đóng góp (%)</th>
                            <th>Thanh tiến độ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($userstats)): ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted">Chưa có dữ liệu phân công công việc</td></tr>
                        <?php else: ?>
                            <?php foreach ($userstats as $stat): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($stat['name']); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo $stat['task_count']; ?> việc</span></td>
                                    <td><?php echo $stat['percent']; ?>%</td>
                                    <td style="width: 35%;">
                                        <div class="progress" style="height: 10px; border-radius: 5px;">
                                            <div class="progress-bar bg-info" style="width: <?php echo $stat['percent']; ?>%"></div>
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
</div>

<?php
echo $OUTPUT->footer();