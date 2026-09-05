<?php
// MAMCET Placement & Learning Portal - Student Profile Management Page

$pageTitle = 'My Profile';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Student
requireRole(ROLE_STUDENT);

$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);

if (!$student) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Student profile not associated with this login.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

$studentId = $student['student_id'];

// Calculate completion
$profileCompletion = calculateProfileCompletion($db, $studentId);

// Fetch profile detailed fields
$stmtSp = $db->prepare("SELECT * FROM student_profiles WHERE student_id = ?");
$stmtSp->execute([$studentId]);
$profile = $stmtSp->fetch();

if (!$profile) {
    // Create profile if missing
    $db->prepare("INSERT INTO student_profiles (student_id) VALUES (?)")->execute([$studentId]);
    $stmtSp->execute([$studentId]);
    $profile = $stmtSp->fetch();
}

// Fetch academic marks
$stmtSa = $db->prepare("SELECT * FROM student_academics WHERE student_id = ?");
$stmtSa->execute([$studentId]);
$academics = $stmtSa->fetch();

// Determine total semesters based on programme/batch duration or batch years
$durationYears = 4;
if (!empty($student['programme_duration']) && (int)$student['programme_duration'] > 0) {
    $durationYears = (int)$student['programme_duration'];
} elseif (!empty($student['duration_years']) && (int)$student['duration_years'] > 0) {
    $durationYears = (int)$student['duration_years'];
} elseif (!empty($student['admission_year']) && !empty($student['graduation_year']) && ((int)$student['graduation_year'] > (int)$student['admission_year'])) {
    $durationYears = (int)$student['graduation_year'] - (int)$student['admission_year'];
} elseif (!empty($student['batch_admission_year']) && !empty($student['batch_graduation_year']) && ((int)$student['batch_graduation_year'] > (int)$student['batch_admission_year'])) {
    $durationYears = (int)$student['batch_graduation_year'] - (int)$student['batch_admission_year'];
}
$totalSemesters = max(2, min(8, $durationYears * 2));

// If student has non-null marks recorded in higher semesters, ensure they are shown
for ($s = 8; $s > $totalSemesters; $s--) {
    if (isset($academics["sem{$s}_gpa"]) && $academics["sem{$s}_gpa"] !== null && $academics["sem{$s}_gpa"] !== '') {
        $totalSemesters = $s;
        break;
    }
}

