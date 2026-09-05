<?php
// MAMCET Placement & Learning Portal - Student Certificates Hub

$pageTitle = 'My Certificates & Credentials';
require_once(__DIR__ . '/../includes/header.php');
require_once(__DIR__ . '/../includes/sidebar.php');

// Restrict to Student Role
requireRole(ROLE_STUDENT);

$db = Database::getInstance()->getConnection();
ensureCertificationsTable($db);

$student = getActiveStudent($db);

if (!$student) {
    echo "<div class='main-content'><div class='page-container'><div class='alert alert-danger'>Student profile not associated with this login. Contact Placement Officer.</div></div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

$studentId = (int)$student['student_id'];
require_once(__DIR__ . '/../services/StudentService.php');
$studentService = new StudentService($db);
$certificates = $studentService->getStudentCertificates($studentId);
$totalCount = count($certificates);

$verifiedCount = 0;
$pendingCount = 0;
$rejectedCount = 0;

foreach ($certificates as $c) {
    if ($c['status'] === 'Verified') {
        $verifiedCount++;
    } elseif ($c['status'] === 'Rejected') {
        $rejectedCount++;
    } else {
        $pendingCount++;
    }
}
?>

<div class="main-content">
    <?php require_once(__DIR__ . '/../includes/topbar.php'); ?>

    <div class="page-container">
        <div class="db-container">

            <!-- PAGE HEADER BANNER -->
            <div class="cert-hero-card">
                <i class="fa-solid fa-award cert-hero-watermark"></i>
                <div class="cert-hero-body position-relative" style="z-index: 1;">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 gap-lg-4">
                        <div>
                            <h2 class="cert-hero-title">Certificate Portfolio</h2>
                            <p class="cert-hero-copy">
                                Upload course certificates, NPTEL/Coursera completions, and competition awards. Verified certificates are showcased directly to recruitment officers.
                            </p>
                        </div>
                        <div class="flex-shrink-0 d-flex flex-column align-items-start align-items-lg-end gap-1 mt-2 mt-lg-0">
                            <button type="button" class="cert-hero-upload-btn" onclick="openCertModal()">
                                <i class="fa-solid fa-cloud-arrow-up"></i> Upload Certificate
                            </button>
                            <span class="cert-hero-helper">
                                <i class="fa-regular fa-file-lines me-1"></i> PDF, PNG, JPG up to 5MB
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- METRIC STAT TILES -->
            <div class="row g-2 g-md-3 mb-3 mb-md-4">
                <div class="col-6 col-lg-3">
                    <div class="cert-stat-card stat-total">
                        <div class="cert-stat-icon-wrap">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                        <div class="cert-stat-info">
                            <span class="cert-stat-label">Total</span>
                            <span class="cert-stat-value text-dark" id="statTotal"><?php echo $totalCount; ?></span>
                            <span class="cert-stat-hint">Active credentials</span>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="cert-stat-card stat-verified">
                        <div class="cert-stat-icon-wrap">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div class="cert-stat-info">
                            <span class="cert-stat-label">Verified</span>
                            <span class="cert-stat-value text-success" id="statVerified"><?php echo $verifiedCount; ?></span>
                            <span class="cert-stat-hint">Placement approved</span>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="cert-stat-card stat-pending">
                        <div class="cert-stat-icon-wrap">
                            <i class="fa-solid fa-hourglass-half"></i>
                        </div>
                        <div class="cert-stat-info">
                            <span class="cert-stat-label">In Review</span>
                            <span class="cert-stat-value text-warning" id="statPending"><?php echo $pendingCount; ?></span>
                            <span class="cert-stat-hint">Pending review</span>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="cert-stat-card stat-rejected">
                        <div class="cert-stat-icon-wrap">
                            <i class="fa-solid fa-circle-exclamation"></i>
                        </div>
                        <div class="cert-stat-info">
                            <span class="cert-stat-label">Rejected</span>
                            <span class="cert-stat-value text-danger" id="statRejected"><?php echo $rejectedCount; ?></span>
                            <span class="cert-stat-hint">Needs re-upload</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FILTER & SEARCH BAR -->
            <div class="cert-toolbar-card">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <ul class="nav nav-pills cert-filter-nav" id="certFilterTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-filter="all">
                                <i class="fa-solid fa-table-cells-large"></i> All
                                <span class="badge-count" id="tabCountAll"><?php echo $totalCount; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-filter="Verified">
                                <i class="fa-solid fa-circle-check text-success"></i> Verified
                                <span class="badge-count" id="tabCountVerified"><?php echo $verifiedCount; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-filter="Pending Verification">
                                <i class="fa-solid fa-clock text-warning"></i> Pending
                                <span class="badge-count" id="tabCountPending"><?php echo $pendingCount; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-filter="Rejected">
                                <i class="fa-solid fa-circle-xmark text-danger"></i> Rejected
                                <span class="badge-count" id="tabCountRejected"><?php echo $rejectedCount; ?></span>
                            </button>
                        </li>
                    </ul>

                    <div class="input-group input-group-sm" style="max-width: 320px;">
                        <span class="input-group-text bg-light border-end-0 text-muted rounded-start-pill ps-3">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </span>
                        <input type="text" id="certSearchInput" class="form-control bg-light border-start-0 border-end-0" placeholder="Search certificates...">
                        <button class="input-group-text bg-light border-start-0 text-muted rounded-end-pill pe-3 d-none" id="btnCertSearchClear" type="button" title="Clear search">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- CERTIFICATES LIST -->
            <div id="certListContainer">
                <?php if (empty($certificates)): ?>
                    <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white" id="emptyCertNotice">
                        <div class="mb-3">
                            <span class="d-inline-flex p-3 rounded-circle" style="background: #f0fdfa; color: #0d9488;">
                                <i class="fa-solid fa-certificate fa-3x"></i>
                            </span>
                        </div>
                        <h5 class="fw-bold text-dark mb-1">No Certificates Uploaded Yet</h5>
                        <p class="text-muted small mx-auto mb-3" style="max-width: 460px;">
                            Upload your course completions (Coursera, Udemy, NPTEL) and event certificates in PDF, PNG, or JPG format.
                        </p>
                        <div>
                            <button type="button" class="btn btn-teal text-white fw-semibold rounded-pill px-4 py-2" style="background: #0d9488;" onclick="openCertModal()">
                                <i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload Your First Certificate
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-3" id="certCardsRow">
                        <?php foreach ($certificates as $cert): ?>
                            <?php
                            $status = $cert['status'];
                            $statusBadgeClass = 'cert-status-pending';
                            $statusIcon = 'fa-clock';
                            if ($status === 'Verified') {
                                $statusBadgeClass = 'cert-status-verified';
                                $statusIcon = 'fa-circle-check';
                            } elseif ($status === 'Rejected') {
                                $statusBadgeClass = 'cert-status-rejected';
                                $statusIcon = 'fa-circle-xmark';
                            }

                            $fName = $cert['file_name'] ?: basename($cert['file_path'] ?? 'certificate');
                            $fSize = !empty($cert['file_size']) ? (function_exists('formatBytes') ? formatBytes($cert['file_size']) : round($cert['file_size'] / 1024, 1) . ' KB') : '';
                            $uploadDateFormatted = !empty($cert['created_at']) ? date('d M Y, h:i A', strtotime($cert['created_at'])) : 'N/A';
                            $ext = strtolower(pathinfo($fName, PATHINFO_EXTENSION));
                            $isPdf = $ext === 'pdf';
                            $viewUrl = "../api/certificate-file.php?cert_id=" . $cert['cert_id'] . "&mode=view";
                            $downloadUrl = "../api/certificate-file.php?cert_id=" . $cert['cert_id'] . "&mode=download";
                            ?>
                            <div class="col-md-6 col-xl-4 cert-card-item" data-status="<?php echo esc($status); ?>" data-title="<?php echo esc(strtolower($cert['title'] . ' ' . $cert['issuing_organization'] . ' ' . ($cert['skills'] ?? ''))); ?>">
                                <div class="cert-credential-card shadow-sm">

                                    <!-- CARD PREVIEW BANNER -->
                                    <div class="cert-preview-banner" onclick="openCertQuickView(<?php echo $cert['cert_id']; ?>, '<?php echo esc(addslashes($cert['title'])); ?>', '<?php echo $isPdf ? 'pdf' : 'image'; ?>', '<?php echo $viewUrl; ?>', '<?php echo $downloadUrl; ?>')">
                                        <?php if ($isPdf): ?>
                                            <div class="cert-preview-pdf">
                                                <div class="cert-pdf-badge">
                                                    <div class="pdf-icon-wrap">
                                                        <i class="fa-solid fa-file-pdf"></i>
                                                    </div>
                                                    <span class="small fw-semibold letter-spacing-wide opacity-90">PDF DOCUMENT</span>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <img src="<?php echo $viewUrl; ?>" 
                                                 alt="<?php echo esc($cert['title']); ?>" 
                                                 class="cert-preview-img" 
                                                 loading="lazy"
                                                 onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'cert-preview-pdf\'><div class=\'cert-pdf-badge\'><div class=\'pdf-icon-wrap\' style=\'background:rgba(2,132,199,0.15); border-color:rgba(2,132,199,0.3); color:#38bdf8;\'><i class=\'fa-solid fa-award\'></i></div><span class=\'small fw-semibold\'>CREDENTIAL DOCUMENT</span></div></div>';">
                                        <?php endif; ?>

                                        <!-- Floating Status Pill -->
                                        <span class="cert-floating-status <?php echo $statusBadgeClass; ?>">
                                            <i class="fa-solid <?php echo $statusIcon; ?> me-1"></i> <?php echo esc($status); ?>
                                        </span>

                                        <!-- Floating File Format Pill -->
                                        <span class="cert-floating-filetype">
                                            <?php echo strtoupper($ext ?: 'DOC'); ?><?php echo !empty($fSize) ? ' • ' . esc($fSize) : ''; ?>
                                        </span>

                                        <!-- Hover Preview Overlay -->
                                        <div class="cert-preview-overlay">
                                            <span class="cert-preview-overlay-btn">
                                                <i class="fa-solid fa-expand"></i> Quick Preview
                                            </span>
                                        </div>
                                    </div>

                                    <!-- CARD BODY -->
                                    <div class="cert-body-content">
                                        <!-- Organization & Title -->
                                        <div class="cert-org-badge text-truncate" title="<?php echo esc($cert['issuing_organization'] ?: 'Credential'); ?>">
                                            <i class="fa-solid fa-building-columns text-teal"></i>
                                            <span><?php echo esc($cert['issuing_organization'] ?: 'Institution / Academy'); ?></span>
                                        </div>

                                        <h5 class="cert-title-heading" title="<?php echo esc($cert['title']); ?>">
                                            <?php echo esc($cert['title']); ?>
                                        </h5>

                                        <!-- Structured Metadata -->
                                        <div class="cert-meta-grid">
                                            <?php if (!empty($cert['issue_date'])): ?>
                                                <div class="cert-meta-row">
                                                    <span class="cert-meta-label">
                                                        <i class="fa-regular fa-calendar-check text-muted"></i> Issued Date
                                                    </span>
                                                    <span class="cert-meta-value text-dark">
                                                        <?php echo date('d M Y', strtotime($cert['issue_date'])); ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($cert['credential_id'])): ?>
                                                <div class="cert-meta-row">
                                                    <span class="cert-meta-label">
                                                        <i class="fa-solid fa-fingerprint text-muted"></i> Credential ID
                                                    </span>
                                                    <div class="cert-meta-value d-flex align-items-center justify-content-end">
                                                        <code class="text-dark bg-white border px-1.5 py-0.5 rounded user-select-all" style="font-size:0.75rem; max-width: 140px; overflow:hidden; text-overflow:ellipsis;" title="<?php echo esc($cert['credential_id']); ?>">
                                                            <?php echo esc($cert['credential_id']); ?>
                                                        </code>
                                                        <button type="button" class="cert-copy-btn" onclick="event.stopPropagation(); copyToClipboard('<?php echo esc(addslashes($cert['credential_id'])); ?>', this)" title="Copy Credential ID">
                                                            <i class="fa-regular fa-copy"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($cert['credential_url'])): ?>
                                                <div class="cert-meta-row">
                                                    <span class="cert-meta-label">
                                                        <i class="fa-solid fa-shield-halved text-muted"></i> Verification
                                                    </span>
                                                    <a href="<?php echo esc($cert['credential_url']); ?>" target="_blank" rel="noopener noreferrer" class="text-teal text-decoration-none fw-semibold small d-inline-flex align-items-center gap-1" style="font-size:0.75rem;">
                                                        <span>Verify Online</span>
                                                        <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.68rem;"></i>
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Skills Badges -->
                                        <?php if (!empty($cert['skills'])): ?>
                                            <div class="cert-skills-wrap">
                                                <div class="d-flex flex-wrap gap-1">
                                                    <?php 
                                                    $skillsArray = array_filter(array_map('trim', explode(',', $cert['skills'])));
                                                    foreach (array_slice($skillsArray, 0, 4) as $sk): 
                                                    ?>
                                                        <span class="cert-skill-chip" title="<?php echo esc($sk); ?>">
                                                            <i class="fa-solid fa-tag opacity-50" style="font-size: 0.62rem;"></i>
                                                            <?php echo esc($sk); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                    <?php if (count($skillsArray) > 4): ?>
                                                        <span class="badge bg-light text-muted border px-2 py-0.5 rounded-pill" style="font-size:0.68rem;" title="<?php echo esc(implode(', ', array_slice($skillsArray, 4))); ?>">
                                                            +<?php echo count($skillsArray) - 4; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <!-- File Technical Footnote -->
                                        <div class="cert-file-footnote">
                                            <span class="text-truncate me-2" title="<?php echo esc($fName); ?>" style="max-width: 170px;">
                                                <i class="fa-regular fa-file me-1"></i> <?php echo esc($fName); ?>
                                            </span>
                                            <span>
                                                <i class="fa-regular fa-clock me-1"></i> <?php echo esc($uploadDateFormatted); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- ACTIONS FOOTER -->
                                    <div class="cert-actions-footer">
                                        <div class="d-flex align-items-center gap-2">
                                            <button type="button" class="cert-btn-primary" onclick="openCertQuickView(<?php echo $cert['cert_id']; ?>, '<?php echo esc(addslashes($cert['title'])); ?>', '<?php echo $isPdf ? 'pdf' : 'image'; ?>', '<?php echo $viewUrl; ?>', '<?php echo $downloadUrl; ?>')">
                                                <i class="fa-solid fa-eye"></i> View
                                            </button>
                                            <a href="<?php echo $downloadUrl; ?>" class="cert-btn-secondary" title="Download File">
                                                <i class="fa-solid fa-download"></i> Download
                                            </a>
                                        </div>
                                        <button type="button" class="cert-btn-delete" onclick="deleteCert(<?php echo $cert['cert_id']; ?>, '<?php echo esc(addslashes($cert['title'])); ?>')" title="Delete Certificate">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </button>
                                    </div>

                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Filter Empty State: When search/filter matches 0 items -->
                    <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white d-none mt-3" id="certSearchEmpty">
                        <div class="mb-3">
                            <span class="d-inline-flex p-3 rounded-circle bg-light text-muted">
                                <i class="fa-solid fa-magnifying-glass fa-2x"></i>
                            </span>
                        </div>
                        <h5 class="fw-bold text-dark mb-1">No Matching Certificates Found</h5>
                        <p class="text-muted small mx-auto mb-3" style="max-width: 420px;">
                            We couldn't find any certificates matching your current filter and search query.
                        </p>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" id="btnResetFilters">
                                <i class="fa-solid fa-rotate-left me-1"></i> Reset Filters
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- CERTIFICATE QUICK VIEW LIGHTBOX MODAL -->
<div class="modal fade" id="certQuickViewModal" tabindex="-1" aria-labelledby="certQuickViewModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white py-3 px-4 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    <i class="fa-solid fa-award text-warning"></i>
                    <h6 class="modal-title fw-bold text-white text-truncate mb-0" id="certQuickViewModalTitle">Certificate Preview</h6>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="#" id="certQuickViewOpenTab" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-light rounded-pill px-3 py-1" style="font-size:0.78rem;" title="Open in New Tab">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Full Window
                    </a>
                    <a href="#" id="certQuickViewDownload" class="btn btn-sm btn-teal text-white rounded-pill px-3 py-1" style="background:#0d9488; font-size:0.78rem;" title="Download Certificate">
                        <i class="fa-solid fa-download me-1"></i> Download
                    </a>
                    <button type="button" class="btn-close btn-close-white ms-1" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-0 bg-light position-relative" style="min-height: 440px; max-height: 80vh; display: flex; align-items: center; justify-content: center; overflow: auto;">
                <div id="certQuickViewLoading" class="position-absolute top-50 start-50 translate-middle text-center py-4">
                    <div class="spinner-border" role="status" style="width: 2.5rem; height: 2.5rem; color:#0d9488;">
                        <span class="visually-hidden">Loading document...</span>
                    </div>
                    <div class="text-muted small mt-2 fw-medium">Rendering document preview...</div>
                </div>
                <div id="certQuickViewContainer" class="w-100 h-100 d-flex justify-content-center align-items-center p-3">
                    <!-- Dynamic iframe or img loaded via JS -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- UPLOAD CERTIFICATE MODAL -->
