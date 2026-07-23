<?php
// MAMCET Placement & Learning Portal - Create/Edit Job Description Form
require_once(__DIR__ . '/../includes/header.php');

// Restrict to Officer / Admin
if ($roleId !== ROLE_PLACEMENT_OFFICER && $roleId !== ROLE_SUPER_ADMIN) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

$jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$job = null;
$selectedDepts = [];
$selectedBatches = [];
$selectedSkills = [];

// Load reference lists
$departments = $db->query("SELECT * FROM departments ORDER BY dept_code ASC")->fetchAll();
$batches = $db->query("SELECT * FROM batches ORDER BY batch_name DESC")->fetchAll();
$sessions = $db->query("SELECT * FROM academic_sessions ORDER BY start_year DESC")->fetchAll();

if ($jobId > 0) {
    // Load existing record
    $stmt = $db->prepare("SELECT * FROM job_descriptions WHERE job_id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    
    if (!$job) {
        header("Location: job-descriptions.php");
        exit;
    }

    // Load mappings
    $stmtD = $db->prepare("SELECT dept_id FROM job_description_departments WHERE job_id = ?");
    $stmtD->execute([$jobId]);
    $selectedDepts = $stmtD->fetchAll(PDO::FETCH_COLUMN);

    $stmtB = $db->prepare("SELECT batch_id FROM job_description_batches WHERE job_id = ?");
    $stmtB->execute([$jobId]);
    $selectedBatches = $stmtB->fetchAll(PDO::FETCH_COLUMN);

    $stmtS = $db->prepare("SELECT skill_name FROM job_description_skills WHERE job_id = ?");
    $stmtS->execute([$jobId]);
    $selectedSkills = $stmtS->fetchAll(PDO::FETCH_COLUMN);
}

// Handle Form Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        $company = trim($_POST['company_name']);
        $title = trim($_POST['job_title']);
        $role = trim($_POST['job_role']);
        $package = (float)$_POST['package_lpa'];
        $location = trim($_POST['work_location']);
        $exp = trim($_POST['experience_required']);
        $desc = trim($_POST['description']);
        $minCgpa = (float)$_POST['min_cgpa'];
        $maxArrears = (int)$_POST['max_standing_arrears'];
        $maxHistory = (int)$_POST['max_history_arrears'];
        $sessionId = (int)$_POST['session_id'];
        $gFormUrl = trim($_POST['google_form_url'] ?? '');
        $deadline = $_POST['application_deadline'];

        $deptsPost = $_POST['departments'] ?? [];
        $batchesPost = $_POST['batches'] ?? [];
        $skillsPost = array_filter(array_map('trim', explode(',', $_POST['skills_req'] ?? '')));

        if (empty($company) || empty($title) || empty($deadline)) {
            throw new Exception("Please complete all required fields.");
        }

        if ($jobId > 0) {
            // Update
            $stmtUpd = $db->prepare("
                UPDATE job_descriptions 
                SET company_name = ?, job_title = ?, job_role = ?, package_lpa = ?, 
                    work_location = ?, experience_required = ?, description = ?, 
                    min_cgpa = ?, max_standing_arrears = ?, max_history_arrears = ?, 
                    session_id = ?, google_form_url = ?, application_deadline = ? 
                WHERE job_id = ?
            ");
            $stmtUpd->execute([
                $company, $title, $role, $package, $location, $exp, $desc, 
                $minCgpa, $maxArrears, $maxHistory, $sessionId, $gFormUrl, $deadline, $jobId
            ]);
        } else {
            // Insert
            $stmtIns = $db->prepare("
                INSERT INTO job_descriptions 
                (company_name, job_title, job_role, package_lpa, work_location, experience_required, description, min_cgpa, max_standing_arrears, max_history_arrears, session_id, google_form_url, application_deadline) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtIns->execute([
                $company, $title, $role, $package, $location, $exp, $desc, 
                $minCgpa, $maxArrears, $maxHistory, $sessionId, $gFormUrl, $deadline
            ]);
            $jobId = (int)$db->lastInsertId();
        }

        // Sync Departments
        $db->prepare("DELETE FROM job_description_departments WHERE job_id = ?")->execute([$jobId]);
        $stmtDeptIns = $db->prepare("INSERT INTO job_description_departments (job_id, dept_id) VALUES (?, ?)");
        foreach ($deptsPost as $dId) {
            $stmtDeptIns->execute([$jobId, (int)$dId]);
        }

        // Sync Batches
        $db->prepare("DELETE FROM job_description_batches WHERE job_id = ?")->execute([$jobId]);
        $stmtBatchIns = $db->prepare("INSERT INTO job_description_batches (job_id, batch_id) VALUES (?, ?)");
        foreach ($batchesPost as $bId) {
            $stmtBatchIns->execute([$jobId, (int)$bId]);
        }

        // Sync Skills
        $db->prepare("DELETE FROM job_description_skills WHERE job_id = ?")->execute([$jobId]);
        $stmtSkillIns = $db->prepare("INSERT INTO job_description_skills (job_id, skill_name) VALUES (?, ?)");
        foreach ($skillsPost as $skName) {
            $stmtSkillIns->execute([$jobId, $skName]);
        }

        $db->commit();
        header("Location: job-descriptions.php");
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}
?>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content">
    <div class="container-fluid py-4">
            <h1 class="h3 mb-4 text-gray-800">
                <i class="fa-solid fa-file-contract text-primary"></i> 
                <?php echo $jobId > 0 ? 'Edit Job Description' : 'Create Job Description'; ?>
            </h1>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="card shadow mb-4">
                <div class="card-body">
                    <!-- Section 1: Company Profile -->
                    <h5 class="text-primary font-weight-bold mb-3 border-bottom pb-2">Company & Role Profile</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold">Company Name <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" class="form-control" value="<?php echo $job ? htmlspecialchars($job['company_name']) : ''; ?>" placeholder="e.g. Zoho Corporation" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold">Job Title <span class="text-danger">*</span></label>
                            <input type="text" name="job_title" class="form-control" value="<?php echo $job ? htmlspecialchars($job['job_title']) : ''; ?>" placeholder="e.g. Software Engineer Graduate Trainee" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label font-weight-bold">Job Role</label>
                            <input type="text" name="job_role" class="form-control" value="<?php echo $job ? htmlspecialchars($job['job_role']) : ''; ?>" placeholder="e.g. Developer / QA">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label font-weight-bold">Package (LPA) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="package_lpa" class="form-control" value="<?php echo $job ? htmlspecialchars($job['package_lpa']) : '4.50'; ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label font-weight-bold">Work Location</label>
                            <input type="text" name="work_location" class="form-control" value="<?php echo $job ? htmlspecialchars($job['work_location']) : ''; ?>" placeholder="e.g. Chennai / Remote">
                        </div>
                    </div>

                    <!-- Section 2: Requirements -->
                    <h5 class="text-primary font-weight-bold mb-3 mt-4 border-bottom pb-2">Qualifications & Target Candidates</h5>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label font-weight-bold">Min CGPA Required</label>
                            <input type="number" step="0.01" name="min_cgpa" class="form-control" value="<?php echo $job ? htmlspecialchars($job['min_cgpa']) : '6.00'; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label font-weight-bold">Max Standing Arrears</label>
                            <input type="number" name="max_standing_arrears" class="form-control" value="<?php echo $job ? htmlspecialchars($job['max_standing_arrears']) : '0'; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label font-weight-bold">Max History Arrears</label>
                            <input type="number" name="max_history_arrears" class="form-control" value="<?php echo $job ? htmlspecialchars($job['max_history_arrears']) : '2'; ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold d-block">Target Departments</label>
                            <div class="border rounded p-3" style="max-height: 150px; overflow-y: scroll;">
                                <?php foreach ($departments as $d): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="departments[]" value="<?php echo $d['dept_id']; ?>" id="deptCheckbox<?php echo $d['dept_id']; ?>"
                                            <?php echo in_array($d['dept_id'], $selectedDepts) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="deptCheckbox<?php echo $d['dept_id']; ?>">
                                            <?php echo htmlspecialchars($d['dept_name']); ?> (<?php echo $d['dept_code']; ?>)
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold d-block">Target Batches</label>
                            <div class="border rounded p-3" style="max-height: 150px; overflow-y: scroll;">
                                <?php foreach ($batches as $b): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="batches[]" value="<?php echo $b['batch_id']; ?>" id="batchCheckbox<?php echo $b['batch_id']; ?>"
                                            <?php echo in_array($b['batch_id'], $selectedBatches) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="batchCheckbox<?php echo $b['batch_id']; ?>">
                                            Batch <?php echo htmlspecialchars($b['batch_name']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Technical Skills, Deadline, Details -->
                    <h5 class="text-primary font-weight-bold mb-3 mt-4 border-bottom pb-2">Technical Skills & Drive Registry</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold">Academic Session</label>
                            <select class="form-select" name="session_id">
                                <?php foreach ($sessions as $s): ?>
                                    <option value="<?php echo $s['session_id']; ?>" <?php echo ($job && $job['session_id'] == $s['session_id']) || (!$job && $s['is_active'] == 1) ? 'selected' : ''; ?>>
                                        Session <?php echo htmlspecialchars($s['session_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold">Application Deadline <span class="text-danger">*</span></label>
                            <input type="date" name="application_deadline" class="form-control" value="<?php echo $job ? htmlspecialchars($job['application_deadline']) : ''; ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label font-weight-bold">Required Technical Skills (Comma-Separated)</label>
                        <input type="text" name="skills_req" class="form-control" value="<?php echo implode(', ', $selectedSkills); ?>" placeholder="e.g. Python, Java, SQL, Machine Learning">
                    </div>
                    <div class="mb-3">
                        <label class="form-label font-weight-bold">Google Registration Form URL</label>
                        <input type="url" name="google_form_url" class="form-control" value="<?php echo $job ? htmlspecialchars($job['google_form_url']) : ''; ?>" placeholder="e.g. https://forms.gle/yourform">
                    </div>
                    <div class="mb-3">
                        <label class="form-label font-weight-bold">Experience Required / Comments</label>
                        <input type="text" name="experience_required" class="form-control" value="<?php echo $job ? htmlspecialchars($job['experience_required']) : ''; ?>" placeholder="e.g. Freshers (2027 batch) only / 6 Months Internship experience">
                    </div>
                    <div class="mb-4">
                        <label class="form-label font-weight-bold">Detailed Job Description</label>
                        <textarea class="form-control" name="description" rows="5" placeholder="Paste structural JD guidelines here..."><?php echo $job ? htmlspecialchars($job['description']) : ''; ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-6">
                            <a href="job-descriptions.php" class="btn btn-outline-secondary w-100">Cancel</a>
                        </div>
                        <div class="col-6">
                            <button type="submit" class="btn btn-primary w-100 font-weight-bold">
                                <i class="fa-solid fa-cloud-arrow-up"></i> Save Job Description
                            </button>
                        </div>
                    </div>
                </div>
            </form>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