$pageTitle = 'Manage My Profile';
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        
        <div class="row">
            <!-- Left Side Details Summary -->
            <div class="col-lg-4 mb-4">
                <div class="profile-hero-card">
                    <div class="profile-hero-banner"></div>
                    <div class="card-body text-center pt-0 px-4 pb-4">
                        <div class="profile-hero-avatar-wrapper">
                            <div class="profile-hero-avatar">
                                <?php echo strtoupper(substr($student['student_name'], 0, 1)); ?>
                            </div>
                            <span class="profile-status-indicator online" title="Student Active"></span>
                        </div>
                        
                        <h4 class="fw-bold mb-1 text-dark mt-2" style="font-family: var(--font-heading);">
                            <?php echo esc($student['student_name']); ?>
                        </h4>
                        
                        <div class="mb-3">
                            <span class="profile-copy-badge" onclick="navigator.clipboard.writeText('<?php echo esc($student['registration_number']); ?>'); if(typeof Swal !== 'undefined') Swal.fire({toast:true, position:'top-end', icon:'success', title:'Reg No copied!', showConfirmButton:false, timer:1500});" title="Click to copy Reg. No">
                                <span><?php echo esc($student['registration_number']); ?></span>
                                <i class="fa-regular fa-copy text-muted small"></i>
                            </span>
                        </div>
                        
                        <!-- Profile Completeness Bar -->
                        <div class="profile-meter-box text-start mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1.5">
                                <span class="small fw-bold text-dark">Profile Completion</span>
                                <span class="badge <?php echo $profileCompletion >= 80 ? 'bg-success' : ($profileCompletion >= 50 ? 'bg-primary' : 'bg-warning text-dark'); ?> rounded-pill">
                                    <?php echo $profileCompletion; ?>%
                                </span>
                            </div>
                            <div class="profile-meter-progress">
                                <div class="progress-bar <?php echo $profileCompletion >= 80 ? 'bg-success' : ($profileCompletion >= 50 ? 'bg-primary' : 'bg-warning'); ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo $profileCompletion; ?>%">
                                </div>
                            </div>
                        </div>

                        <hr class="my-3 text-muted opacity-25">
                        
                        <div class="profile-meta-group text-start">
                            <div class="profile-meta-tile">
                                <div class="profile-meta-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                                <div class="profile-meta-content">
                                    <span class="profile-meta-label">Department</span>
                                    <span class="profile-meta-val"><?php echo esc($student['dept_name']); ?></span>
                                </div>
                            </div>
                            
                            <div class="profile-meta-tile">
                                <div class="profile-meta-icon"><i class="fa-solid fa-users-rectangle"></i></div>
                                <div class="profile-meta-content">
                                    <span class="profile-meta-label">Batch & Section</span>
                                    <span class="profile-meta-val"><?php echo esc($student['batch_name']) . ' (' . esc($student['section_name'] ?? 'Section A') . ')'; ?></span>
                                </div>
                            </div>
                            
                            <div class="profile-meta-tile">
                                <div class="profile-meta-icon"><i class="fa-solid fa-chart-line"></i></div>
                                <div class="profile-meta-content">
                                    <span class="profile-meta-label">Academic CGPA</span>
                                    <span class="profile-meta-val text-primary"><?php echo number_format((float)($academics['current_cgpa'] ?? 0), 2); ?> / 10.00</span>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between gap-2 mt-1">
                                <div class="p-2 border rounded-3 bg-light text-center flex-grow-1">
                                    <span class="profile-meta-label mb-0">Standing Arrears</span>
                                    <strong class="<?php echo ($academics['standing_arrears'] ?? 0) > 0 ? 'text-danger' : 'text-success'; ?> fs-6">
                                        <?php echo (int)($academics['standing_arrears'] ?? 0); ?>
                                    </strong>
                                </div>
                                <div class="p-2 border rounded-3 bg-light text-center flex-grow-1">
                                    <span class="profile-meta-label mb-0">History Arrears</span>
                                    <strong class="text-dark fs-6"><?php echo (int)($academics['history_of_arrears'] ?? 0); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side Editable Profile Forms -->
            <div class="col-lg-8 mb-4">
                <div class="mamcet-card">
                    <div class="card-header bg-white py-3">
                        <h5 class="card-title fw-bold mb-0 text-dark"><i class="fa-solid fa-user-pen text-primary me-2"></i> Portfolio, Academic & Personal Details</h5>
                    </div>
                    
                    <div class="card-body">
                        <form id="profileForm" action="../api/update-profile.php" method="POST" enctype="multipart/form-data">
                            <?php csrfInput(); ?>
                            <input type="hidden" name="update_academics" value="1">
                            
                            <h6 class="fw-bold border-bottom pb-2 mb-3 text-dark"><i class="fa-solid fa-address-book text-secondary me-2"></i>1. Contact & Location</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small text-muted">Personal Email</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo esc($student['email']); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small text-muted">Alternative Mobile</label>
                                    <input type="text" name="alt_mobile_number" class="form-control" value="<?php echo esc($student['alt_mobile_number']); ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small text-muted">Address</label>
                                <input type="text" name="address" class="form-control" value="<?php echo esc($profile['address']); ?>">
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small text-muted">City</label>
                                    <input type="text" name="city" class="form-control" value="<?php echo esc($profile['city']); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small text-muted">District</label>
                                    <input type="text" name="district" class="form-control" value="<?php echo esc($profile['district']); ?>">
                                </div>
                            </div>

                            <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4 text-dark d-flex align-items-center justify-content-between">
                                <span><i class="fa-solid fa-graduation-cap text-primary me-2"></i>2. Academic Performance & Marks</span>
                                <small class="text-muted fw-normal fs-7">Updates CGPA & Placement Eligibility</small>
                            </h6>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small text-muted">10th Percentage (%)</label>
                                    <input type="number" step="0.01" min="0" max="100" name="tenth_percentage" class="form-control" placeholder="e.g. 85.50" value="<?php echo esc($academics['tenth_percentage'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small text-muted">12th Percentage (%)</label>
                                    <input type="number" step="0.01" min="0" max="100" name="twelfth_percentage" class="form-control" placeholder="e.g. 88.00" value="<?php echo esc($academics['twelfth_percentage'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small text-muted">Diploma Percentage (%) <span class="text-muted">(Optional)</span></label>
                                    <input type="number" step="0.01" min="0" max="100" name="diploma_percentage" class="form-control" placeholder="e.g. 90.00" value="<?php echo esc($academics['diploma_percentage'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small fw-bold text-dark">Overall Current CGPA (Out of 10.00)</label>
                                    <input type="number" step="0.01" min="0.00" max="10.00" name="current_cgpa" id="current_cgpa" class="form-control fw-bold border-primary text-primary" placeholder="e.g. 8.36" value="<?php echo esc($academics['current_cgpa'] ?? ''); ?>">
                                    <div class="form-text small">Auto-calculated from semester GPAs below (or edit directly).</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small text-muted">Standing Arrears (Current)</label>
                                    <input type="number" min="0" name="standing_arrears" class="form-control" placeholder="0" value="<?php echo esc($academics['standing_arrears'] ?? '0'); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small text-muted">History of Arrears (Total)</label>
                                    <input type="number" min="0" name="history_of_arrears" class="form-control" placeholder="0" value="<?php echo esc($academics['history_of_arrears'] ?? '0'); ?>">
                                </div>
                            </div>

                            <div class="p-3 bg-light rounded mb-4 border">
                                <label class="form-label small fw-bold text-dark mb-2">Semester-wise GPA Breakdown (Sem 1 to Sem <?php echo $totalSemesters; ?>)</label>
                                <div class="row g-2">
                                    <?php for ($i = 1; $i <= $totalSemesters; $i++): ?>
                                        <div class="col-6 col-md-3">
                                            <label class="form-label fs-7 text-muted mb-1">Sem <?php echo $i; ?> GPA</label>
                                            <input type="number" step="0.01" min="0" max="10" name="sem<?php echo $i; ?>_gpa" class="form-control form-control-sm sem-gpa-input" placeholder="0.00" value="<?php echo esc($academics["sem{$i}_gpa"] ?? ''); ?>">
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>

                            <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4 text-dark"><i class="fa-solid fa-briefcase text-secondary me-2"></i>3. Portfolios & Job Preferences</h6>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small text-muted">LinkedIn URL</label>
                                    <input type="url" name="linkedin_url" class="form-control" placeholder="https://linkedin.com/in/..." value="<?php echo esc($profile['linkedin_url']); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small text-muted">GitHub URL</label>
                                    <input type="url" name="github_url" class="form-control" placeholder="https://github.com/..." value="<?php echo esc($profile['github_url']); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small text-muted">Portfolio Website</label>
                                    <input type="url" name="portfolio_url" class="form-control" placeholder="https://..." value="<?php echo esc($profile['portfolio_url']); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small text-muted">Placement Willingness</label>
                                    <select name="placement_willingness" class="form-select">
                                        <option value="Yes" <?php echo $student['placement_willingness'] === 'Yes' ? 'selected' : ''; ?>>Willing / Yes</option>
                                        <option value="No" <?php echo $student['placement_willingness'] === 'No' ? 'selected' : ''; ?>>Not Willing / No</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small text-muted">Preferred Job Role</label>
                                    <input type="text" name="preferred_job_role" class="form-control" placeholder="e.g. Software Engineer" value="<?php echo esc($profile['preferred_job_role']); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small text-muted">Preferred Job Location</label>
                                    <input type="text" name="preferred_location" class="form-control" placeholder="e.g. Chennai, Bangalore" value="<?php echo esc($profile['preferred_location']); ?>">
                                </div>
                            </div>

                            <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4 text-dark"><i class="fa-solid fa-file-lines text-secondary me-2"></i>4. Professional Experience & CV</h6>
                            <div class="mb-3">
                                <label class="form-label small text-muted">Career Objective</label>
                                <textarea name="career_objective" class="form-control" rows="2" placeholder="Write a short pitch about your career goals..."><?php echo esc($profile['career_objective']); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small text-muted">Technical Skills (Comma separated)</label>
                                <input type="text" name="skills" class="form-control" placeholder="e.g. HTML5, CSS3, JavaScript, PHP, MySQL, Java" value="<?php echo esc($profile['skills']); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small text-muted">Academic Projects (Add details & links)</label>
                                <textarea name="projects" class="form-control" rows="3" placeholder="Project Name: Description (GitHub link)"><?php echo esc($profile['projects']); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small text-muted">Internships & Work Experience</label>
                                <textarea name="internships" class="form-control" rows="3" placeholder="Company: Duration, Role, Learning Outcomes"><?php echo esc($profile['internships']); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small text-muted">Certifications</label>
                                <textarea name="certifications" class="form-control" rows="3" placeholder="Certificate Title - Issuer (Verification Link)"><?php echo esc($profile['certifications']); ?></textarea>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold text-dark d-flex align-items-center justify-content-between mb-2">
                                    <span><i class="fa-solid fa-file-pdf text-danger me-2"></i>Upload CV / Resume (PDF only, max 5MB)</span>
                                </label>

                                <!-- Hidden input to track removal -->
                                <input type="hidden" name="remove_resume" id="remove_resume_input" value="0">

                                <!-- Active CV Card (Visible when CV is uploaded) -->
                                <div id="cvActiveCard" class="<?php echo !empty($profile['resume_path']) ? '' : 'd-none'; ?> mb-3">
                                    <div class="p-3 border rounded-3 bg-white shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-3" style="border-left: 4px solid #0d6efd !important;">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="bg-danger-subtle text-danger rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 50px; height: 50px; font-size: 1.6rem;">
                                                <i class="fa-solid fa-file-pdf"></i>
                                            </div>
                                            <div>
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <span id="cvFileNameDisplay" class="fw-bold text-dark fs-6 text-break"><?php echo !empty($profile['resume_path']) ? esc(basename($profile['resume_path'])) : ''; ?></span>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1" style="font-size: 0.75rem;">
                                                        <i class="fa-solid fa-circle-check me-1"></i> Current CV Uploaded
                                                    </span>
                                                </div>
                                                <div class="small text-muted mt-1">
                                                    <i class="fa-solid fa-circle-info text-primary me-1"></i> Document active for placement drives & ATS scanning.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="d-flex align-items-center gap-2 flex-wrap ms-auto">
                                            <a id="cvViewLink" href="../assets/uploads/resumes/<?php echo !empty($profile['resume_path']) ? esc(basename($profile['resume_path'])) : '#'; ?>" target="_blank" class="btn btn-sm btn-outline-primary fw-medium px-3 me-1">
                                                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> View CV
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-warning text-dark fw-medium px-3 me-1" id="btnReplaceCV">
                                                <i class="fa-solid fa-arrows-rotate me-1"></i> Replace CV
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger fw-medium px-3" id="btnRemoveCV">
                                                <i class="fa-solid fa-trash-can me-1"></i> Remove CV
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- File Input Container (Visible when no CV exists or when replacing) -->
                                <div id="cvUploadContainer" class="<?php echo !empty($profile['resume_path']) ? 'd-none' : ''; ?>">
                                    <div class="border border-2 border-dashed rounded-3 p-4 text-center bg-light position-relative" id="cvDropzone">
                                        <i class="fa-solid fa-cloud-arrow-up fs-1 text-primary mb-2 d-block"></i>
                                        <h6 class="fw-bold text-dark mb-1">Select a PDF File to Upload / Replace</h6>
                                        <p class="text-muted small mb-3">Accepted format: PDF only (.pdf) • Maximum file size: 5MB</p>
                                        
                                        <div class="d-inline-block text-start position-relative" style="max-width: 400px; width: 100%;">
                                            <input type="file" name="resume" id="resumeInput" class="form-control" accept="application/pdf">
                                        </div>

                                        <div id="selectedFileNotice" class="d-none mt-3 alert alert-success py-2 px-3 small d-inline-flex align-items-center gap-2">
                                            <i class="fa-solid fa-file-circle-check text-success fs-5"></i>
                                            <span id="selectedFileName"></span>
                                            <span class="badge bg-success">Ready to save</span>
                                        </div>
                                        
                                        <div id="replaceNotice" class="d-none mt-2 alert alert-warning py-2 px-3 small d-flex align-items-center justify-content-between mx-auto" style="max-width: 500px;">
                                            <span><i class="fa-solid fa-circle-exclamation me-1"></i> Uploading a new file will replace your currently active CV.</span>
                                            <button type="button" class="btn-close ms-2 btn-sm" id="btnCancelReplace" aria-label="Close"></button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="text-end">
                                <button type="submit" class="btn btn-primary btn-lg px-5">Save Profile Details</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