<div class="modal fade" id="uploadCertModal" tabindex="-1" aria-labelledby="uploadCertModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, #064e3b 0%, #0f766e 100%);">
                <div class="d-flex align-items-center gap-2.5">
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="background: rgba(255, 255, 255, 0.15); width: 38px; height: 38px;">
                        <i class="fa-solid fa-award"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="uploadCertModalLabel">Upload New Certificate</h5>
                        <small class="text-white text-opacity-75" style="font-size: 0.78rem;">Showcase credentials to campus recruitment officers</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="certUploadForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                <div class="modal-body p-4">
                    <div id="certUploadAlert" class="alert d-none py-2 px-3 small rounded-3 mb-3"></div>

                    <!-- Certificate Title -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">Certificate Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="certTitle" class="form-control" placeholder="e.g. AWS Certified Solutions Architect, NPTEL Cloud Computing" required>
                    </div>

                    <!-- Issuing Organization & Date -->
                    <div class="row g-3 mb-3">
                        <div class="col-sm-7">
                            <label class="form-label small fw-bold text-dark">Issuing Organization</label>
                            <input type="text" name="issuing_organization" id="certOrg" class="form-control" placeholder="e.g. Coursera, Udemy, NPTEL, Cisco">
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label small fw-bold text-dark">Issue Date</label>
                            <input type="date" name="issue_date" id="certDate" class="form-control">
                        </div>
                    </div>

                    <!-- Credential ID & URL -->
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold text-dark">Credential ID (Optional)</label>
                            <input type="text" name="credential_id" id="certCredId" class="form-control" placeholder="e.g. CERT-89421">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold text-dark">Verification URL (Optional)</label>
                            <input type="url" name="credential_url" id="certCredUrl" class="form-control" placeholder="https://...">
                        </div>
                    </div>

                    <!-- Associated Skills -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">Related Skills (Optional)</label>
                        <input type="text" name="skills" id="certSkills" class="form-control" placeholder="e.g. Python, Machine Learning, AWS, React">
                        <div class="form-text" style="font-size:0.75rem;">Comma-separated skill keywords.</div>
                    </div>

                    <!-- Certificate Document File -->
                    <div class="mb-2">
                        <label class="form-label small fw-bold text-dark">Certificate Document <span class="text-danger">*</span></label>
                        <label for="certFileInput" class="cert-dropzone-box d-block w-100 mb-0" id="dropZone" style="user-select: none;">
                            <input type="file" name="cert_file" id="certFileInput" class="d-none" accept=".pdf,.png,.jpg,.jpeg">
                            <div id="dropZonePrompt">
                                <div class="mb-2">
                                    <span class="d-inline-flex p-3 rounded-circle" style="background: #f0fdfa; color: #0f766e;">
                                        <i class="fa-solid fa-cloud-arrow-up fa-2x"></i>
                                    </span>
                                </div>
                                <div class="fw-bold text-dark small mb-1">Click to browse or drag file here</div>
                                <div class="text-muted small" style="font-size: 0.76rem;">Accepted formats: PDF, PNG, JPG (Maximum 5MB)</div>
                            </div>
                            <div id="dropZoneSelected" class="d-none text-start p-2 rounded-3 bg-white border">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-3 p-2 d-flex align-items-center justify-content-center" id="selectedTypeIconWrap" style="background: #e0f2fe; color: #0284c7; width: 42px; height: 42px;">
                                        <i class="fa-solid fa-file fa-lg" id="selectedTypeIcon"></i>
                                    </div>
                                    <div class="min-w-0 flex-grow-1">
                                        <div class="fw-bold text-dark text-truncate small" id="selectedFileName"></div>
                                        <div class="text-muted small" id="selectedFileSize" style="font-size:0.74rem;"></div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-circle p-1" style="width: 30px; height: 30px;" id="btnRemoveSelectedFile" title="Remove file">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-teal text-white rounded-pill px-4 fw-semibold" id="btnSubmitCert" style="background: #0d9488;">
                        <span id="btnSubmitCertText"><i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload Certificate</span>
                        <span id="btnSubmitCertSpinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>

