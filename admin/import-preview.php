<?php
// MAMCET Placement & Learning Portal - Excel Import Preview Page

$pageTitle = 'Dataset Import Preview';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

$fileId = (int)($_GET['file_id'] ?? 0);
if ($fileId <= 0) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Invalid Request. File ID is required.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

// Fetch file metadata
$stmt = $db->prepare("SELECT * FROM dataset_files WHERE file_id = ?");
$stmt->execute([$fileId]);
$fileRow = $stmt->fetch();

if (!$fileRow) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>File registry not found in database.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

// Read first row of Excel file for header mapping
require_once(__DIR__ . '/../includes/excel-helper.php');
$excelHeaders = [];
try {
    $filePath = $fileRow['filepath'];
    $extension = pathinfo($fileRow['filename'], PATHINFO_EXTENSION);
    $allRows = ExcelHelper::readSheet($filePath, $extension);
    if (!empty($allRows)) {
        $excelHeaders = $allRows[0];
    }
} catch (Exception $e) {
    $error = 'Error reading file headers: ' . $e->getMessage();
}

// Pre-detect auto mapping
$autoMapped = ExcelHelper::autoMapColumns($excelHeaders);

// Auto-detect and ensure academic session exists from Excel content or filename
$detectedSessionId = 0;
$detectedBatchId = 0;
$newSessionNotice = '';

if (!empty($allRows) && count($allRows) > 1) {
    $sessIdx = $autoMapped['academic_session'] ?? -1;
    $batchIdx = $autoMapped['batch_name'] ?? -1;

    for ($r = 1; $r < min(count($allRows), 30); $r++) {
        $row = $allRows[$r];
        
        // 1. Direct Academic Session column in Excel
        if ($sessIdx >= 0 && !empty($row[$sessIdx])) {
            $sRes = ensureAcademicSessionExists($db, $row[$sessIdx]);
            if (!empty($sRes['session_id'])) {
                $detectedSessionId = $sRes['session_id'];
                if (!empty($sRes['is_new'])) {
                    $newSessionNotice = "Automatically created new Academic Session: <strong>" . esc($sRes['session_name']) . "</strong> from file.";
                }
                break;
            }
        }

        // 2. Inferred from Batch column in Excel
        if ($batchIdx >= 0 && !empty($row[$batchIdx])) {
            $sRes = ensureAcademicSessionExists($db, $row[$batchIdx]);
            if (!empty($sRes['session_id'])) {
                $detectedSessionId = $sRes['session_id'];
                if (!empty($sRes['is_new'])) {
                    $newSessionNotice = "Automatically created new Academic Session: <strong>" . esc($sRes['session_name']) . "</strong> based on student batch.";
                }
            }
            if (empty($detectedBatchId)) {
                $studentService = new StudentService($db);
                $bId = $studentService->lookupBatchIdByName((string)$row[$batchIdx], (int)($fileRow['detected_dept_id'] ?? 0));
                if ($bId > 0) $detectedBatchId = $bId;
            }
            if ($detectedSessionId > 0 && $detectedBatchId > 0) break;
        }
    }
}

// Fallback to checking filename
if (empty($detectedSessionId) && !empty($fileRow['filename'])) {
    $sRes = ensureAcademicSessionExists($db, $fileRow['filename']);
    if (!empty($sRes['session_id'])) {
        $detectedSessionId = $sRes['session_id'];
        if (!empty($sRes['is_new'])) {
            $newSessionNotice = "Automatically created new Academic Session: <strong>" . esc($sRes['session_name']) . "</strong> from file name.";
        }
    }
}

// Fetch academic sessions, batches, departments, sections
$sessions = $db->query("SELECT * FROM academic_sessions ORDER BY start_year DESC")->fetchAll();
$batches = $db->query("SELECT * FROM batches ORDER BY graduation_year DESC")->fetchAll();
$departments = $db->query("SELECT dept_id, dept_code, dept_name FROM departments ORDER BY dept_code")->fetchAll();
$sections = $db->query("SELECT s.*, d.dept_code FROM sections s JOIN departments d ON s.dept_id = d.dept_id ORDER BY d.dept_code, s.section_name")->fetchAll();