$(document).ready(function() {
    // Auto-calculate average CGPA when semester GPAs are updated
    $('.sem-gpa-input').on('input change', function() {
        let sum = 0;
        let count = 0;
        $('.sem-gpa-input').each(function() {
            let val = parseFloat($(this).val());
            if (!isNaN(val) && val > 0) {
                sum += val;
                count++;
            }
        });
        if (count > 0) {
            let avg = (sum / count).toFixed(2);
            $('#current_cgpa').val(avg);
        }
    });

    // CV Replace button click
    $('#btnReplaceCV').on('click', function() {
        $('#cvUploadContainer').removeClass('d-none');
        $('#replaceNotice').removeClass('d-none');
        $('#resumeInput').trigger('click');
    });

    // Cancel Replace button click
    $('#btnCancelReplace').on('click', function() {
        $('#resumeInput').val('');
        $('#selectedFileNotice').addClass('d-none');
        $('#replaceNotice').addClass('d-none');
        <?php if (!empty($profile['resume_path'])): ?>
            $('#cvUploadContainer').addClass('d-none');
            $('#cvActiveCard').removeClass('d-none');
        <?php endif; ?>
    });

    // File selection change
    $('#resumeInput').on('change', function() {
        const file = this.files[0];
        if (file) {
            if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                Swal.fire('Invalid File', 'Only PDF files (.pdf) are allowed.', 'error');
                $(this).val('');
                $('#selectedFileNotice').addClass('d-none');
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                Swal.fire('File Too Large', 'File size exceeds maximum allowed limit of 5MB.', 'error');
                $(this).val('');
                $('#selectedFileNotice').addClass('d-none');
                return;
            }
            const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
            $('#selectedFileName').html(`<strong>${file.name}</strong> (${sizeMb} MB)`);
            $('#selectedFileNotice').removeClass('d-none');
        } else {
            $('#selectedFileNotice').addClass('d-none');
        }
    });

    // Remove CV button click
    $('#btnRemoveCV').on('click', function() {
        Swal.fire({
            title: 'Remove Uploaded CV?',
            text: 'Are you sure you want to remove your current CV/Resume document from your profile?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fa-solid fa-trash-can me-1"></i> Yes, Remove CV',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Removing CV...',
                    text: 'Please wait while we update your profile.',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                $.ajax({
                    url: '../api/remove-resume.php',
                    type: 'POST',
                    data: {
                        csrf_token: $('meta[name="csrf-token"]').attr('content')
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'CV Removed',
                                text: response.message,
                                timer: 1500,
                                showConfirmButton: false
                            });

                            $('#cvActiveCard').addClass('d-none');
                            $('#cvUploadContainer').removeClass('d-none');
                            $('#replaceNotice').addClass('d-none');
                            $('#selectedFileNotice').addClass('d-none');
                            $('#resumeInput').val('');
                            $('#cvFileNameDisplay').text('');
                            $('#cvViewLink').attr('href', '#');
                        } else {
                            Swal.fire('Removal Failed', response.message || 'Error occurred while removing CV.', 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Communication failure with the server.', 'error');
                    }
                });
            }
        });
    });

    $('#profileForm').on('submit', function(e) {
        e.preventDefault();
        
        const form = $(this);
        const btn = form.find('button[type="submit"]');
        const origText = btn.text();
        
        btn.html('<i class="fa-solid fa-spinner fa-spin me-2"></i> Saving...').prop('disabled', true);
        
        // Handle file upload ajax
        const formData = new FormData(this);
        
        $.ajax({
            url: form.attr('action'),
            type: 'POST',
            data: formData,
            cache: false,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(response) {
                btn.html(origText).prop('disabled', false);
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Profile Updated',
                        text: response.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Update Failed', response.message || 'Error occurred during save.', 'error');
                }
            },
            error: function() {
                btn.html(origText).prop('disabled', false);
                Swal.fire('Error', 'Communication failure with the server.', 'error');
            }
        });
    });
});
</script>