<script>
function openCertModal() {
    const modal = new bootstrap.Modal(document.getElementById('uploadCertModal'));
    modal.show();
}

$(document).ready(function() {
    const $dropZone = $('#dropZone');
    const $fileInput = $('#certFileInput');
    const $dropPrompt = $('#dropZonePrompt');
    const $dropSelected = $('#dropZoneSelected');
    const $selectedName = $('#selectedFileName');
    const $selectedSize = $('#selectedFileSize');
    const $alert = $('#certUploadAlert');

    $fileInput.on('change', function() {
        validateAndShowFile(this.files[0]);
    });

    // Drag and drop events
    $dropZone.on('dragover dragenter', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('dragover');
    });

    $dropZone.on('dragleave drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('dragover');
    });

    $dropZone.on('drop', function(e) {
        const dt = e.originalEvent.dataTransfer;
        if (dt && dt.files && dt.files.length) {
            $fileInput[0].files = dt.files;
            validateAndShowFile(dt.files[0]);
        }
    });

    $('#btnRemoveSelectedFile').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $fileInput.val('');
        $dropSelected.addClass('d-none');
        $dropPrompt.removeClass('d-none');
    });

    function validateAndShowFile(file) {
        $alert.addClass('d-none').removeClass('alert-danger alert-success');
        if (!file) return;

        const allowedExts = ['pdf', 'png', 'jpg', 'jpeg'];
        const nameParts = file.name.split('.');
        const ext = nameParts.length > 1 ? nameParts.pop().toLowerCase() : '';

        if (!allowedExts.includes(ext)) {
            showAlert('Invalid file format. Please upload a PDF, PNG, or JPG certificate.', 'danger');
            $fileInput.val('');
            return;
        }

        const maxBytes = 5 * 1024 * 1024;
        if (file.size > maxBytes) {
            showAlert('File size exceeds the 5MB limit (' + (file.size / (1024*1024)).toFixed(2) + 'MB). Please choose a smaller file.', 'danger');
            $fileInput.val('');
            return;
        }

        // Dynamic file type icon & style
        if (ext === 'pdf') {
            $('#selectedTypeIcon').attr('class', 'fa-solid fa-file-pdf fa-lg');
            $('#selectedTypeIconWrap').css({ background: '#fee2e2', color: '#dc2626' });
        } else {
            $('#selectedTypeIcon').attr('class', 'fa-solid fa-file-image fa-lg');
            $('#selectedTypeIconWrap').css({ background: '#e0f2fe', color: '#0284c7' });
        }

        $selectedName.text(file.name);
        $selectedSize.text((file.size / 1024).toFixed(1) + ' KB');
        $dropPrompt.addClass('d-none');
        $dropSelected.removeClass('d-none');
    }

    function showAlert(msg, type) {
        $alert.removeClass('d-none alert-danger alert-success')
              .addClass('alert-' + type)
              .html(msg);
    }

    // Submit certificate form via AJAX
    $('#certUploadForm').on('submit', function(e) {
        e.preventDefault();

        const file = $fileInput[0].files[0];
        if (!file) {
            showAlert('Please select a certificate file (PDF, PNG, JPG) to upload.', 'danger');
            return;
        }

        const formData = new FormData(this);
        const $btn = $('#btnSubmitCert');
        const $text = $('#btnSubmitCertText');
        const $spinner = $('#btnSubmitCertSpinner');

        $btn.prop('disabled', true);
        $text.addClass('d-none');
        $spinner.removeClass('d-none');

        $.ajax({
            url: '../api/upload-certification.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                $btn.prop('disabled', false);
                $text.removeClass('d-none');
                $spinner.addClass('d-none');

                if (res.success) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Uploaded Successfully!',
                            text: res.message || 'Certificate uploaded and placed under verification.',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        showAlert(res.message, 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    }
                } else {
                    showAlert(res.message || 'Upload failed. Please check the file and try again.', 'danger');
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false);
                $text.removeClass('d-none');
                $spinner.addClass('d-none');

                let errMsg = 'Failed to upload certificate. Please try again.';
                try {
                    const parsed = JSON.parse(xhr.responseText);
                    if (parsed && parsed.message) errMsg = parsed.message;
                } catch(e) {}
                showAlert(errMsg, 'danger');
            }
        });
    });

    // Filter tabs
    $('#certFilterTabs button').on('click', function() {
        $('#certFilterTabs button').removeClass('active');
        $(this).addClass('active');

        const filter = $(this).data('filter');
        applyFilterAndSearch();
    });

    // Live search
    $('#certSearchInput').on('keyup input', function() {
        const val = $(this).val();
        if (val.length > 0) {
            $('#btnCertSearchClear').removeClass('d-none');
        } else {
            $('#btnCertSearchClear').addClass('d-none');
        }
        applyFilterAndSearch();
    });

    // Clear search
    $('#btnCertSearchClear').on('click', function() {
        $('#certSearchInput').val('');
        $(this).addClass('d-none');
        applyFilterAndSearch();
    });

    // Reset filters from empty search state
    $('#btnResetFilters').on('click', function() {
        $('#certSearchInput').val('');
        $('#btnCertSearchClear').addClass('d-none');
        $('#certFilterTabs button').removeClass('active');
        $('#certFilterTabs button[data-filter="all"]').addClass('active');
        applyFilterAndSearch();
    });

    function applyFilterAndSearch() {
        const filter = $('#certFilterTabs button.active').data('filter');
        const query = $('#certSearchInput').val().toLowerCase().trim();

        let visibleCount = 0;
        $('.cert-card-item').each(function() {
            const cardStatus = $(this).data('status');
            const cardData = $(this).data('title') || '';

            const matchesStatus = (filter === 'all' || cardStatus === filter);
            const matchesQuery = (!query || cardData.indexOf(query) !== -1);

            if (matchesStatus && matchesQuery) {
                $(this).removeClass('d-none');
                visibleCount++;
            } else {
                $(this).addClass('d-none');
            }
        });

        if (visibleCount === 0 && $('.cert-card-item').length > 0) {
            $('#certSearchEmpty').removeClass('d-none');
        } else {
            $('#certSearchEmpty').addClass('d-none');
        }
    }
});

