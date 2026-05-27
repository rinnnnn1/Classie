<?php
header("Cache-Control: no-cache, must-revalidate"); // Prevent caching

session_start();
require_once "config.php";
$student_session = $_SESSION['auth']['student'] ?? null;

if ((!is_array($student_session) || empty($student_session['id'])) && isset($_SESSION['user_id'])) {
    $legacy_user_id = (int)$_SESSION['user_id'];
    if ($legacy_user_id > 0) {
        $legacy_stmt = $conn->prepare("SELECT id, name, role FROM users WHERE id = ? LIMIT 1");
        if ($legacy_stmt) {
            $legacy_stmt->bind_param('i', $legacy_user_id);
            $legacy_stmt->execute();
            $legacy_result = $legacy_stmt->get_result();
            if ($legacy_result && $legacy_result->num_rows > 0) {
                $legacy_row = $legacy_result->fetch_assoc();
                if (($legacy_row['role'] ?? '') === 'student') {
                    if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
                        $_SESSION['auth'] = [];
                    }
                    $_SESSION['auth']['student'] = [
                        'id' => (int)$legacy_row['id'],
                        'name' => $legacy_row['name'] ?? 'Student',
                    ];
                    $student_session = $_SESSION['auth']['student'];
                }
            }
            $legacy_stmt->close();
        }
    }
}

if (!is_array($student_session) || empty($student_session['id'])) {
    header("Location: login.php");
    exit();
}
$user_id = (int)$student_session['id'];
$student_name = $student_session['name'] ?? 'Student';
$user_classes = [];

$role_stmt = $conn->prepare("SELECT name, role FROM users WHERE id = ? LIMIT 1");
if ($role_stmt) {
    $role_stmt->bind_param('i', $user_id);
    $role_stmt->execute();
    $role_result = $role_stmt->get_result();
    if ($role_result && $role_result->num_rows > 0) {
        $role_row = $role_result->fetch_assoc();
        $student_name = $role_row['name'] ?? $student_name;
        $current_role = $role_row['role'] ?? 'student';

        if ($current_role !== 'student') {
            $role_stmt->close();
            if ($current_role === 'admin') {
                header("Location: admin.php");
            } else {
                header("Location: teacher.php");
            }
            exit();
        }
    }
    $role_stmt->close();
}

// Separate connection for time_db
$time_conn = connect_time_db();
if ($time_conn->connect_error) {
    die("Time DB connection failed: " . $time_conn->connect_error);
}

