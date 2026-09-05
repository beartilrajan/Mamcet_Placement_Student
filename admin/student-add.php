<?php
// MAMCET Placement & Learning Portal - Add Student (Manual Entry)

$pageTitle = 'Add New Student';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');
require_once(__DIR__ . '/../services/StudentService.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$studentService = new StudentService($db);

$error = '';
$success = '';
$createdStudentId = 0;

$departments = $db->query("SELECT dept_id, dept_code, dept_name FROM departments ORDER BY dept_code")->fetchAll();
$batches = $db->query("SELECT batch_id, batch_name, admission_year, graduation_year, programme_duration, current_year_of_study FROM batches ORDER BY graduation_year DESC")->fetchAll();
$sections = $db->query("SELECT section_id, section_name, dept_id FROM sections ORDER BY section_name")->fetchAll();

$activeSessStart = 2026;
$stmtActSess = $db->query("SELECT start_year FROM academic_sessions WHERE is_active = 1 LIMIT 1");
if ($r = $stmtActSess->fetch()) {
    $activeSessStart = (int)$r['start_year'];
}

// Handle direct non-AJAX POST submission fallback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_student') {
    verifyCsrfRequest();
    try {
        $createdStudentId = $studentService->createStudent($_POST, (int)$_SESSION['user_id']);
        $success = "Student <strong>" . esc($_POST['student_name'] ?? '') . "</strong> (" . esc($_POST['registration_number'] ?? '') . ") was added successfully.";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        <!-- Page Header Breadcrumb and Navigation -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="students.php" class="text-decoration-none">Student Roster</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Add Student</li>
                    </ol>
                </nav>
                <h2 class="h4 fw-bold text-dark mb-0"><i class="fa-solid fa-user-plus text-primary me-2"></i> Add Student Record</h2>
            </div>
            <div class="d-flex gap-2">
                <a href="students.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Roster
                </a>
                <a href="student-import.php" class="btn btn-outline-success btn-sm">
                    <i class="fa-solid fa-file-excel me-1"></i> Bulk Import (Excel)
                </a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo esc($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fa-solid fa-circle-check me-2"></i> <?php echo $success; ?>
                    </div>
                    <?php if ($createdStudentId > 0): ?>
                        <div>
                            <a href="student-view.php?student_id=<?php echo $createdStudentId; ?>" class="btn btn-sm btn-success text-white me-2">View Student Profile</a>
                            <a href="student-add.php" class="btn btn-sm btn-outline-success bg-white">Add Another</a>
                        </div>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Student Creation Form Card -->
        <form id="addStudentForm" action="student-add.php" method="POST" class="needs-validation" novalidate>
            <?php csrfInput(); ?>
            <input type="hidden" name="action" id="formActionInput" value="save_student">

            <div class="row g-4">
                <!-- Left Column: Primary & Academic Info -->
                <div class="col-lg-8">
                    <!-- SECTION 1: IDENTIFICATION & PERSONAL DETAILS -->
                    <div class="mamcet-card mb-4">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="card-title fw-bold mb-0 text-dark">
                                <i class="fa-solid fa-id-card text-primary me-2"></i> 1. Identification & Personal Details
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">
                                        Registration Number <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="fa-solid fa-hashtag"></i></span>
                                        <input type="text" name="registration_number" id="registration_number" class="form-control text-uppercase" placeholder="e.g. 812421104001" required maxlength="50" autocomplete="off">
                                    </div>
                                    <div id="regNoFeedback" class="small mt-1"></div>
                                    <div class="invalid-feedback">Valid registration number is required (3-50 alphanumeric characters).</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">
                                        Full Name <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                                        <input type="text" name="student_name" id="student_name" class="form-control" placeholder="e.g. Aravind Kumar R" required maxlength="150">
                                    </div>
                                    <div class="invalid-feedback">Student full name is required (min 2 characters).</div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small">Date of Birth</label>
                                    <input type="date" name="dob" id="dob" class="form-control form-control-sm" max="<?php echo date('Y-m-d', strtotime('-15 years')); ?>">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small">Gender</label>
                                    <select name="gender" class="form-select form-select-sm">
                                        <option value="">Select Gender</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small">Blood Group</label>
                                    <select name="blood_group" class="form-select form-select-sm">
                                        <option value="">Select Blood Group</option>
                                        <option value="A+">A+</option>
                                        <option value="A-">A-</option>
                                        <option value="B+">B+</option>
                                        <option value="B-">B-</option>
                                        <option value="O+">O+</option>
                                        <option value="O-">O-</option>
                                        <option value="AB+">AB+</option>
                                        <option value="AB-">AB-</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small">Aadhar Number (12 Digits)</label>
                                    <input type="text" name="aadhar_number" id="aadhar_number" class="form-control form-control-sm" placeholder="12-digit UID" maxlength="12" pattern="[0-9]{12}">
                                    <div class="invalid-feedback">Aadhar number must be exactly 12 digits.</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small">Hosteller Status</label>
                                    <select name="hosteller_status" class="form-select form-select-sm">
                                        <option value="No">Day Scholar (No)</option>
                                        <option value="Yes">Hosteller (Yes)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 2: ACADEMIC AFFILIATION -->
                    <div class="mamcet-card mb-4">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="card-title fw-bold mb-0 text-dark">
                                <i class="fa-solid fa-graduation-cap text-primary me-2"></i> 2. Academic Affiliation
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">
                                        Department <span class="text-danger">*</span>
                                    </label>
                                    <select name="dept_id" id="dept_id" class="form-select form-select-sm" required>
                                        <option value="">-- Choose Department --</option>
                                        <?php foreach ($departments as $d): ?>
                                            <option value="<?php echo $d['dept_id']; ?>"><?php echo esc($d['dept_name']) . ' (' . esc($d['dept_code']) . ')'; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please choose a department.</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">
                                        Batch (Admission – Graduation) <span class="text-danger">*</span>
                                    </label>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="input-group input-group-sm flex-grow-1">
                                            <span class="input-group-text bg-light fw-bold text-dark px-2">20</span>
                                            <input type="number" name="batch_start_yy" id="batch_start_yy" class="form-control" value="24" min="10" max="99" placeholder="24" required>
                                        </div>
                                        <span class="text-muted fw-bold">–</span>
                                        <div class="input-group input-group-sm flex-grow-1">
                                            <span class="input-group-text bg-light fw-bold text-dark px-2">20</span>
                                            <input type="number" name="batch_end_yy" id="batch_end_yy" class="form-control" value="28" min="10" max="99" placeholder="28" required>
                                        </div>
                                    </div>
                                    <input type="hidden" name="batch" id="batch_name_input" value="2024–2028">
                                    <div class="form-text small text-muted d-flex justify-content-between align-items-center mt-1">
                                        <span>Batch: <strong id="batch_preview_badge" class="text-primary">2024–2028</strong></span>
                                        <span class="badge bg-light text-secondary border" id="batch_dur_badge">4-Year Degree</span>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small">Section (Optional)</label>
                                    <select name="section_id" id="section_id" class="form-select form-select-sm">
                                        <option value="">None / Not Assigned</option>
                                        <?php foreach ($sections as $sec): ?>
                                            <option value="<?php echo $sec['section_id']; ?>" data-dept="<?php echo $sec['dept_id']; ?>">
                                                Section <?php echo esc($sec['section_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small">Year of Study</label>
                                    <select name="year_of_study" id="year_of_study" class="form-select form-select-sm">
                                        <option value="Final Year">Final Year (IV)</option>
                                        <option value="Third Year">Third Year (III)</option>
                                        <option value="Second Year">Second Year (II)</option>
                                        <option value="First Year">First Year (I)</option>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small">Current Semester</label>
                                    <select name="current_semester" id="current_semester" class="form-select form-select-sm">
                                        <?php for ($sem = 1; $sem <= 8; $sem++): ?>
                                            <option value="<?php echo $sem; ?>">Semester <?php echo $sem; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 3: ACADEMIC MARKS & SCORES -->
                    <div class="mamcet-card mb-4">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="card-title fw-bold mb-0 text-dark">
                                <i class="fa-solid fa-chart-line text-primary me-2"></i> 3. Academic Performance & Marks
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 mb-3">
                                <div class="col-md-3 col-6">
                                    <label class="form-label small">10th Mark (%)</label>
                                    <input type="number" step="0.01" min="0" max="100" name="tenth_percentage" class="form-control form-control-sm" placeholder="e.g. 85.50">
                                </div>
                                <div class="col-md-3 col-6">
                                    <label class="form-label small">12th Mark (%)</label>
                                    <input type="number" step="0.01" min="0" max="100" name="twelfth_percentage" class="form-control form-control-sm" placeholder="e.g. 82.00">
                                </div>
                                <div class="col-md-3 col-6">
                                    <label class="form-label small">Diploma Mark (%)</label>
                                    <input type="number" step="0.01" min="0" max="100" name="diploma_percentage" class="form-control form-control-sm" placeholder="Optional">
                                </div>
                                <div class="col-md-3 col-6">
                                    <label class="form-label fw-bold text-primary small">Current CGPA (0 - 10)</label>
                                    <input type="number" step="0.01" min="0" max="10" name="current_cgpa" class="form-control form-control-sm border-primary" placeholder="e.g. 8.25">
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6 col-6">
                                    <label class="form-label small">Standing Arrears Count</label>
                                    <input type="number" min="0" max="99" name="standing_arrears" class="form-control form-control-sm" value="0">
                                </div>
                                <div class="col-md-6 col-6">
                                    <label class="form-label small">History of Arrears Count</label>
                                    <input type="number" min="0" max="99" name="history_of_arrears" class="form-control form-control-sm" value="0">
                                </div>
                            </div>

                            <label class="form-label small text-muted d-block mb-2">Semester GPAs (Optional)</label>
                            <div class="row g-2">
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                    <div class="col-md-3 col-6">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text small" style="font-size:0.75rem;">S<?php echo $i; ?></span>
                                            <input type="number" step="0.01" min="0" max="10" name="sem<?php echo $i; ?>_gpa" class="form-control" placeholder="0.00">
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Contact, Placement & Actions -->
                <div class="col-lg-4">
                    <!-- SECTION 4: CONTACT INFORMATION -->
                    <div class="mamcet-card mb-4">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="card-title fw-bold mb-0 text-dark">
                                <i class="fa-solid fa-address-book text-primary me-2"></i> 4. Contact Details
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-bold small">
                                        Mobile Number <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="fa-solid fa-phone"></i></span>
                                        <input type="tel" name="mobile_number" id="mobile_number" class="form-control" placeholder="10-digit Mobile" required pattern="[0-9]{8,15}">
                                    </div>
                                    <div class="invalid-feedback">Valid mobile number is required (8-15 digits).</div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label small">Alternate / Parent Mobile</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="fa-solid fa-phone-flip"></i></span>
                                        <input type="tel" name="alt_mobile_number" class="form-control" placeholder="Parent Contact" pattern="[0-9]{8,15}">
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label small">Personal Email</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="fa-solid fa-envelope"></i></span>
                                        <input type="email" name="email" class="form-control" placeholder="student@gmail.com">
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label small">College Official Email</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="fa-solid fa-at"></i></span>
                                        <input type="email" name="college_email" class="form-control" placeholder="student@mamcet.org">
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label small">City / Town</label>
                                    <input type="text" name="city" class="form-control form-control-sm" placeholder="e.g. Tiruchirappalli">
                                </div>

                                <div class="col-12">
                                    <label class="form-label small">District</label>
                                    <input type="text" name="district" class="form-control form-control-sm" placeholder="e.g. Trichy">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 5: PLACEMENT PREFERENCES -->
                    <div class="mamcet-card mb-4">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="card-title fw-bold mb-0 text-dark">
                                <i class="fa-solid fa-briefcase text-primary me-2"></i> 5. Placement Status
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label small fw-bold">Placement Willingness</label>
                                    <select name="placement_willingness" class="form-select form-select-sm">
                                        <option value="Yes" selected>Yes (Willing for Placements)</option>
                                        <option value="No">No (Not Willing / Higher Studies)</option>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label small fw-bold">Initial Placement Status</label>
                                    <select name="placement_status" class="form-select form-select-sm">
                                        <option value="Unplaced" selected>Unplaced</option>
                                        <option value="Placed">Placed</option>
                                        <option value="Higher Studies">Higher Studies</option>
                                        <option value="Entrepreneurship">Entrepreneurship</option>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label small">Driving Licence / Passport</label>
                                    <div class="d-flex gap-2">
                                        <select name="licence_status" class="form-select form-select-sm">
                                            <option value="">Licence: None</option>
                                            <option value="Yes">Licence: Yes</option>
                                            <option value="No">Licence: No</option>
                                        </select>
                                        <select name="passport_status" class="form-select form-select-sm">
                                            <option value="">Passport: None</option>
                                            <option value="Yes">Passport: Yes</option>
                                            <option value="No">Passport: No</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ACTION BUTTONS CARD -->
                    <div class="mamcet-card sticky-top" style="top: 80px; z-index: 10;">
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" id="btnSubmitStudent" class="btn btn-primary btn-lg">
                                    <i class="fa-solid fa-user-plus me-1" id="submitIcon"></i> Save Student Record
                                </button>
                                <button type="button" id="btnSaveAndAddAnother" class="btn btn-outline-primary">
                                    <i class="fa-solid fa-plus me-1"></i> Save & Add Another
                                </button>
                                <a href="students.php" class="btn btn-light border text-muted">
                                    Cancel
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
$(document).ready(function() {
    let duplicateCheckTimer = null;
    let isRegNoValid = true;

    // Live Debounced Check for Duplicate Registration Number
    $('#registration_number').on('input blur', function() {
        const val = $(this).val().trim();
        const feedback = $('#regNoFeedback');
        const input = $(this);

        clearTimeout(duplicateCheckTimer);

        if (val.length < 3) {
            feedback.html('');
            input.removeClass('is-invalid is-valid');
            return;
        }

        feedback.html('<span class="text-muted"><i class="fa-solid fa-spinner fa-spin me-1"></i> Checking availability...</span>');

        duplicateCheckTimer = setTimeout(function() {
            $.ajax({
                url: '../api/student-api.php',
                type: 'GET',
                data: {
                    action: 'check_duplicate',
                    registration_number: val
                },
                dataType: 'json',
                success: function(res) {
                    if (res.exists) {
                        isRegNoValid = false;
                        input.removeClass('is-valid').addClass('is-invalid');
                        feedback.html('<span class="text-danger fw-bold"><i class="fa-solid fa-circle-xmark me-1"></i> ' + res.message + '</span>');
                    } else {
                        isRegNoValid = true;
                        input.removeClass('is-invalid').addClass('is-valid');
                        feedback.html('<span class="text-success"><i class="fa-solid fa-circle-check me-1"></i> ' + res.message + '</span>');
                    }
                },
                error: function() {
                    feedback.html('');
                }
            });
        }, 300);
    });

    // Dynamic Section filtering on Department Selection
    $('#dept_id').on('change', function() {
        const deptId = $(this).val();
        $('#section_id option').each(function() {
            const d = $(this).data('dept');
            if (!d || d == deptId) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
        if ($('#section_id option:selected').is(':hidden')) {
            $('#section_id').val('');
        }
    });

    // Automatically sync study year and semester when typing Batch Years
    function updateBatchAndCohort() {
        let sYy = parseInt($('#batch_start_yy').val(), 10);
        let eYy = parseInt($('#batch_end_yy').val(), 10);
        if (isNaN(sYy) || isNaN(eYy)) return;

        let adm = sYy < 100 ? (2000 + sYy) : sYy;
        let grad = eYy < 100 ? (2000 + eYy) : eYy;

        if (grad < adm) {
            grad = adm + 4;
            $('#batch_end_yy').val(grad % 100);
        }

        let batchStr = adm + '–' + grad;
        $('#batch_name_input').val(batchStr);
        $('#batch_preview_badge').text(batchStr);

        let dur = grad - adm;
        $('#batch_dur_badge').text(dur === 3 ? '3-Year (Lateral)' : '4-Year Degree');

        let activeStart = <?php echo (int)$activeSessStart; ?>;
        let yearNum = (activeStart - adm) + (4 - dur) + 1;

        let studyYear = 'First Year';
        let sem = 1;

        if (grad <= activeStart || grad === (activeStart + 1) || yearNum >= 4) {
            studyYear = 'Final Year';
            sem = 7;
        } else if (yearNum === 3) {
            studyYear = 'Third Year';
            sem = 5;
        } else if (yearNum === 2) {
            studyYear = 'Second Year';
            sem = 3;
        } else {
            studyYear = 'First Year';
            sem = 1;
        }

        $('#year_of_study').val(studyYear);
        $('#current_semester').val(sem);
    }

    $('#batch_start_yy, #batch_end_yy').on('input change', function() {
        updateBatchAndCohort();
    });

    $('#year_of_study').on('change', function() {
        const yos = $(this).val();
        let targetSem = 7;
        if (yos === 'First Year') targetSem = 1;
        else if (yos === 'Second Year') targetSem = 3;
        else if (yos === 'Third Year') targetSem = 5;
        else if (yos === 'Final Year') targetSem = 7;
        $('#current_semester').val(targetSem);
    });

    // Initialize batch & cohort on load
    updateBatchAndCohort();

    // Handle AJAX Form Submission
    $('#addStudentForm').on('submit', function(e) {
        e.preventDefault();
        submitForm(false);
    });

    $('#btnSaveAndAddAnother').on('click', function() {
        submitForm(true);
    });

    function submitForm(addAnother) {
        const form = document.getElementById('addStudentForm');

        if (!form.checkValidity() || !isRegNoValid) {
            form.classList.add('was-validated');
            if (!isRegNoValid) {
                $('#registration_number').focus();
            }
            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Form',
                text: 'Please correct the highlighted fields before submitting.'
            });
            return;
        }

        const btnSubmit = $('#btnSubmitStudent');
        const btnAnother = $('#btnSaveAndAddAnother');
        const icon = $('#submitIcon');

        // Loading state
        btnSubmit.prop('disabled', true);
        btnAnother.prop('disabled', true);
        icon.removeClass('fa-user-plus fa-plus').addClass('fa-spinner fa-spin');

        $('#formActionInput').val('create_student');
        const formData = $('#addStudentForm').serialize();

        $.ajax({
            url: '../api/student-api.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                btnSubmit.prop('disabled', false);
                btnAnother.prop('disabled', false);
                icon.removeClass('fa-spinner fa-spin').addClass('fa-user-plus');
                $('#formActionInput').val('save_student');

                if (response && response.success) {
                    if (addAnother) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Student Created',
                            text: response.message || 'Student record added successfully.',
                            timer: 1800,
                            showConfirmButton: false
                        });
                        // Reset form fields
                        form.reset();
                        form.classList.remove('was-validated');
                        $('#regNoFeedback').html('');
                        $('#registration_number').removeClass('is-valid is-invalid');
                        $('#registration_number').focus();
                    } else {
                        Swal.fire({
                            icon: 'success',
                            title: 'Student Created Successfully',
                            text: response.message || 'Student record added successfully.',
                            showCancelButton: true,
                            confirmButtonColor: '#0B3D91',
                            cancelButtonColor: '#64748b',
                            confirmButtonText: '<i class="fa-solid fa-id-badge me-1"></i> View Profile',
                            cancelButtonText: '<i class="fa-solid fa-list me-1"></i> Go to Roster'
                        }).then((result) => {
                            if (result.isConfirmed && response.student_id) {
                                window.location.href = 'student-view.php?student_id=' + response.student_id;
                            } else {
                                window.location.href = 'students.php';
                            }
                        });
                    }
                } else {
                    let errMsg = (response && response.message) ? response.message : 'Failed to create student record.';
                    if (response && response.errors) {
                        errMsg = Object.values(response.errors).join('<br>');
                    }
                    Swal.fire({
                        icon: 'error',
                        title: 'Submission Error',
                        html: errMsg
                    });
                }
            },
            error: function(xhr, status, error) {
                btnSubmit.prop('disabled', false);
                btnAnother.prop('disabled', false);
                icon.removeClass('fa-spinner fa-spin').addClass('fa-user-plus');
                $('#formActionInput').val('save_student');

                let parsed = null;
                try {
                    let text = (xhr.responseText || '').trim();
                    let match = text.match(/\{[\s\S]*\}/);
                    if (match) {
                        parsed = JSON.parse(match[0]);
                    }
                } catch(e) {}

                if (parsed && parsed.success) {
                    if (addAnother) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Student Created',
                            text: parsed.message || 'Student record added successfully.',
                            timer: 1800,
                            showConfirmButton: false
                        });
                        form.reset();
                        form.classList.remove('was-validated');
                        $('#regNoFeedback').html('');
                        $('#registration_number').removeClass('is-valid is-invalid');
                        $('#registration_number').focus();
                    } else {
                        Swal.fire({
                            icon: 'success',
                            title: 'Student Created Successfully',
                            text: parsed.message || 'Student record added successfully.',
                            showCancelButton: true,
                            confirmButtonColor: '#0B3D91',
                            cancelButtonColor: '#64748b',
                            confirmButtonText: '<i class="fa-solid fa-id-badge me-1"></i> View Profile',
                            cancelButtonText: '<i class="fa-solid fa-list me-1"></i> Go to Roster'
                        }).then((result) => {
                            if (result.isConfirmed && parsed.student_id) {
                                window.location.href = 'student-view.php?student_id=' + parsed.student_id;
                            } else {
                                window.location.href = 'students.php';
                            }
                        });
                    }
                    return;
                }

                let errMsg = 'Network or server communication failure.';
                if (parsed && parsed.message) {
                    errMsg = parsed.message;
                } else if (parsed && parsed.errors) {
                    errMsg = Object.values(parsed.errors).join('<br>');
                }

                Swal.fire({
                    icon: 'error',
                    title: 'Server Error',
                    html: errMsg
                });
            }
        });
    }
});
</script>
