<?php
// MAMCET Placement & Learning Portal - ATS-Friendly Resume Builder
require_once(__DIR__ . '/../includes/header.php');

// Restrict to student only
if ($roleId !== ROLE_STUDENT) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

$db = Database::getInstance()->getConnection();
$studentId = (int)$_SESSION['student_id'];
$message = '';
$error = '';

// 1. Fetch student data to initialize builder profile
$stmtStud = $db->prepare("
    SELECT s.*, sa.tenth_percentage, sa.twelfth_percentage, sa.diploma_percentage, 
           sa.current_cgpa, sa.standing_arrears, sa.history_of_arrears,
           d.dept_name, b.batch_name
    FROM students s
    LEFT JOIN student_academics sa ON s.student_id = sa.student_id
    LEFT JOIN departments d ON s.dept_id = d.dept_id
    LEFT JOIN batches b ON s.batch_id = b.batch_id
    WHERE s.student_id = ?
");
$stmtStud->execute([$studentId]);
$student = $stmtStud->fetch();

if (!$student) {
    header("Location: " . $baseDir . "index.php");
    exit;
}

// 2. Fetch or create customized resume builder profile
$stmtProfile = $db->prepare("SELECT * FROM resume_builder_profiles WHERE student_id = ?");
$stmtProfile->execute([$studentId]);
$profile = $stmtProfile->fetch();

// Default values if no profile exists
if (!$profile) {
    $defaultObjective = "Seeking a challenging opportunity in a progressive organization where I can utilize my technical skills and academic background to contribute to the growth of the company.";
    $defaultLanguages = "English, Tamil";
    $defaultDeclaration = "I hereby declare that all the information provided above is true and correct to the best of my knowledge.";
    
    // Auto-fill from student skills (stored as JSON)
    $skills = [];
    if (!empty($student['skills'])) {
        $skills = json_decode($student['skills'], true) ?: explode(',', $student['skills']);
    }

    $stmtIns = $db->prepare("
        INSERT INTO resume_builder_profiles 
        (student_id, career_objective, languages, declaration, skills_json, projects_json, internships_json, certifications_json, achievements_json, workshops_json) 
        VALUES (?, ?, ?, ?, ?, '[]', '[]', '[]', '[]', '[]')
    ");
    $stmtIns->execute([
        $studentId, 
        $defaultObjective, 
        $defaultLanguages, 
        $defaultDeclaration, 
        json_encode($skills)
    ]);
    
    $stmtProfile->execute([$studentId]);
    $profile = $stmtProfile->fetch();
}

// 3. Process Save Request
if (isset($_POST['save_draft'])) {
    try {
        $objective = trim($_POST['career_objective']);
        $languages = trim($_POST['languages']);
        $declaration = trim($_POST['declaration']);
        
        // Parse skills, projects, internships lists
        $skillsList = array_map('trim', explode(',', $_POST['skills_list']));
        
        $projects = [];
        if (isset($_POST['project_title'])) {
            foreach ($_POST['project_title'] as $idx => $title) {
                if (!empty($title)) {
                    $projects[] = [
                        'title' => $title,
                        'tech' => $_POST['project_tech'][$idx] ?? '',
                        'desc' => $_POST['project_desc'][$idx] ?? ''
                    ];
                }
            }
        }

        $internships = [];
        if (isset($_POST['intern_company'])) {
            foreach ($_POST['intern_company'] as $idx => $company) {
                if (!empty($company)) {
                    $internships[] = [
                        'company' => $company,
                        'role' => $_POST['intern_role'][$idx] ?? '',
                        'duration' => $_POST['intern_duration'][$idx] ?? '',
                        'desc' => $_POST['intern_desc'][$idx] ?? ''
                    ];
                }
            }
        }

        $certs = array_filter(array_map('trim', explode("\n", $_POST['certifications_list'])));
        $achievements = array_filter(array_map('trim', explode("\n", $_POST['achievements_list'])));
        $workshops = array_filter(array_map('trim', explode("\n", $_POST['workshops_list'])));

        // Update database
        $stmtUpd = $db->prepare("
            UPDATE resume_builder_profiles 
            SET career_objective = ?, languages = ?, declaration = ?, 
                skills_json = ?, projects_json = ?, internships_json = ?, 
                certifications_json = ?, achievements_json = ?, workshops_json = ? 
            WHERE student_id = ?
        ");
        $stmtUpd->execute([
            $objective,
            $languages,
            $declaration,
            json_encode($skillsList),
            json_encode($projects),
            json_encode($internships),
            json_encode(array_values($certs)),
            json_encode(array_values($achievements)),
            json_encode(array_values($workshops)),
            $studentId
        ]);

        $message = "Resume draft saved successfully!";
        
        // Refresh profile data
        $stmtProfile->execute([$studentId]);
        $profile = $stmtProfile->fetch();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 4. Save generated text to resume files when marked as active resume
if (isset($_POST['publish_to_resume']) && $profile) {
    try {
        // Compile full text version of the resume
        $template = $_POST['resume_template'] ?? 'fresher';
        
        $skillsArr = json_decode($profile['skills_json'], true) ?: [];
        $projectsArr = json_decode($profile['projects_json'], true) ?: [];
        $internshipsArr = json_decode($profile['internships_json'], true) ?: [];
        $certsArr = json_decode($profile['certifications_json'], true) ?: [];
        $achievementsArr = json_decode($profile['achievements_json'], true) ?: [];
        $workshopsArr = json_decode($profile['workshops_json'], true) ?: [];

        $resumeText = "Name: " . $student['student_name'] . "\n"
                    . "Email: " . $student['email'] . "\n"
                    . "Mobile: " . $student['mobile_number'] . "\n"
                    . "Department: " . $student['dept_name'] . "\n"
                    . "Batch: " . $student['batch_name'] . "\n\n"
                    . "CAREER OBJECTIVE\n" . $profile['career_objective'] . "\n\n"
                    . "EDUCATION\n"
                    . "College CGPA: " . ($student['current_cgpa'] ?? 'N/A') . "\n"
                    . "10th Percentage: " . ($student['tenth_percentage'] ?? 'N/A') . "%\n"
                    . "12th/Diploma Percentage: " . ($student['twelfth_percentage'] ?: $student['diploma_percentage'] ?: 'N/A') . "%\n\n";

        if (!empty($skillsArr)) {
            $resumeText .= "TECHNICAL SKILLS\n" . implode(', ', $skillsArr) . "\n\n";
        }

        if (!empty($projectsArr)) {
            $resumeText .= "PROJECTS\n";
            foreach ($projectsArr as $proj) {
                $resumeText .= "- " . $proj['title'] . " (Tech: " . $proj['tech'] . ")\n  " . $proj['desc'] . "\n";
            }
            $resumeText .= "\n";
        }

        if (!empty($internshipsArr)) {
            $resumeText .= "INTERNSHIPS\n";
            foreach ($internshipsArr as $intern) {
                $resumeText .= "- " . $intern['company'] . " (" . $intern['role'] . " - " . $intern['duration'] . ")\n  " . $intern['desc'] . "\n";
            }
            $resumeText .= "\n";
        }

        if (!empty($certsArr)) {
            $resumeText .= "CERTIFICATIONS\n- " . implode("\n- ", $certsArr) . "\n\n";
        }

        if (!empty($achievementsArr)) {
            $resumeText .= "ACHIEVEMENTS\n- " . implode("\n- ", $achievementsArr) . "\n\n";
        }

        $db->beginTransaction();
        
        // Reset currents
        $db->prepare("UPDATE resume_files SET is_current = 0 WHERE student_id = ?")->execute([$studentId]);

        // Insert mock builder file reference
        $fileName = 'generated_builder_' . $studentId . '.pdf';
        $stmtIns = $db->prepare("
            INSERT INTO resume_files (student_id, original_filename, stored_filename, file_path, file_type, file_size, is_current, status)
            VALUES (?, 'Builder Generated Resume.pdf', ?, 'builder', 'pdf', 0, 1, 'ready')
            ON DUPLICATE KEY UPDATE is_current = 1, status = 'ready'
        ");
        $stmtIns->execute([$studentId, $fileName]);
        
        // Fetch or create resume record id
        $stmtR = $db->prepare("SELECT resume_id FROM resume_files WHERE student_id = ? AND is_current = 1 LIMIT 1");
        $stmtR->execute([$studentId]);
        $resId = (int)$stmtR->fetchColumn();

        $textHash = hash('sha256', $resumeText);
        $stmtText = $db->prepare("
            INSERT INTO resume_extracted_text (resume_id, extracted_text, text_hash) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE extracted_text = VALUES(extracted_text), text_hash = VALUES(text_hash)
        ");
        $stmtText->execute([$resId, $resumeText, $textHash]);

        $db->commit();
        $message = "Resume saved as your current active portal resume! You can now run ATS Score analysis.";
    } catch (Exception $ex) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $ex->getMessage();
    }
}

// 5. Decode JSON profile sections
$skillsArr = json_decode($profile['skills_json'] ?? '[]', true) ?: [];
$projectsArr = json_decode($profile['projects_json'] ?? '[]', true) ?: [];
$internshipsArr = json_decode($profile['internships_json'] ?? '[]', true) ?: [];
$certsArr = json_decode($profile['certifications_json'] ?? '[]', true) ?: [];
$achievementsArr = json_decode($profile['achievements_json'] ?? '[]', true) ?: [];
$workshopsArr = json_decode($profile['workshops_json'] ?? '[]', true) ?: [];

$activeTemplate = $_GET['template'] ?? 'fresher';
?>

<style>
/* CSS Print Styles */
@media print {
    body * {
        visibility: hidden;
    }
    .print-area, .print-area * {
        visibility: visible;
    }
    .print-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        font-family: Arial, Helvetica, sans-serif;
        color: #000 !important;
        font-size: 10.5pt;
    }
    .sidebar, .navbar, .btn, .d-print-none, .alert, footer, .card-header {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
}
</style>

<?php require_once(__DIR__ . '/../includes/sidebar.php'); ?>

<div class="main-content d-print-none">
    <div class="container-fluid py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fa-solid fa-wand-magic-sparkles text-primary"></i> ATS Resume Builder</h1>
                <div>
                    <button onclick="window.print()" class="btn btn-primary"><i class="fa-solid fa-print"></i> Print / Save PDF</button>
                </div>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check"></i> <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Left Column: Form Editor -->
                <div class="col-lg-6">
                    <form method="POST">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 bg-primary text-white">
                                <h6 class="m-0 font-weight-bold">Personal Summary & Objectives</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Professional Objective</label>
                                    <textarea class="form-control" name="career_objective" rows="4" required><?php echo htmlspecialchars($profile['career_objective']); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Languages Known</label>
                                    <input type="text" class="form-control" name="languages" value="<?php echo htmlspecialchars($profile['languages']); ?>" placeholder="English, Tamil">
                                </div>
                            </div>
                        </div>

                        <!-- Technical Skills -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 bg-secondary text-white">
                                <h6 class="m-0 font-weight-bold">Skills & Keywords</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Technical Skills (Comma separated)</label>
                                    <input type="text" class="form-control" name="skills_list" value="<?php echo htmlspecialchars(implode(', ', $skillsArr)); ?>" placeholder="Python, Java, HTML, MySQL">
                                </div>
                            </div>
                        </div>

                        <!-- Academic Projects Section -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 bg-dark text-white d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold">Projects List</h6>
                                <button type="button" class="btn btn-sm btn-outline-light" onclick="addProjectRow()"><i class="fa-solid fa-plus"></i> Add Project</button>
                            </div>
                            <div class="card-body" id="projectsContainer">
                                <?php if (empty($projectsArr)): ?>
                                    <div class="border rounded p-3 mb-3 project-row">
                                        <input type="text" class="form-control mb-2" name="project_title[]" placeholder="Project Title">
                                        <input type="text" class="form-control mb-2" name="project_tech[]" placeholder="Technologies Used">
                                        <textarea class="form-control" name="project_desc[]" rows="2" placeholder="Brief project description..."></textarea>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($projectsArr as $proj): ?>
                                        <div class="border rounded p-3 mb-3 project-row">
                                            <input type="text" class="form-control mb-2" name="project_title[]" value="<?php echo htmlspecialchars($proj['title']); ?>" placeholder="Project Title">
                                            <input type="text" class="form-control mb-2" name="project_tech[]" value="<?php echo htmlspecialchars($proj['tech'] ?? ''); ?>" placeholder="Technologies Used">
                                            <textarea class="form-control" name="project_desc[]" rows="2" placeholder="Brief project description..."><?php echo htmlspecialchars($proj['desc'] ?? ''); ?></textarea>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Internships & Work Exp Section -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 bg-secondary text-white d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold">Internships & Work Experience</h6>
                                <button type="button" class="btn btn-sm btn-outline-light" onclick="addInternRow()"><i class="fa-solid fa-plus"></i> Add Internship</button>
                            </div>
                            <div class="card-body" id="internContainer">
                                <?php if (empty($internshipsArr)): ?>
                                    <div class="border rounded p-3 mb-3 intern-row">
                                        <input type="text" class="form-control mb-2" name="intern_company[]" placeholder="Company Name">
                                        <input type="text" class="form-control mb-2" name="intern_role[]" placeholder="Role (e.g. Intern, Web Developer)">
                                        <input type="text" class="form-control mb-2" name="intern_duration[]" placeholder="Duration (e.g. June 2026 - July 2026)">
                                        <textarea class="form-control" name="intern_desc[]" rows="2" placeholder="Responsibilities/Learnings..."></textarea>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($internshipsArr as $intern): ?>
                                        <div class="border rounded p-3 mb-3 intern-row">
                                            <input type="text" class="form-control mb-2" name="intern_company[]" value="<?php echo htmlspecialchars($intern['company']); ?>" placeholder="Company Name">
                                            <input type="text" class="form-control mb-2" name="intern_role[]" value="<?php echo htmlspecialchars($intern['role'] ?? ''); ?>" placeholder="Role (e.g. Intern, Web Developer)">
                                            <input type="text" class="form-control mb-2" name="intern_duration[]" value="<?php echo htmlspecialchars($intern['duration'] ?? ''); ?>" placeholder="Duration">
                                            <textarea class="form-control" name="intern_desc[]" rows="2" placeholder="Responsibilities/Learnings..."><?php echo htmlspecialchars($intern['desc'] ?? ''); ?></textarea>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Certifications, Workshops, Achievements -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 bg-light">
                                <h6 class="m-0 font-weight-bold text-dark">Certifications & Achievements (one item per line)</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Certifications</label>
                                    <textarea class="form-control" name="certifications_list" rows="3" placeholder="Google Python Certificate&#10;AWS Cloud Practitioner"><?php echo htmlspecialchars(implode("\n", $certsArr)); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Achievements</label>
                                    <textarea class="form-control" name="achievements_list" rows="3" placeholder="First prize in Web Hackathon 2026&#10;College Badminton runner-up"><?php echo htmlspecialchars(implode("\n", $achievementsArr)); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Workshops & Events</label>
                                    <textarea class="form-control" name="workshops_list" rows="3" placeholder="Attended IoT workshop at IIT Madras"><?php echo htmlspecialchars(implode("\n", $workshopsArr)); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Self-Declaration Statement</label>
                                    <textarea class="form-control" name="declaration" rows="2"><?php echo htmlspecialchars($profile['declaration']); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex mb-4">
                            <button type="submit" name="save_draft" class="btn btn-success flex-grow-1 me-3 py-2 font-weight-bold">
                                <i class="fa-solid fa-floppy-disk"></i> Save Draft
                            </button>
                            <button type="submit" name="publish_to_resume" class="btn btn-info text-white py-2 font-weight-bold">
                                <i class="fa-solid fa-circle-check"></i> Set as Active Portal Resume
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Right Column: Interactive Template Previews -->
                <div class="col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-dark text-white py-3">
                            <h6 class="m-0 font-weight-bold">Template Selection</h6>
                        </div>
                        <div class="card-body">
                            <div class="row text-center mb-3">
                                <div class="col-6 mb-2">
                                    <a href="?template=fresher" class="btn btn-block btn-sm <?php echo $activeTemplate === 'fresher' ? 'btn-primary' : 'btn-outline-primary'; ?>">Simple Fresher</a>
                                </div>
                                <div class="col-6 mb-2">
                                    <a href="?template=software" class="btn btn-block btn-sm <?php echo $activeTemplate === 'software' ? 'btn-primary' : 'btn-outline-primary'; ?>">Software & IT</a>
                                </div>
                                <div class="col-6">
                                    <a href="?template=engineering" class="btn btn-block btn-sm <?php echo $activeTemplate === 'engineering' ? 'btn-primary' : 'btn-outline-primary'; ?>">Core Engineering</a>
                                </div>
                                <div class="col-6">
                                    <a href="?template=intern" class="btn btn-block btn-sm <?php echo $activeTemplate === 'intern' ? 'btn-primary' : 'btn-outline-primary'; ?>">Internship Resume</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PRINT PREVIEW WINDOW FRAME -->
                    <div class="card shadow mb-4">
                        <div class="card-header bg-light">
                            <h6 class="m-0 font-weight-bold text-dark">Live A4 Print Preview</h6>
                        </div>
                        <div class="card-body bg-light p-3" style="overflow-y: scroll; max-height: 700px;">
                            
                            <div class="print-area bg-white p-4 border shadow-sm" style="min-height: 842px;">
                                <!-- Template Header -->
                                <div class="text-center mb-4">
                                    <h3 class="font-weight-bold text-uppercase mb-1" style="letter-spacing: 1px; color:#000; font-family:'Times New Roman',Times,serif;"><?php echo htmlspecialchars($student['student_name']); ?></h3>
                                    <div class="text-dark" style="font-size:0.9rem;">
                                        Email: <?php echo htmlspecialchars($student['email']); ?> | Mobile: +91 <?php echo htmlspecialchars($student['mobile_number']); ?>
                                    </div>
                                    <div class="text-dark" style="font-size:0.85rem;">
                                        Batch: <?php echo htmlspecialchars($student['batch_name']); ?> | Department of <?php echo htmlspecialchars($student['dept_name']); ?>
                                    </div>
                                    <hr style="border-top:2px solid #000; margin:10px 0;">
                                </div>

                                <!-- Objective -->
                                <div class="mb-4">
                                    <h6 class="font-weight-bold text-uppercase" style="border-bottom:1px solid #000; padding-bottom:3px; font-family:'Times New Roman',Times,serif; color:#000;">Career Objective</h6>
                                    <p class="text-justify" style="line-height:1.4;"><?php echo htmlspecialchars($profile['career_objective']); ?></p>
                                </div>

                                <!-- Education -->
                                <div class="mb-4">
                                    <h6 class="font-weight-bold text-uppercase" style="border-bottom:1px solid #000; padding-bottom:3px; font-family:'Times New Roman',Times,serif; color:#000;">Education</h6>
                                    <ul style="list-style-type: square; padding-left:20px; line-height: 1.5;">
                                        <li><strong>B.E / B.Tech (<?php echo htmlspecialchars($student['dept_name']); ?>)</strong> - MAMCET | CGPA: <?php echo ($student['current_cgpa'] ?? 'N/A'); ?></li>
                                        <?php if ($student['diploma_percentage'] > 0): ?>
                                            <li><strong>Diploma Course</strong> | Score: <?php echo $student['diploma_percentage']; ?>%</li>
                                        <?php endif; ?>
                                        <?php if ($student['twelfth_percentage'] > 0): ?>
                                            <li><strong>HSC (Class XII)</strong> | Score: <?php echo $student['twelfth_percentage']; ?>%</li>
                                        <?php endif; ?>
                                        <li><strong>SSLC (Class X)</strong> | Score: <?php echo $student['tenth_percentage']; ?>%</li>
                                    </ul>
                                </div>

                                <!-- Skills -->
                                <?php if (!empty($skillsArr)): ?>
                                    <div class="mb-4">
                                        <h6 class="font-weight-bold text-uppercase" style="border-bottom:1px solid #000; padding-bottom:3px; font-family:'Times New Roman',Times,serif; color:#000;">Technical Skills</h6>
                                        <p><strong>Core Languages & Frameworks:</strong> <?php echo htmlspecialchars(implode(', ', $skillsArr)); ?></p>
                                    </div>
                                <?php endif; ?>

                                <!-- Projects -->
                                <?php if (!empty($projectsArr) && !empty($projectsArr[0]['title'])): ?>
                                    <div class="mb-4">
                                        <h6 class="font-weight-bold text-uppercase" style="border-bottom:1px solid #000; padding-bottom:3px; font-family:'Times New Roman',Times,serif; color:#000;">Academic Projects</h6>
                                        <?php foreach ($projectsArr as $proj): if (empty($proj['title'])) continue; ?>
                                            <div class="mb-2">
                                                <strong><?php echo htmlspecialchars($proj['title']); ?></strong> <?php if (!empty($proj['tech'])): ?>| <small>Technologies: <?php echo htmlspecialchars($proj['tech']); ?></small><?php endif; ?>
                                                <p style="margin: 2px 0 0 0; font-size: 0.9rem; line-height:1.3; text-align:justify;"><?php echo htmlspecialchars($proj['desc']); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Internships -->
                                <?php if (!empty($internshipsArr) && !empty($internshipsArr[0]['company'])): ?>
                                    <div class="mb-4">
                                        <h6 class="font-weight-bold text-uppercase" style="border-bottom:1px solid #000; padding-bottom:3px; font-family:'Times New Roman',Times,serif; color:#000;">Internship Experience</h6>
                                        <?php foreach ($internshipsArr as $intern): if (empty($intern['company'])) continue; ?>
                                            <div class="mb-2">
                                                <strong><?php echo htmlspecialchars($intern['company']); ?></strong> | <?php echo htmlspecialchars($intern['role']); ?> (<?php echo htmlspecialchars($intern['duration']); ?>)
                                                <p style="margin: 2px 0 0 0; font-size: 0.9rem; line-height:1.3; text-align:justify;"><?php echo htmlspecialchars($intern['desc']); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Certifications & Achievements -->
                                <?php if (!empty($certsArr) || !empty($achievementsArr)): ?>
                                    <div class="mb-4">
                                        <h6 class="font-weight-bold text-uppercase" style="border-bottom:1px solid #000; padding-bottom:3px; font-family:'Times New Roman',Times,serif; color:#000;">Certifications & Co-Curriculars</h6>
                                        <ul style="padding-left:20px; line-height: 1.4; font-size:0.9rem;">
                                            <?php foreach ($certsArr as $c): ?>
                                                <li><?php echo htmlspecialchars($c); ?></li>
                                            <?php endforeach; ?>
                                            <?php foreach ($achievementsArr as $a): ?>
                                                <li><?php echo htmlspecialchars($a); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <!-- Declaration -->
                                <div class="mt-5">
                                    <h6 class="font-weight-bold text-uppercase" style="border-bottom:1px solid #000; padding-bottom:3px; font-family:'Times New Roman',Times,serif; color:#000;">Declaration</h6>
                                    <p class="text-justify" style="font-size:0.9rem;"><?php echo htmlspecialchars($profile['declaration']); ?></p>
                                    <div class="d-flex justify-content-between mt-4">
                                        <span>Date: <?php echo date('d-M-Y'); ?></span>
                                        <span class="font-weight-bold">(<?php echo htmlspecialchars($student['student_name']); ?>)</span>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
    </div>
</div>

<script>
function addProjectRow() {
    const container = document.getElementById('projectsContainer');
    const div = document.createElement('div');
    div.className = 'border rounded p-3 mb-3 project-row';
    div.innerHTML = `
        <input type="text" class="form-control mb-2" name="project_title[]" placeholder="Project Title">
        <input type="text" class="form-control mb-2" name="project_tech[]" placeholder="Technologies Used">
        <textarea class="form-control" name="project_desc[]" rows="2" placeholder="Brief project description..."></textarea>
    `;
    container.appendChild(div);
}

function addInternRow() {
    const container = document.getElementById('internContainer');
    const div = document.createElement('div');
    div.className = 'border rounded p-3 mb-3 intern-row';
    div.innerHTML = `
        <input type="text" class="form-control mb-2" name="intern_company[]" placeholder="Company Name">
        <input type="text" class="form-control mb-2" name="intern_role[]" placeholder="Role (e.g. Intern, Web Developer)">
        <input type="text" class="form-control mb-2" name="intern_duration[]" placeholder="Duration">
        <textarea class="form-control" name="intern_desc[]" rows="2" placeholder="Responsibilities/Learnings..."></textarea>
    `;
    container.appendChild(div);
}
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