$selectedSessionId = $detectedSessionId > 0 ? $detectedSessionId : ($activeSessionId ?? 1);
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        <!-- Wizard Status Tracker -->
        <div class="wizard-steps">
            <div class="wizard-step active" id="stepIndicator_1">
                <div class="step-num">1</div>
                <div class="step-label">Configurations</div>
            </div>
            <div class="wizard-step" id="stepIndicator_2">
                <div class="step-num">2</div>
                <div class="step-label">Column Mapping</div>
            </div>
            <div class="wizard-step" id="stepIndicator_3">
                <div class="step-num">3</div>
                <div class="step-label">Validate & Preview</div>
            </div>
            <div class="wizard-step" id="stepIndicator_4">
                <div class="step-num">4</div>
                <div class="step-label">Import Complete</div>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo esc($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($newSessionNotice)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-sparkles text-primary me-2"></i> <?php echo $newSessionNotice; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- STEP 1: CONFIGURATIONS PANEL -->
        <div class="mamcet-card" id="panel_step_1">
            <div class="card-header">
                <h5 class="card-title fw-bold"><i class="fa-solid fa-gears text-primary me-2"></i> Session & Target Selection</h5>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded">
                            <span class="text-muted small">File Selected:</span>
                            <span class="fw-bold d-block text-dark"><?php echo esc($fileRow['filename']); ?></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded">
                            <span class="text-muted small">Auto-Detected Dept:</span>
                            <span class="fw-bold d-block text-dark">
                                <?php
                                $found = false;
                                foreach ($departments as $d) {
                                    if ($d['dept_id'] == $fileRow['detected_dept_id']) {
                                        echo esc($d['dept_name']) . " (" . esc($d['dept_code']) . ")";
                                        $found = true;
                                        break;
                                    }
                                }
                                if (!$found) echo "None - Select below";
                                ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Academic Session</label>
                        <select id="sel_session_id" class="form-select">
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?php echo $s['session_id']; ?>" <?php echo $s['session_id'] == $selectedSessionId ? 'selected' : ''; ?>><?php echo esc($s['session_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Target Programme Batch</label>
                        <select id="sel_batch_id" class="form-select">
                            <option value="">Select Batch</option>
                            <?php foreach ($batches as $b): ?>
                                <option value="<?php echo $b['batch_id']; ?>" <?php echo $detectedBatchId > 0 && $b['batch_id'] == $detectedBatchId ? 'selected' : ''; ?>><?php echo esc($b['batch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Department</label>
                        <select id="sel_dept_id" class="form-select">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo $d['dept_id']; ?>" <?php echo $d['dept_id'] == $fileRow['detected_dept_id'] ? 'selected' : ''; ?>><?php echo esc($d['dept_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-4">
                        <label class="form-label fw-bold">Section (Optional)</label>
                        <select id="sel_section_id" class="form-select">
                            <option value="">All Sections / Not Specified</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?php echo $sec['section_id']; ?>" data-dept="<?php echo $sec['dept_id']; ?>"><?php echo esc($sec['dept_code']) . ' - ' . esc($sec['section_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="text-end">
                    <button class="btn btn-primary btn-lg" onclick="nextToStep2()">Next: Map Columns <i class="fa-solid fa-arrow-right ms-1"></i></button>
                </div>
            </div>
        </div>

        <!-- STEP 2: INTERACTIVE COLUMN MAPPING -->
        <div class="mamcet-card d-none" id="panel_step_2">
            <div class="card-header">
                <h5 class="card-title fw-bold"><i class="fa-solid fa-arrows-split-up-and-left text-primary me-2"></i> Map Excel Headers to Database Fields</h5>
            </div>
            <div class="card-body">
                <p class="text-muted mb-4">Match each student database parameter with the correct Excel sheet column header. Unmapped columns will be skipped.</p>

                <div class="row" id="mappingForm">
                    <!-- Column Mappings Loop -->
                    <?php
                    $fieldsToMap = [
                        'registration_number' => ['label' => 'Registration Number', 'required' => true],
                        'student_name' => ['label' => 'Student Name', 'required' => true],
                        'mobile_number' => ['label' => 'Mobile Number', 'required' => true],
                        'alt_mobile_number' => ['label' => 'Parent / Alt Mobile', 'required' => false],
                        'email' => ['label' => 'Personal Email', 'required' => false],
                        'college_email' => ['label' => 'College Email', 'required' => false],
                        'dob' => ['label' => 'Date of Birth', 'required' => false],
                        'gender' => ['label' => 'Gender', 'required' => false],
                        'blood_group' => ['label' => 'Blood Group', 'required' => false],
                        'aadhar_number' => ['label' => 'Aadhar Number', 'required' => false],
                        'hosteller_status' => ['label' => 'Hosteller (Yes/No)', 'required' => false],
                        'tenth_percentage' => ['label' => '10th Percentage (%)', 'required' => false],
                        'twelfth_percentage' => ['label' => '12th Percentage (%)', 'required' => false],
                        'diploma_percentage' => ['label' => 'Diploma Percentage (%)', 'required' => false],
                        'current_cgpa' => ['label' => 'Current CGPA', 'required' => false],
                        'standing_arrears' => ['label' => 'Current standing arrears', 'required' => false],
                        'history_of_arrears' => ['label' => 'History of arrears count', 'required' => false],
                        'sem1_gpa' => ['label' => 'Semester 1 GPA', 'required' => false],
                        'sem2_gpa' => ['label' => 'Semester 2 GPA', 'required' => false],
                        'sem3_gpa' => ['label' => 'Semester 3 GPA', 'required' => false],
                        'sem4_gpa' => ['label' => 'Semester 4 GPA', 'required' => false],
                        'sem5_gpa' => ['label' => 'Semester 5 GPA', 'required' => false],
                        'sem6_gpa' => ['label' => 'Semester 6 GPA', 'required' => false],
                        'sem7_gpa' => ['label' => 'Semester 7 GPA', 'required' => false],
                        'sem8_gpa' => ['label' => 'Semester 8 GPA', 'required' => false]
                    ];
                    
                    foreach ($fieldsToMap as $dbKey => $field):
                        $matchedIdx = isset($autoMapped[$dbKey]) ? $autoMapped[$dbKey] : '';
                    ?>
                        <div class="col-md-6 mb-3">
                            <label class="form-label <?php echo $field['required'] ? 'fw-bold text-dark' : 'text-secondary'; ?>">
                                <?php echo esc($field['label']); ?>
                                <?php if ($field['required']): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>
                            <select class="form-select mapping-select" data-field="<?php echo $dbKey; ?>" required>
                                <option value="">-- Choose Column --</option>
                                <?php foreach ($excelHeaders as $idx => $hdr): ?>
                                    <option value="<?php echo $idx; ?>" <?php echo $matchedIdx !== '' && $matchedIdx == $idx ? 'selected' : ''; ?>>
                                        Col <?php echo $idx + 1; ?>: <?php echo esc($hdr); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-light border" onclick="backToStep1()"><i class="fa-solid fa-arrow-left me-1"></i> Back</button>
                    <button class="btn btn-primary btn-lg" onclick="nextToStep3()">Validate and Preview <i class="fa-solid fa-magnifying-glass ms-1"></i></button>
                </div>
            </div>
        </div>

        <!-- STEP 3: VALIDATION AND PREVIEW GRID -->
        <div class="mamcet-card d-none" id="panel_step_3">
            <div class="card-header">
                <h5 class="card-title fw-bold"><i class="fa-solid fa-magnifying-glass text-primary me-2"></i> Verification & Validation Summary</h5>
            </div>
            <div class="card-body">
                <div class="row text-center mb-4 g-2" id="validationSummary">
                    <div class="col-6 col-md-2">
                        <div class="p-3 border rounded bg-light">
                            <h4 class="fw-bold mb-1" id="sum_total_rows">0</h4>
                            <span class="text-muted small">Total Rows</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="p-3 border rounded bg-success-subtle text-success">
                            <h4 class="fw-bold mb-1" id="sum_valid_rows">0</h4>
                            <span class="small">New Valid Rows</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="p-3 border rounded bg-danger-subtle text-danger">
                            <h4 class="fw-bold mb-1" id="sum_invalid_rows">0</h4>
                            <span class="small">Errors found</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="p-3 border rounded bg-warning-subtle text-warning">
                            <h4 class="fw-bold mb-1" id="sum_duplicate_rows">0</h4>
                            <span class="small">File Duplicates</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="p-3 border rounded bg-primary-subtle text-primary">
                            <h4 class="fw-bold mb-1" id="sum_existing_rows">0</h4>
                            <span class="small">Existing in DB</span>
                        </div>
                    </div>
                </div>

                <!-- Duplicate settings selection -->
                <div class="alert alert-secondary py-3 mb-4">
                    <h6 class="fw-bold mb-2">Duplicate & Override Strategy</h6>
                    <div class="row">
                        <div class="col-md-6 mb-2 mb-md-0">
                            <label class="form-label small">Action for duplicates in DB</label>
                            <select id="import_on_duplicate" class="form-select form-select-sm">
                                <option value="skip" selected>Skip / Ignore duplicate students</option>
                                <option value="overwrite">Overwrite / Update existing students</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-center">
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" id="import_overwrite_student" value="1">
                                <label class="form-check-label small" for="import_overwrite_student">
                                    Force overwrite student-updated profile parameters (email, links, resumes)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold mb-3">Validation Roster Preview (First 50 Rows)</h6>
                <div class="table-responsive mb-4" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-sm table-bordered" id="previewGridTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Row</th>
                                <th>Registration</th>
                                <th>Name</th>
                                <th>Mobile</th>
                                <th>CGPA</th>
                                <th>Arrears</th>
                                <th>Validation State / Errors</th>
                            </tr>
                        </thead>
                        <tbody id="previewGridBody">
                            <!-- Visual grid items will render dynamically -->
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between">
                    <button class="btn btn-light border" onclick="backToStep2()"><i class="fa-solid fa-arrow-left me-1"></i> Back</button>
                    <div>
                        <a href="students.php" class="btn btn-outline-danger me-2">Cancel</a>
                        <button class="btn btn-success btn-lg" id="btnExecuteImport" onclick="executeImport()">
                            <i class="fa-solid fa-cloud-arrow-up me-1"></i> Confirm & Import
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- STEP 4: IMPORT COMPLETION -->
        <div class="mamcet-card d-none" id="panel_step_4">
            <div class="card-body text-center py-5">
                <i class="fa-solid fa-circle-check text-success fa-5x mb-4"></i>
                <h3 class="fw-bold mb-3">Student Records Imported Successfully!</h3>
                <p class="text-muted mb-4" id="importResultsText">All valid student details have been processed and loaded into the college database registry.</p>
                
                <div class="p-3 border rounded bg-light d-inline-block text-start mb-4" style="min-width: 260px;">
                    <span class="d-block">New Records Added: <strong class="text-success" id="res_imported">0</strong></span>
                    <span class="d-block">Existing Updated: <strong class="text-primary" id="res_updated">0</strong></span>
                    <span class="d-block">Skipped / Ignored: <strong class="text-muted" id="res_skipped">0</strong></span>
                </div>
                
                <div class="d-block">
                    <a href="students.php" class="btn btn-primary me-2">View Student Roster</a>
                    <a href="student-import.php" class="btn btn-outline-secondary">Import Another File</a>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
let currentImportId = 0;

function nextToStep2() {
    const batch = $('#sel_batch_id').val();
    const dept = $('#sel_dept_id').val();
    
    if (!batch || !dept) {
        Swal.fire('Fields Required', 'Please select a batch and department to target the import.', 'warning');
        return;
    }
    
    // Filter sections based on department
    $('#sel_section_id option').each(function() {
        const d = $(this).data('dept');
        if (d && d != dept) {
            $(this).hide();
        } else {
            $(this).show();
        }
    });
    
    $('#panel_step_1').addClass('d-none');
    $('#panel_step_2').removeClass('d-none');
    $('#stepIndicator_1').removeClass('active').addClass('completed');
    $('#stepIndicator_2').addClass('active');
}

function backToStep1() {
    $('#panel_step_2').addClass('d-none');
    $('#panel_step_1').removeClass('d-none');
    $('#stepIndicator_2').removeClass('active');
    $('#stepIndicator_1').removeClass('completed').addClass('active');
}

function nextToStep3() {
    // Collect mappings
    const mappings = {};
    let missingRequired = false;
    
    $('.mapping-select').each(function() {
        const field = $(this).data('field');
        const val = $(this).val();
        const required = $(this).prop('required');
        
        if (required && !val) {
            missingRequired = true;
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
        
        if (val) {
            mappings[field] = val;
        }
    });
    
    if (missingRequired) {
        Swal.fire('Required Mapping', 'Please map all mandatory fields highlighted in bold (Registration Number, Name, Mobile).', 'warning');
        return;
    }
    
    // Start AJAX Validation
    Swal.fire({
        title: 'Verifying Records',
        text: 'Parsing rows and testing database constraints...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    $.ajax({
        url: '../api/validate-dataset.php',
        type: 'POST',
        data: {
            file_id: '<?php echo $fileId; ?>',
            session_id: $('#sel_session_id').val(),
            batch_id: $('#sel_batch_id').val(),
            programme_id: 1, // Default B.Tech mapped
            dept_id: $('#sel_dept_id').val(),
            section_id: $('#sel_section_id').val(),
            mappings: mappings,
            csrf_token: '<?php echo isset($_SESSION["csrf_token"]) ? $_SESSION["csrf_token"] : ""; ?>'
        },
        dataType: 'json',
        success: function(response) {
            Swal.close();
            if (response.success) {
                currentImportId = response.import_id;
                
                // Set stats summary values
                $('#sum_total_rows').text(response.summary.total_rows);
                $('#sum_valid_rows').text(response.summary.valid_rows);
                $('#sum_invalid_rows').text(response.summary.invalid_rows);
                $('#sum_duplicate_rows').text(response.summary.duplicate_rows);
                $('#sum_existing_rows').text(response.summary.existing_rows);
                
                // Populate visual preview grid
                let tbody = '';
                response.preview_rows.forEach(function(row) {
                    let rowClass = 'row-valid';
                    let statusLabel = '<span class="text-success fw-bold"><i class="fa-solid fa-check me-1"></i> Valid</span>';
                    
                    if (!row.is_valid) {
                        rowClass = 'row-invalid';
                        statusLabel = '<span class="text-danger fw-bold d-block"><i class="fa-solid fa-circle-xmark me-1"></i> Invalid Row</span>';
                        row.errors.forEach(err => {
                            statusLabel += '<small class="text-danger d-block">- ' + err + '</small>';
                        });
                    } else if (row.status === 'skipped') {
                        rowClass = 'row-existing';
                        statusLabel = '<span class="text-primary fw-bold"><i class="fa-solid fa-database me-1"></i> Matches DB Record</span>';
                    } else if (row.status === 'duplicate') {
                        rowClass = 'row-warning';
                        statusLabel = '<span class="text-warning fw-bold"><i class="fa-solid fa-copy me-1"></i> File Duplicate</span>';
                    }
                    
                    tbody += `<tr class="${rowClass}">
                        <td>${row.row_number}</td>
                        <td>${row.data.registration_number || 'N/A'}</td>
                        <td>${row.data.student_name || 'N/A'}</td>
                        <td>${row.data.mobile_number || 'N/A'}</td>
                        <td>${row.data.current_cgpa || 'N/A'}</td>
                        <td>${row.data.standing_arrears || '0'}</td>
                        <td>${statusLabel}</td>
                    </tr>`;
                });
                
                $('#previewGridBody').html(tbody);
                
                // Transition panels
                $('#panel_step_2').addClass('d-none');
                $('#panel_step_3').removeClass('d-none');
                $('#stepIndicator_2').removeClass('active').addClass('completed');
                $('#stepIndicator_3').addClass('active');
            } else {
                Swal.fire('Validation Error', response.message || 'Unable to complete validation.', 'error');
            }
        },
        error: function(xhr, status, error) {
            Swal.close();
            Swal.fire('Error', 'Communication failure: ' + error, 'error');
        }
    });
}

function backToStep2() {
    $('#panel_step_3').addClass('d-none');
    $('#panel_step_2').removeClass('d-none');
    $('#stepIndicator_3').removeClass('active');
    $('#stepIndicator_2').removeClass('completed').addClass('active');
}

function executeImport() {
    if (currentImportId <= 0) return;
    
    Swal.fire({
        title: 'Executing Database Write',
        text: 'Saving students profiles and academic marks...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    $.ajax({
        url: '../api/import-excel.php',
        type: 'POST',
        data: {
            import_id: currentImportId,
            on_duplicate: $('#import_on_duplicate').val(),
            overwrite_student_fields: $('#import_overwrite_student').is(':checked') ? '1' : '0',
            csrf_token: '<?php echo isset($_SESSION["csrf_token"]) ? $_SESSION["csrf_token"] : ""; ?>'
        },
        dataType: 'json',
        success: function(response) {
            Swal.close();
            if (response.success) {
                $('#res_imported').text(response.stats.imported);
                $('#res_updated').text(response.stats.updated);
                $('#res_skipped').text(response.stats.skipped);
                
                $('#panel_step_3').addClass('d-none');
                $('#panel_step_4').removeClass('d-none');
                $('#stepIndicator_3').removeClass('active').addClass('completed');
                $('#stepIndicator_4').addClass('completed');
                
                Swal.fire('Success', 'Student database populated successfully.', 'success');
            } else {
                Swal.fire('Import Failed', response.message || 'Error occurred.', 'error');
            }
        },
        error: function(xhr, status, error) {
            Swal.close();
            Swal.fire('Error', 'Import failed with script error: ' + error, 'error');
        }
    });
}
</script>

