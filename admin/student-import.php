<?php
// MAMCET Placement & Learning Portal - Excel Student Import Dashboard

$pageTitle = 'Import Students via Excel';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Super Admin and Placement Officer
requireRole([ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER]);

$db = Database::getInstance()->getConnection();

// Fetch departments, batches, sections for fallback pickers
$departments = $db->query("SELECT dept_id, dept_code, dept_name FROM departments ORDER BY dept_code")->fetchAll();
$batches = $db->query("SELECT batch_id, batch_name, graduation_year FROM batches ORDER BY graduation_year DESC")->fetchAll();
$sections = $db->query("SELECT section_id, section_name, dept_id FROM sections ORDER BY section_name")->fetchAll();
?>

<style>
.wizard-steps {
    display: flex;
    justify-content: space-between;
    margin-bottom: 2rem;
    position: relative;
}
.wizard-steps::before {
    content: '';
    position: absolute;
    top: 20px;
    left: 0;
    right: 0;
    height: 2px;
    background: #e2e8f0;
    z-index: 1;
}
.wizard-step {
    position: relative;
    z-index: 2;
    background: #fff;
    padding: 0 10px;
    text-align: center;
    flex: 1;
}
.wizard-step .step-num {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #f1f5f9;
    color: #64748b;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 6px;
    border: 2px solid #cbd5e1;
    transition: all 0.3s ease;
}
.wizard-step.active .step-num {
    background: var(--primary-color, #0B3D91);
    color: #fff;
    border-color: var(--primary-color, #0B3D91);
    box-shadow: 0 0 0 4px rgba(11, 61, 145, 0.15);
}
.wizard-step.completed .step-num {
    background: #10b981;
    color: #fff;
    border-color: #10b981;
}
.wizard-step .step-label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #64748b;
}
.wizard-step.active .step-label {
    color: var(--primary-color, #0B3D91);
    font-weight: 700;
}
.upload-dropzone {
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    padding: 40px 20px;
    text-align: center;
    background: #f8fafc;
    cursor: pointer;
    transition: all 0.2s ease;
}
.upload-dropzone:hover, .upload-dropzone.dragover {
    border-color: var(--primary-color, #0B3D91);
    background: #eff6ff;
}
.row-valid {
    background-color: rgba(16, 185, 129, 0.04);
}
.row-invalid {
    background-color: rgba(239, 68, 68, 0.06);
}
.row-duplicate {
    background-color: rgba(245, 158, 11, 0.06);
}
.row-existing {
    background-color: rgba(59, 130, 246, 0.06);
}
</style>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        <!-- Breadcrumb & Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="students.php" class="text-decoration-none">Student Roster</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Import Excel</li>
                    </ol>
                </nav>
                <h2 class="h4 fw-bold text-dark mb-0"><i class="fa-solid fa-file-excel text-success me-2"></i> Excel Student Import Wizard</h2>
            </div>
            <div class="d-flex gap-2">
                <a href="../api/student-import-api.php?action=download_template" class="btn btn-success btn-sm">
                    <i class="fa-solid fa-file-arrow-down me-1"></i> Download Sample Excel (.XLSX)
                </a>
                <a href="student-add.php" class="btn btn-outline-primary btn-sm">
                    <i class="fa-solid fa-user-plus me-1"></i> Add Single Student
                </a>
            </div>
        </div>

        <!-- Wizard Status Tracker -->
        <div class="wizard-steps">
            <div class="wizard-step active" id="step_tab_1">
                <div class="step-num">1</div>
                <div class="step-label">Upload & Options</div>
            </div>
            <div class="wizard-step" id="step_tab_2">
                <div class="step-num">2</div>
                <div class="step-label">Validate & Preview</div>
            </div>
            <div class="wizard-step" id="step_tab_3">
                <div class="step-num">3</div>
                <div class="step-label">Import Outcome</div>
            </div>
        </div>

        <!-- STEP 1: FILE UPLOAD & CONFIGURATION -->
        <div class="mamcet-card" id="step_panel_1">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-cloud-arrow-up text-primary me-2"></i> Step 1: Select Excel Spreadsheet and Defaults
                </h5>
            </div>
            <div class="card-body">
                <div class="row g-4 mb-4">
                    <div class="col-lg-7">
                        <label class="form-label fw-bold small text-dark mb-2">Upload Student Data Sheet (.xlsx / .xls / .csv / .xml)</label>
                        <label for="excelFileInput" class="upload-dropzone d-block w-100 mb-0" id="dropzone" style="cursor: pointer; user-select: none;">
                            <i class="fa-solid fa-file-excel fa-3x text-success mb-3"></i>
                            <h6 class="fw-bold text-dark mb-1">Drag & Drop your Excel or XML file here</h6>
                            <p class="text-muted small mb-3">or click to browse from your computer (.xlsx, .xls, .csv, .xml)</p>
                            <input type="file" id="excelFileInput" accept=".xlsx, .xls, .csv, .xml, text/xml, application/xml, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/vnd.ms-excel, text/csv" class="d-none">
                            <span class="btn btn-sm btn-primary px-3">
                                <i class="fa-solid fa-folder-open me-1"></i> Browse File
                            </span>
                            <div id="selectedFileInfo" class="mt-3 text-success fw-bold small d-none">
                                <i class="fa-solid fa-check-circle me-1"></i> <span id="selectedFileName"></span>
                            </div>
                        </label>
                    </div>

                    <div class="col-lg-5">
                        <div class="p-3 bg-light rounded border h-100">
                            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-sliders text-secondary me-2"></i> Fallback Target Parameters</h6>
                            <p class="text-muted small mb-3">
                                If your Excel file does not contain <code>dept_code</code>, <code>batch_name</code>, or <code>section_name</code> columns, the values selected below will be applied automatically:
                            </p>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Default Department</label>
                                <select id="default_dept_id" class="form-select form-select-sm">
                                    <option value="">-- Detect from Excel columns --</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?php echo $d['dept_id']; ?>"><?php echo esc($d['dept_name']) . ' (' . esc($d['dept_code']) . ')'; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Default Batch</label>
                                <select id="default_batch_id" class="form-select form-select-sm">
                                    <option value="">-- Detect from Excel columns --</option>
                                    <?php foreach ($batches as $b): ?>
                                        <option value="<?php echo $b['batch_id']; ?>"><?php echo esc($b['batch_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-bold">Default Section</label>
                                <select id="default_section_id" class="form-select form-select-sm">
                                    <option value="">-- None / Detect from Excel --</option>
                                    <?php foreach ($sections as $sec): ?>
                                        <option value="<?php echo $sec['section_id']; ?>">Section <?php echo esc($sec['section_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-success py-3 mb-4">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <i class="fa-solid fa-file-excel me-2"></i>
                            <strong>Need the standard template?</strong> Download our pre-formatted Microsoft Excel template (.xlsx) with sample rows, proper text formatting, and all required columns.
                        </div>
                        <a href="../api/student-import-api.php?action=download_template" class="btn btn-sm btn-success fw-bold text-white shadow-sm">
                            <i class="fa-solid fa-file-arrow-down me-1"></i> Download Sample (.XLSX)
                        </a>
                    </div>
                </div>

                <div class="text-end">
                    <button type="button" id="btnUploadPreview" class="btn btn-primary btn-lg" disabled>
                        <span id="btnUploadText"><i class="fa-solid fa-magnifying-glass me-1"></i> Upload & Validate Records</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- STEP 2: VALIDATION AND PREVIEW GRID -->
        <div class="mamcet-card d-none" id="step_panel_2">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="card-title fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-table-list text-primary me-2"></i> Step 2: Verification & Validation Summary
                </h5>
                <span class="badge bg-light text-dark border px-3 py-2" id="previewFileNameBadge"></span>
            </div>
            <div class="card-body">
                <!-- Metrics Summary Cards -->
                <div class="row g-2 mb-4 text-center">
                    <div class="col-6 col-md">
                        <div class="p-3 border rounded bg-light">
                            <h3 class="fw-bold mb-1 text-dark" id="stat_total">0</h3>
                            <span class="text-muted small">Total Rows</span>
                        </div>
                    </div>
                    <div class="col-6 col-md">
                        <div class="p-3 border rounded bg-success-subtle text-success">
                            <h3 class="fw-bold mb-1" id="stat_valid">0</h3>
                            <span class="small fw-bold">New Valid Records</span>
                        </div>
                    </div>
                    <div class="col-6 col-md">
                        <div class="p-3 border rounded bg-primary-subtle text-primary">
                            <h3 class="fw-bold mb-1" id="stat_existing">0</h3>
                            <span class="small fw-bold">Matches DB Record</span>
                        </div>
                    </div>
                    <div class="col-6 col-md">
                        <div class="p-3 border rounded bg-warning-subtle text-warning">
                            <h3 class="fw-bold mb-1" id="stat_duplicate">0</h3>
                            <span class="small fw-bold">Duplicates in File</span>
                        </div>
                    </div>
                    <div class="col-6 col-md">
                        <div class="p-3 border rounded bg-danger-subtle text-danger">
                            <h3 class="fw-bold mb-1" id="stat_invalid">0</h3>
                            <span class="small fw-bold">Invalid Records</span>
                        </div>
                    </div>
                </div>

                <!-- Duplicate Policy & Settings -->
                <div class="alert alert-secondary py-3 mb-4">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <h6 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-copy me-2 text-primary"></i> Duplicate Handling Policy</h6>
                            <p class="text-muted small mb-0">Choose how the system should handle records that match existing student registration numbers.</p>
                        </div>
                        <div class="col-md-5 mt-2 mt-md-0">
                            <select id="duplicate_strategy" class="form-select form-select-sm">
                                <option value="skip" selected>Skip / Ignore duplicate students (Recommended)</option>
                                <option value="overwrite">Overwrite / Update existing student records</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Preview Table -->
                <h6 class="fw-bold text-dark mb-2">Record Preview (Row-Level Validation)</h6>
                <div class="table-responsive mb-4" style="max-height: 420px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <table class="table table-sm table-hover align-middle mb-0" id="previewTable">
                        <thead class="table-light sticky-top" style="z-index: 5;">
                            <tr>
                                <th width="60">Row</th>
                                <th>Registration No</th>
                                <th>Student Name</th>
                                <th>Mobile</th>
                                <th>CGPA</th>
                                <th>Arrears</th>
                                <th>Validation State & Errors</th>
                            </tr>
                        </thead>
                        <tbody id="previewTableBody">
                            <!-- Injected via AJAX -->
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="backToStep1()">
                        <i class="fa-solid fa-arrow-left me-1"></i> Re-upload / Back
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" id="btnDownloadFailedInPreview" class="btn btn-outline-danger d-none" onclick="downloadFailedRows()">
                            <i class="fa-solid fa-file-arrow-down me-1"></i> Download Failed Rows (.XLSX)
                        </button>
                        <button type="button" id="btnExecuteImport" class="btn btn-success btn-lg" onclick="executeBatchImport()">
                            <i class="fa-solid fa-check-double me-1" id="execIcon"></i> Confirm & Import Valid Records
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- STEP 3: IMPORT OUTCOME SUMMARY -->
        <div class="mamcet-card d-none" id="step_panel_3">
            <div class="card-body text-center py-5">
                <div id="outcomeIconContainer" class="mb-4">
                    <i class="fa-solid fa-circle-check text-success fa-5x"></i>
                </div>
                <h3 class="fw-bold mb-2 text-dark" id="outcomeTitle">Import Completed Successfully!</h3>
                <p class="text-muted mb-4" id="outcomeSubtitle">The student registry has been updated in the college database.</p>

                <div class="p-4 border rounded bg-light d-inline-block text-start mb-4 shadow-sm" style="min-width: 320px; max-width: 480px; width: 100%;">
                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-chart-pie me-2 text-primary"></i> Final Import Summary</h6>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">New Students Added:</span>
                        <strong class="text-success" id="res_imported">0</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Existing Records Updated:</span>
                        <strong class="text-primary" id="res_updated">0</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Skipped Duplicates:</span>
                        <strong class="text-warning" id="res_skipped">0</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-top mt-2 pt-2">
                        <span class="text-muted">Failed / Invalid Records:</span>
                        <strong class="text-danger" id="res_failed">0</strong>
                    </div>
                </div>

                <div class="d-flex justify-content-center gap-3 flex-wrap">
                    <button type="button" id="btnDownloadFailedFinal" class="btn btn-outline-danger d-none" onclick="downloadFailedRows()">
                        <i class="fa-solid fa-file-arrow-down me-1"></i> Download Failed Rows (.XLSX)
                    </button>
                    <a href="students.php" class="btn btn-primary px-4">
                        <i class="fa-solid fa-users me-1"></i> View Student Roster
                    </a>
                    <button type="button" class="btn btn-outline-secondary" onclick="resetWizard()">
                        <i class="fa-solid fa-rotate-left me-1"></i> Import Another File
                    </button>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
let currentImportToken = '';
let selectedExcelFile = null;

$(document).ready(function() {
    // Dropzone interaction
    const dropzone = $('#dropzone');
    const fileInput = $('#excelFileInput');

    dropzone.on('dragover', function(e) {
        e.preventDefault();
        dropzone.addClass('dragover');
    });

    dropzone.on('dragleave drop', function(e) {
        e.preventDefault();
        dropzone.removeClass('dragover');
    });

    dropzone.on('drop', function(e) {
        const files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            handleFileSelection(files[0]);
        }
    });

    fileInput.on('change', function() {
        if (this.files.length > 0) {
            handleFileSelection(this.files[0]);
        }
    });

    function handleFileSelection(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        if (!['xlsx', 'xls', 'csv', 'txt', 'xml'].includes(ext)) {
            Swal.fire('Invalid File', 'Please select an Excel spreadsheet (.xlsx, .xls), CSV, or XML file.', 'warning');
            return;
        }

        selectedExcelFile = file;
        $('#selectedFileName').text(file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)');
        $('#selectedFileInfo').removeClass('d-none');
        $('#btnUploadPreview').prop('disabled', false);
    }

    // Step 1 -> Step 2 (Upload and Preview)
    $('#btnUploadPreview').on('click', function() {
        if (!selectedExcelFile) return;

        const btn = $(this);
        const origText = $('#btnUploadText').html();
        btn.prop('disabled', true);
        $('#btnUploadText').html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Parsing and Validating Spreadsheet...');

        const formData = new FormData();
        formData.append('action', 'upload_and_preview');
        formData.append('excel_file', selectedExcelFile);
        formData.append('default_dept_id', $('#default_dept_id').val());
        formData.append('default_batch_id', $('#default_batch_id').val());
        formData.append('default_section_id', $('#default_section_id').val());
        formData.append('csrf_token', '<?php echo getCsrfToken(); ?>');

        $.ajax({
            url: '../api/student-import-api.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                btn.prop('disabled', false);
                $('#btnUploadText').html(origText);

                if (res.success) {
                    currentImportToken = res.import_token;
                    $('#previewFileNameBadge').text(res.filename);

                    // Update summary stats
                    $('#stat_total').text(res.summary.total_rows);
                    $('#stat_valid').text(res.summary.valid_rows);
                    $('#stat_existing').text(res.summary.existing_rows);
                    $('#stat_duplicate').text(res.summary.duplicate_rows);
                    $('#stat_invalid').text(res.summary.invalid_rows);

                    // Alert if new academic sessions were created
                    if (res.summary.created_sessions && res.summary.created_sessions.length > 0) {
                        Swal.fire({
                            icon: 'info',
                            title: 'New Academic Session Created',
                            html: 'Automatically created new Academic Session(s): <strong>' + res.summary.created_sessions.join(', ') + '</strong> for these students.',
                            timer: 4000,
                            showConfirmButton: true
                        });
                    }

                    // Toggle failed rows download buttons
                    if (res.summary.invalid_rows > 0 || res.summary.duplicate_rows > 0) {
                        $('#btnDownloadFailedInPreview').removeClass('d-none');
                    } else {
                        $('#btnDownloadFailedInPreview').addClass('d-none');
                    }

                    // Render preview rows
                    let tbody = '';
                    res.preview_rows.forEach(function(row) {
                        let rowClass = 'row-valid';
                        let badge = '<span class="badge bg-success"><i class="fa-solid fa-check me-1"></i> Valid</span>';

                        if (row.status === 'invalid') {
                            rowClass = 'row-invalid';
                            badge = '<span class="badge bg-danger mb-1"><i class="fa-solid fa-xmark me-1"></i> Invalid</span>';
                            if (row.errors && row.errors.length > 0) {
                                badge += '<div class="small text-danger mt-1">' + row.errors.join('<br>') + '</div>';
                            }
                        } else if (row.status === 'file_duplicate') {
                            rowClass = 'row-duplicate';
                            badge = '<span class="badge bg-warning text-dark"><i class="fa-solid fa-copy me-1"></i> Duplicate in File</span>';
                            if (row.errors && row.errors.length > 0) {
                                badge += '<div class="small text-warning mt-1">' + row.errors.join('<br>') + '</div>';
                            }
                        } else if (row.status === 'db_duplicate') {
                            rowClass = 'row-existing';
                            badge = '<span class="badge bg-primary"><i class="fa-solid fa-database me-1"></i> Existing Student</span>';
                        }

                        tbody += `<tr class="${rowClass}">
                            <td class="fw-bold">${row.row_number}</td>
                            <td><strong>${row.registration_number}</strong></td>
                            <td>${row.student_name}</td>
                            <td>${row.mobile_number}</td>
                            <td>${row.current_cgpa || '-'}</td>
                            <td>${row.standing_arrears || '0'}</td>
                            <td>${badge}</td>
                        </tr>`;
                    });

                    $('#previewTableBody').html(tbody);

                    // Switch Wizard Panels
                    $('#step_panel_1').addClass('d-none');
                    $('#step_panel_2').removeClass('d-none');
                    $('#step_tab_1').removeClass('active').addClass('completed');
                    $('#step_tab_2').addClass('active');
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validation Failed',
                        text: res.message || 'Unable to parse spreadsheet.'
                    });
                }
            },
            error: function(xhr) {
                btn.prop('disabled', false);
                $('#btnUploadText').html(origText);
                Swal.fire('Error', 'Communication failure while uploading spreadsheet.', 'error');
            }
        });
    });
});

function backToStep1() {
    $('#step_panel_2').addClass('d-none');
    $('#step_panel_1').removeClass('d-none');
    $('#step_tab_2').removeClass('active');
    $('#step_tab_1').removeClass('completed').addClass('active');
}

function executeBatchImport() {
    if (!currentImportToken) return;

    const btn = $('#btnExecuteImport');
    const icon = $('#execIcon');
    const strategy = $('#duplicate_strategy').val();

    Swal.fire({
        title: 'Confirm Import Execution',
        text: 'Proceed with batch writing valid student records to the database?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0B3D91',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Execute Import!'
    }).then((result) => {
        if (result.isConfirmed) {
            btn.prop('disabled', true);
            icon.removeClass('fa-check-double').addClass('fa-spinner fa-spin');

            $.ajax({
                url: '../api/student-import-api.php',
                type: 'POST',
                data: {
                    action: 'execute_import',
                    import_token: currentImportToken,
                    on_duplicate: strategy,
                    csrf_token: '<?php echo getCsrfToken(); ?>'
                },
                dataType: 'json',
                success: function(res) {
                    btn.prop('disabled', false);
                    icon.removeClass('fa-spinner fa-spin').addClass('fa-check-double');

                    if (res.success) {
                        $('#res_imported').text(res.stats.imported);
                        $('#res_updated').text(res.stats.updated);
                        $('#res_skipped').text(res.stats.skipped);
                        $('#res_failed').text(res.stats.failed);

                        if (res.stats.has_failed_rows) {
                            $('#btnDownloadFailedFinal').removeClass('d-none');
                        } else {
                            $('#btnDownloadFailedFinal').addClass('d-none');
                        }

                        // Switch panels
                        $('#step_panel_2').addClass('d-none');
                        $('#step_panel_3').removeClass('d-none');
                        $('#step_tab_2').removeClass('active').addClass('completed');
                        $('#step_tab_3').addClass('completed');

                        Swal.fire({
                            icon: 'success',
                            title: 'Import Successful',
                            text: 'Batch import processed successfully.',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire('Import Error', res.message || 'Execution error during import.', 'error');
                    }
                },
                error: function() {
                    btn.prop('disabled', false);
                    icon.removeClass('fa-spinner fa-spin').addClass('fa-check-double');
                    Swal.fire('Error', 'Communication failure during import execution.', 'error');
                }
            });
        }
    });
}

function downloadFailedRows() {
    if (!currentImportToken) return;
    window.location.href = '../api/student-import-api.php?action=download_failed&import_token=' + encodeURIComponent(currentImportToken);
}

function resetWizard() {
    currentImportToken = '';
    selectedExcelFile = null;
    $('#excelFileInput').val('');
    $('#selectedFileInfo').addClass('d-none');
    $('#btnUploadPreview').prop('disabled', true);

    $('#step_panel_3').addClass('d-none');
    $('#step_panel_2').addClass('d-none');
    $('#step_panel_1').removeClass('d-none');

    $('#step_tab_3').removeClass('completed active');
    $('#step_tab_2').removeClass('completed active');
    $('#step_tab_1').removeClass('completed').addClass('active');
}
</script>