// Delete certificate confirmation
function deleteCert(certId, certTitle) {
    const csrfToken = $('meta[name="csrf-token"]').attr('content') || $('input[name="csrf_token"]').val();

    const doDelete = () => {
        $.post('../api/certificate-actions.php', {
            action: 'delete',
            cert_id: certId,
            csrf_token: csrfToken
        }, function(res) {
            if (res.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Deleted!', res.message, 'success').then(() => {
                        window.location.reload();
                    });
                } else {
                    alert(res.message);
                    window.location.reload();
                }
            } else {
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Error', res.message || 'Failed to delete certificate.', 'error');
                } else {
                    alert(res.message || 'Failed to delete certificate.');
                }
            }
        }, 'json').fail(function() {
            alert('Server error while deleting certificate.');
        });
    };

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Delete Certificate?',
            text: `Are you sure you want to permanently delete "${certTitle}"?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, delete it'
        }).then((result) => {
            if (result.isConfirmed) {
                doDelete();
            }
        });
    } else {
        if (confirm(`Are you sure you want to delete certificate "${certTitle}"?`)) {
            doDelete();
        }
    }
}

// In-Page Quick-View Lightbox Preview
function openCertQuickView(certId, certTitle, fileType, viewUrl, downloadUrl) {
    $('#certQuickViewModalTitle').text(certTitle || 'Certificate Preview');
    $('#certQuickViewOpenTab').attr('href', viewUrl);
    $('#certQuickViewDownload').attr('href', downloadUrl);

    const $container = $('#certQuickViewContainer');
    const $loading = $('#certQuickViewLoading');
    $container.empty();
    $loading.removeClass('d-none');

    if (fileType === 'pdf') {
        const $iframe = $('<iframe>', {
            src: viewUrl,
            class: 'w-100 border-0 rounded-3',
            style: 'height: 70vh; min-height: 480px;'
        });
        $iframe.on('load', function() {
            $loading.addClass('d-none');
        });
        $container.append($iframe);
    } else {
        const $img = $('<img>', {
            src: viewUrl,
            alt: certTitle,
            class: 'img-fluid rounded-3 shadow-sm',
            style: 'max-height: 75vh; max-width: 100%; object-fit: contain;'
        });
        $img.on('load', function() {
            $loading.addClass('d-none');
        });
        $img.on('error', function() {
            $loading.addClass('d-none');
            $container.html('<div class="text-center p-5 text-muted"><i class="fa-regular fa-image fa-3x mb-2 opacity-50"></i><p class="small">Unable to render inline preview. Please use "Full Window" or "Download" buttons above.</p></div>');
        });
        $container.append($img);
    }

    const modal = new bootstrap.Modal(document.getElementById('certQuickViewModal'));
    modal.show();
}

// 1-Click Credential ID Copy with Tooltip Feedback
function copyToClipboard(text, btnElement) {
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        const $btn = $(btnElement);
        const originalHtml = $btn.html();
        $btn.html('<i class="fa-solid fa-check text-success"></i>');
        setTimeout(() => {
            $btn.html(originalHtml);
        }, 1500);
    }).catch(err => {
        console.error('Could not copy text: ', err);
    });
}
</script>