// Fetch thresholds from database
$settings_query = $time_conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('on_time_threshold', 'late_threshold')");
$settings = [];
while ($row = $settings_query->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$on_time_threshold = $settings['on_time_threshold'] ?? '11:00:00';
$late_threshold = $settings['late_threshold'] ?? '12:00:00';
if ($time_conn->connect_error) {
    die("Time DB connection failed: " . $time_conn->connect_error);
}

$current_date = date('Y-m-d');
$attendance_status = [];
$attendance_history = [];
$class_activation_map = [];
$class_display_map = [];
$default_selected_class = '';
$is_selected_class_active = false;
$is_any_class_active = false;
$monthly_attended_count = 0;
$monthly_possible_count = 0;
$attendance_percentage = 0;

$time_conn->query("CREATE TABLE IF NOT EXISTS attendance_activation (class VARCHAR(50) NOT NULL, active_date DATE NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (class, active_date))");
$time_conn->query("CREATE TABLE IF NOT EXISTS classes_catalog (class_code VARCHAR(50) PRIMARY KEY, class_name VARCHAR(120) NOT NULL, section_name VARCHAR(80) DEFAULT '', is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$time_conn->query("ALTER TABLE classes_catalog ADD COLUMN IF NOT EXISTS section_id INT NULL");
$time_conn->query("ALTER TABLE classes_catalog ADD COLUMN IF NOT EXISTS sections VARCHAR(255) NULL");
$time_conn->query("ALTER TABLE classes_catalog ADD COLUMN IF NOT EXISTS on_time_threshold VARCHAR(8) NOT NULL DEFAULT '11:00:00'");
$time_conn->query("ALTER TABLE classes_catalog ADD COLUMN IF NOT EXISTS late_threshold VARCHAR(8) NOT NULL DEFAULT '12:00:00'");
$time_conn->query("INSERT IGNORE INTO classes_catalog (class_code, class_name, section_name) VALUES
    ('cs101', 'CS 101 - Programming', 'Section A'),
    ('math02', 'MATH 02 - Calculus', 'Section B'),
    ('hist01', 'HIST 01 - Philippines History', 'Section C')");

$class_threshold_map = [];
$class_map_result = $time_conn->query("SELECT class_code, class_name, on_time_threshold, late_threshold FROM classes_catalog WHERE is_active = 1");
while ($class_map_result && $class_map_row = $class_map_result->fetch_assoc()) {
    $class_display_map[$class_map_row['class_code']] = $class_map_row['class_name'];
    $class_threshold_map[$class_map_row['class_code']] = [
        'on_time' => $class_map_row['on_time_threshold'] ?: $on_time_threshold,
        'late' => $class_map_row['late_threshold'] ?: $late_threshold
    ];
}

if ($user_id) {
    $stmt = $conn->prepare("SELECT name, class, section_id FROM users WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $student_name = $user['name'] ?: $student_name;

        $resolved_classes = [];

        $section_id = (int)($user['section_id'] ?? 0);
        if ($section_id > 0) {
            $section_classes_stmt = $time_conn->prepare("SELECT class_code FROM classes_catalog WHERE is_active = 1 AND (section_id = ? OR FIND_IN_SET(?, sections)) ORDER BY class_name ASC");
            if ($section_classes_stmt) {
                $section_id_str = (string)$section_id;
                $section_classes_stmt->bind_param('is', $section_id, $section_id_str);
                $section_classes_stmt->execute();
                $section_classes_result = $section_classes_stmt->get_result();
                while ($section_classes_result && $section_class_row = $section_classes_result->fetch_assoc()) {
                    $clean_code = strtolower(trim((string)$section_class_row['class_code']));
                    if ($clean_code !== '') {
                        $resolved_classes[$clean_code] = true;
                    }
                }
                $section_classes_stmt->close();
            }
        }

        // Backward compatibility for previously assigned direct student class codes.
        $legacy_classes = array_map('trim', explode(',', (string)($user['class'] ?? '')));
        foreach ($legacy_classes as $legacy_code) {
            $clean_code = strtolower($legacy_code);
            if ($clean_code !== '') {
                $resolved_classes[$clean_code] = true;
            }
        }

        $user_classes = array_keys($resolved_classes);
    }
    $stmt->close();

    if (!empty($user_classes)) {
        $selected_class_from_session = strtolower(trim((string)($_SESSION['student_selected_class'] ?? '')));
        if ($selected_class_from_session === '' || !in_array($selected_class_from_session, $user_classes, true)) {
            $time_conn->close();
            header("Location: select_class.php");
            exit();
        }
        $default_selected_class = $selected_class_from_session;

        $activation_stmt = $time_conn->prepare("SELECT is_active FROM attendance_activation WHERE class = ? AND active_date = ? LIMIT 1");
        foreach ($user_classes as $class_code) {
            $activation_stmt->bind_param('ss', $class_code, $current_date);
            $activation_stmt->execute();
            $activation_result = $activation_stmt->get_result();

            $class_activation_map[$class_code] = false;
            if ($activation_result && $activation_result->num_rows > 0) {
                $activation_row = $activation_result->fetch_assoc();
                $class_activation_map[$class_code] = ((int)$activation_row['is_active'] === 1);
            }
        }
        $activation_stmt->close();
        $is_selected_class_active = $class_activation_map[$default_selected_class] ?? false;
        $is_any_class_active = in_array(true, $class_activation_map, true);

        $days_elapsed = (int)date('j');
        $monthly_possible_count = count($user_classes) * $days_elapsed;
        $month_start = date('Y-m-01');

        $monthly_stmt = $time_conn->prepare("SELECT class, attendance_date, attendance_time FROM attendance WHERE user_id = ? AND attendance_date BETWEEN ? AND ?");
        $monthly_stmt->bind_param('iss', $user_id, $month_start, $current_date);
        $monthly_stmt->execute();
        $monthly_result = $monthly_stmt->get_result();

        $class_lookup = array_fill_keys($user_classes, true);
        $seen_monthly_entries = [];

        while ($monthly_result && $monthly_row = $monthly_result->fetch_assoc()) {
            $class_code = $monthly_row['class'];
            if (!isset($class_lookup[$class_code])) {
                continue;
            }

            $seen_key = $class_code . '|' . $monthly_row['attendance_date'];
            if (isset($seen_monthly_entries[$seen_key])) {
                continue;
            }
            $seen_monthly_entries[$seen_key] = true;

            $class_late_threshold = $class_threshold_map[$class_code]['late'] ?? $late_threshold;
            if ($monthly_row['attendance_time'] <= $class_late_threshold) {
                $monthly_attended_count++;
            }
        }
        $monthly_stmt->close();

        if ($monthly_possible_count > 0) {
            $attendance_percentage = (int)round(($monthly_attended_count / $monthly_possible_count) * 100);
        }
    }

    // Get attendance for each class
    foreach ($user_classes as $class_code) {
        $att_stmt = $time_conn->prepare("SELECT attendance_time FROM attendance WHERE user_id = ? AND class = ? AND attendance_date = ?");
        $att_stmt->bind_param('iss', $user_id, $class_code, $current_date);
        $att_stmt->execute();
        $att_result = $att_stmt->get_result();
        if ($att_result && $att_result->num_rows > 0) {
            $att = $att_result->fetch_assoc();
            $time = $att['attendance_time'];
            $formatted_time = date('h:i A', strtotime($time));
            $class_on_time_threshold = $class_threshold_map[$class_code]['on_time'] ?? $on_time_threshold;
            $class_late_threshold = $class_threshold_map[$class_code]['late'] ?? $late_threshold;
            if ($time <= $class_on_time_threshold) {
                $status = 'present';
                $label = 'Present';
            } elseif ($time <= $class_late_threshold) {
                $status = 'late';
                $label = 'Late';
            } else {
                $status = 'absent';
                $label = 'Absent';
            }
        } else {
            $time = 'No attendance';
            $formatted_time = 'No attendance';
            $status = 'absent';
            $label = 'Absent';
        }
        $attendance_status[$class_code] = ['label' => $label, 'time' => $formatted_time];
        $att_stmt->close();

        $history_stmt = $time_conn->prepare("SELECT attendance_date, attendance_time FROM attendance WHERE user_id = ? AND class = ? ORDER BY attendance_date DESC LIMIT 10");
        $history_stmt->bind_param('is', $user_id, $class_code);
        $history_stmt->execute();
        $history_result = $history_stmt->get_result();
        $class_on_time_threshold = $class_threshold_map[$class_code]['on_time'] ?? $on_time_threshold;
        $class_late_threshold = $class_threshold_map[$class_code]['late'] ?? $late_threshold;
        while ($history_result && $history_row = $history_result->fetch_assoc()) {
            $time = $history_row['attendance_time'];
            if ($time <= $class_on_time_threshold) {
                $history_label = 'Present';
            } elseif ($time <= $class_late_threshold) {
                $history_label = 'Late';
            } else {
                $history_label = 'Absent';
            }

            $attendance_history[] = [
                'class_code' => $class_code,
                'attendance_date' => $history_row['attendance_date'],
                'attendance_time' => date('h:i A', strtotime($time)),
                'status_label' => $history_label,
                'status_class' => strtolower($history_label)
            ];
        }
        $history_stmt->close();
    }
}

$time_conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Attendance - Classiee</title>
    <link rel="stylesheet" href="style.css?v=20260511b">
    <style>
        /* Critical popup styles kept inline to avoid stale CSS cache issues. */
        .student-popup-overlay {
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, 0.55);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 1200;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }

        .student-popup-overlay.visible {
            opacity: 1;
            visibility: visible;
        }

        .student-popup-card {
            width: 100%;
            max-width: 420px;
            background: linear-gradient(165deg, #0f172a 0%, #1e293b 100%);
            border: 1px solid rgba(148, 163, 184, 0.35);
            border-radius: 14px;
            box-shadow: 0 24px 44px rgba(2, 6, 23, 0.45);
            padding: 22px 20px 18px;
            transform: scale(0.96) translateY(6px);
            transition: transform 0.2s ease;
        }

        .student-popup-overlay.visible .student-popup-card {
            transform: scale(1) translateY(0);
        }

        .student-popup-title {
            margin: 0 0 10px;
            color: #f8fafc;
            font-size: 1.15rem;
            letter-spacing: 0.01em;
        }

        .student-popup-message {
            margin: 0;
            color: #dbeafe;
            font-size: 0.95rem;
            line-height: 1.45;
        }

        .student-popup-close {
            margin-top: 16px;
            border: none;
            border-radius: 8px;
            padding: 10px 16px;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.92rem;
            cursor: pointer;
            background: #0284c7;
        }

        .student-popup-card.popup-success { border-left: 4px solid #22c55e; }
        .student-popup-card.popup-warning { border-left: 4px solid #f59e0b; }
        .student-popup-card.popup-error { border-left: 4px solid #ef4444; }
        .student-popup-card.popup-info { border-left: 4px solid #38bdf8; }
        .student-popup-card.popup-success .student-popup-close { background: #16a34a; }
        .student-popup-card.popup-warning .student-popup-close { background: #d97706; }
        .student-popup-card.popup-error .student-popup-close { background: #dc2626; }
        .student-popup-card.popup-info .student-popup-close { background: #0284c7; }

        @media (max-width: 768px) {
            .student-popup-card {
                max-width: 94vw;
                padding: 18px 16px 14px;
            }
        }
    </style>
</head>
<body class="student-body">
    <div class="student-container">
        <div class="student-card">
            <div class="school-icon">🏫</div>
            <h2>Attendance Status</h2>
            <p>Welcome back, <?php echo htmlspecialchars($student_name); ?>!</p>
            <div id="live-clock" class="live-clock"></div>
            <?php if (isset($_GET['logout_blocked']) && $_GET['logout_blocked'] === '1') { ?>
                <span class="attendance-window-status inactive">Logout is locked while your teacher has attendance active.</span>
            <?php } ?>

            <div class="status-summary student-panel">
                <div class="attendance-ring">
                    <span class="percent-text"><?php echo $attendance_percentage; ?>%</span>
                </div>
                <p class="status-label">Monthly Attendance (<?php echo $monthly_attended_count; ?>/<?php echo $monthly_possible_count; ?>)</p>
            </div>

            <div class="attendance-details student-panel">
                <?php
                foreach ($user_classes as $class_code) {
                    $class_name = $class_display_map[$class_code] ?? strtoupper($class_code);
                    $status_info = $attendance_status[$class_code] ?? ['label' => 'Absent', 'time' => 'No attendance'];
                    $status = strtolower($status_info['label']);
                    $label = $status_info['label'];
                    $time = $status_info['time'];
                    echo "<div class='subject-row' data-class='{$class_code}'>
                        <span>{$class_name} ({$time})</span>
                        <span class='badge {$status}'>{$label}</span>
                    </div>";
                }
                ?>
            </div>

            <div class="table-card student-history-card student-panel">
                <h3 class="student-section-title">Recent Attendance History</h3>
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th style="text-align: right;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (count($attendance_history) > 0) {
                            usort($attendance_history, function ($a, $b) {
                                return strcmp($b['attendance_date'], $a['attendance_date']);
                            });
                            foreach ($attendance_history as $entry) {
                                $class_name = $class_display_map[$entry['class_code']] ?? strtoupper($entry['class_code']);
                                echo "<tr>
                                    <td>{$class_name}</td>
                                    <td>" . date('M j, Y', strtotime($entry['attendance_date'])) . "</td>
                                    <td>{$entry['attendance_time']}</td>
                                    <td style='text-align: right;'><span class='status-pill {$entry['status_class']}'>{$entry['status_label']}</span></td>
                                </tr>";
                            }
                        } else {
                            echo "<tr><td colspan='4' style='text-align:center;'>No attendance history yet.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <div class="footer-links student-footer-links">
                <form id="attendance-form" action="attendance.php" method="post" class="student-attendance-form">
                    <input type="hidden" name="attendance_date" id="attendance_date">
                    <input type="hidden" name="attendance_time" id="attendance_time">
                    <input type="hidden" name="class" id="selected_class_code" value="<?php echo htmlspecialchars($default_selected_class); ?>">
                    <p class="selected-class-display">Selected Class: <strong id="selected-class-label"><?php echo htmlspecialchars($class_display_map[$default_selected_class] ?? strtoupper($default_selected_class ?: 'None')); ?></strong></p>
                    <button type="submit" class="take-attendance-btn" <?php if (!$is_selected_class_active) echo 'disabled'; ?>>Take Attendance</button>
                </form>
                <span id="attendance-window-status" class="attendance-window-status <?php echo $is_selected_class_active ? 'active' : 'inactive'; ?>">
                    <?php echo $is_selected_class_active ? 'Attendance is open for this class.' : 'Attendance is currently closed by your teacher.'; ?>
                </span>
                 <a
                    id="student-exit-link"
                    href="<?php echo $is_any_class_active ? '#' : 'logout.php'; ?>"
                    class="student-exit-link <?php echo $is_any_class_active ? 'disabled' : ''; ?>"
                    aria-disabled="<?php echo $is_any_class_active ? 'true' : 'false'; ?>"
                    data-locked="<?php echo $is_any_class_active ? '1' : '0'; ?>"
                 >Exit</a>

            </div>

        </div>
    </div>

    <div id="student-popup" class="student-popup-overlay" aria-hidden="true" hidden>
        <div class="student-popup-card" role="alertdialog" aria-live="assertive" aria-modal="true" aria-labelledby="student-popup-title" aria-describedby="student-popup-message">
            <h3 id="student-popup-title" class="student-popup-title">Notice</h3>
            <p id="student-popup-message" class="student-popup-message">Attendance processed.</p>
            <button type="button" id="student-popup-close" class="student-popup-close">OK</button>
        </div>
    </div>

    <script>
        const onTimeThreshold = '<?php echo $on_time_threshold; ?>';
        const lateThreshold = '<?php echo $late_threshold; ?>';
        let classActivationMap = <?php echo json_encode($class_activation_map); ?>;
        const logoutLink = document.getElementById('student-exit-link');
        const studentPopup = document.getElementById('student-popup');
        const studentPopupCard = studentPopup.querySelector('.student-popup-card');
        const studentPopupTitle = document.getElementById('student-popup-title');
        const studentPopupMessage = document.getElementById('student-popup-message');
        const studentPopupClose = document.getElementById('student-popup-close');
        let popupAutoCloseTimer = null;
        let popupOnClose = null;

        function closeStudentPopup() {
            studentPopup.classList.remove('visible');
            studentPopup.setAttribute('aria-hidden', 'true');
            studentPopup.hidden = true;

            if (popupAutoCloseTimer) {
                clearTimeout(popupAutoCloseTimer);
                popupAutoCloseTimer = null;
            }

            if (typeof popupOnClose === 'function') {
                const callback = popupOnClose;
                popupOnClose = null;
                callback();
            }
        }

        function showStudentPopup(message, type = 'info', autoCloseMs = 2200, onClose = null) {
            studentPopupTitle.textContent = type === 'success'
                ? 'Attendance Saved'
                : type === 'warning'
                    ? 'Attendance Closed'
                    : type === 'error'
                        ? 'Something Went Wrong'
                        : 'Notice';

            studentPopupMessage.textContent = message;
            studentPopupCard.classList.remove('popup-success', 'popup-warning', 'popup-error', 'popup-info');
            studentPopupCard.classList.add(`popup-${type}`);

            popupOnClose = onClose;
            studentPopup.hidden = false;
            studentPopup.classList.add('visible');
            studentPopup.setAttribute('aria-hidden', 'false');

            if (popupAutoCloseTimer) {
                clearTimeout(popupAutoCloseTimer);
            }

            if (autoCloseMs > 0) {
                popupAutoCloseTimer = setTimeout(closeStudentPopup, autoCloseMs);
            }
        }

        studentPopupClose.addEventListener('click', closeStudentPopup);
        studentPopup.addEventListener('click', function (event) {
            if (event.target === studentPopup) {
                closeStudentPopup();
            }
        });

        function updateClock() {
            const now = new Date();
            const dateString = now.toLocaleDateString();
            const timeString = now.toLocaleTimeString();
            document.getElementById('live-clock').innerHTML = dateString + '<br>' + timeString;
        }
        setInterval(updateClock, 1000);
        updateClock(); // initial call

        document.getElementById('attendance-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const selectedClass = document.getElementById('selected_class_code').value;
            const attendanceButton = document.querySelector('.take-attendance-btn');

            if (!classActivationMap[selectedClass] || attendanceButton.disabled) {
                showStudentPopup('Attendance is currently closed by your teacher for this class.', 'warning');
                return;
            }

            const formData = new FormData(this);
            const now = new Date();
            formData.append('attendance_date', now.toLocaleDateString());
            formData.append('attendance_time', now.toLocaleTimeString());

            fetch('attendance.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success' || data.status === 'duplicate') {
                    updateStatus(selectedClass, now, data.attendance_time);
                }

                if (data.status === 'success') {
                    showStudentPopup(data.message || 'Attendance has been recorded.', 'success', 1600, function () {
                        window.location.reload();
                    });
                    return;
                }

                if (data.status === 'duplicate') {
                    showStudentPopup(data.message || 'Attendance already recorded for today.', 'info');
                    return;
                }

                showStudentPopup(data.message || 'Attendance processed.', 'error');
            })
            .catch(error => {
                console.error('Error:', error);
                showStudentPopup('Unable to submit attendance right now. Please try again.', 'error');
            });
        });

        function updateAttendanceAvailability() {
            const selectedClass = document.getElementById('selected_class_code').value;
            const attendanceButton = document.querySelector('.take-attendance-btn');
            const status = document.getElementById('attendance-window-status');
            const isActive = !!classActivationMap[selectedClass];

            attendanceButton.disabled = !isActive;
            status.className = `attendance-window-status ${isActive ? 'active' : 'inactive'}`;
            status.textContent = isActive
                ? 'Attendance is open for this class.'
                : 'Attendance is currently closed by your teacher.';

            const isAnyClassActive = Object.values(classActivationMap || {}).some(Boolean);
            logoutLink.dataset.locked = isAnyClassActive ? '1' : '0';
            logoutLink.setAttribute('aria-disabled', isAnyClassActive ? 'true' : 'false');
            logoutLink.classList.toggle('disabled', isAnyClassActive);
            logoutLink.setAttribute('href', isAnyClassActive ? '#' : 'logout.php');
        }

        logoutLink.addEventListener('click', function (event) {
            if (logoutLink.dataset.locked === '1') {
                event.preventDefault();
                showStudentPopup('You cannot logout while attendance is active. Please wait until your teacher closes attendance.', 'warning');
            }
        });

        updateAttendanceAvailability();

        async function refreshActivationState() {
            try {
                const response = await fetch('get_activation_status.php', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();
                if (data.status === 'ok' && data.activation_map) {
                    classActivationMap = data.activation_map;
                    updateAttendanceAvailability();
                }
            } catch (error) {
                console.error('Activation refresh failed:', error);
            }
        }

        setInterval(refreshActivationState, 5000);

        function updateStatus(classCode, timestamp, serverTimeText) {
            const dbTime = timestamp.toTimeString().split(' ')[0]; // HH:MM:SS
            let status, label;
            if (dbTime <= onTimeThreshold) {
                status = 'present';
                label = 'Present';
            } else if (dbTime <= lateThreshold) {
                status = 'late';
                label = 'Late';
            } else {
                status = 'absent';
                label = 'Absent';
            }
            const formattedTime = serverTimeText || timestamp.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit', hour12: true});
            
            const row = document.querySelector(`.subject-row[data-class="${classCode}"]`);
            if (row) {
                const span = row.querySelector('span');
                // Replace the time part
                span.textContent = span.textContent.replace(/\([^)]*\)/, `(${formattedTime})`);
                const badge = row.querySelector('.badge');
                badge.className = `badge ${status}`;
                badge.textContent = label;
            }
        }

        function scheduleMidnightRefresh() {
            const now = new Date();
            const nextMidnight = new Date(now);
            nextMidnight.setHours(24, 0, 0, 0);
            const msUntilMidnight = nextMidnight.getTime() - now.getTime();

            setTimeout(() => {
                window.location.reload();
            }, msUntilMidnight + 1000);
        }

        scheduleMidnightRefresh();
    </script>
</body>
</html>