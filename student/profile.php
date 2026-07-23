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
?>

<div class="main-content">
    <header class="top-navbar">
        <div class="navbar-left">
            <button class="sidebar-toggle"><i class="fa-solid fa-bars"></i></button>
            <h4 class="mb-0 text-dark fw-bold">Manage My Profile</h4>
        </div>
        
        <div class="navbar-right">
            <div class="session-selector-container">
                <span class="small text-muted fw-bold d-none d-md-inline">Active Session:</span>
                <select class="session-selector-select" id="globalSessionSelector">
                    <?php foreach ($allSessions as $s): ?>
                        <option value="<?php echo $s['session_id']; ?>" <?php echo $s['session_id'] == $activeSessionId ? 'selected' : ''; ?>>
                            <?php echo esc($s['session_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="user-profile-dropdown">
                <div class="user-profile-img text-center d-flex align-items-center justify-content-center bg-primary text-white" style="width:36px;height:36px;border-radius:50%;font-weight:bold;">
                    <?php echo strtoupper(substr($student['student_name'], 0, 1)); ?>
                </div>
                <div class="user-profile-info d-none d-md-flex">
                    <span class="user-name"><?php echo esc($student['student_name']); ?></span>
                    <span class="user-role">Student</span>
                </div>
            </div>
        </div>
    </header>

    <div class="page-container">
        
        <div class="row">
            <!-- Left Side Details Summary -->
            <div class="col-lg-4 mb-4">
                <div class="mamcet-card">
                    <div class="card-body text-center pt-5">
                        <div class="user-profile-img mx-auto text-center d-flex align-items-center justify-content-center bg-primary text-white mb-3" style="width:80px;height:80px;border-radius:50%;font-size:2.5rem;font-weight:bold;">
                            <?php echo strtoupper(substr($student['student_name'], 0, 1)); ?>
                        </div>
                        <h4 class="fw-bold mb-1 text-dark"><?php echo esc($student['student_name']); ?></h4>
                        <span class="badge bg-secondary mb-3"><?php echo esc($student['registration_number']); ?></span>
                        
                        <div class="mb-4 text-start">
                            <label class="small text-muted fw-bold d-block mb-1">Profile Completeness: <?php echo $profileCompletion; ?>%</label>
                            <div class="progress" style="height: 10px; border-radius: 5px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $profileCompletion; ?>%"></div>
                            </div>
                        </div>

                        <hr>
                        
                        <div class="text-start mt-3">
                            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-lock text-muted me-2"></i> Read-Only Institutional Data</h6>
                            
                            <span class="d-block small text-muted">Department</span>
                            <span class="fw-bold d-block text-dark mb-2"><?php echo esc($student['dept_name']); ?></span>
                            
                            <span class="d-block small text-muted">Batch</span>
                            <span class="fw-bold d-block text-dark mb-2"><?php echo esc($student['batch_name']) . ' (Section ' . esc($student['section_name'] ?? 'N/A') . ')'; ?></span>
                            
                            <span class="d-block small text-muted">Academic CGPA</span>
                            <span class="fw-bold d-block text-dark mb-2"><?php echo esc($academics['current_cgpa'] ?? '0.00'); ?></span>

                            <span class="d-block small text-muted">Standing Arrears</span>
                            <span class="fw-bold d-block text-dark mb-2 text-<?php echo ($academics['standing_arrears'] ?? 0) > 0 ? 'danger' : 'success'; ?>"><?php echo (int)($academics['standing_arrears'] ?? 0); ?></span>
                            
                            <span class="d-block small text-muted">History of Arrears</span>
                            <span class="fw-bold d-block text-dark mb-2"><?php echo (int)($academics['history_of_arrears'] ?? 0); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side Editable Profile Forms -->
            <div class="col-lg-8 mb-4">
                <div class="mamcet-card">
                    <div class="card-header bg-white py-3">
                        <h5 class="card-title fw-bold mb-0 text-dark"><i class="fa-solid fa-user-pen text-primary me-2"></i> Portfolio & Personal Details</h5>
                    </div>
                    
                    <div class="card-body">
                        <form id="profileForm" action="../api/update-profile.php" method="POST" enctype="multipart/form-data">
                            <?php csrfInput(); ?>
                            
                            <h6 class="fw-bold border-bottom pb-2 mb-3 text-dark">1. Contact & Location</h6>
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

                            <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4 text-dark">2. Portfolios & Job Preferences</h6>
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

                            <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4 text-dark">3. Professional Experience & CV</h6>
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
                                <label class="form-label small fw-bold text-dark">Upload CV / Resume (PDF only, max 5MB)</label>
                                <input type="file" name="resume" class="form-control" accept="application/pdf">
                                <?php if (!empty($profile['resume_path'])): ?>
                                    <div class="form-text text-success">
                                        <i class="fa-solid fa-circle-check"></i> Current CV is uploaded: 
                                        <a href="../assets/uploads/resumes/<?php echo basename($profile['resume_path']); ?>" target="_blank" class="fw-bold text-success text-decoration-underline"><?php echo basename($profile['resume_path']); ?></a>
                                    </div>
                                <?php endif; ?>
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

