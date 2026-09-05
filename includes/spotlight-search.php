<?php
// MAMCET Placement & Learning Portal - Global Spotlight Search & Student Quick-View Modal

if (!isLoggedIn() || !in_array((int)($_SESSION['role_id'] ?? 0), [ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER])) {
    return;
}

$baseDir = '';
if (strpos($_SERVER['REQUEST_URI'], '/admin/') !== false || 
    strpos($_SERVER['REQUEST_URI'], '/student/') !== false || 
    strpos($_SERVER['REQUEST_URI'], '/auth/') !== false ||
    strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
    $baseDir = '../';
}
?>

<!-- 1. SPOTLIGHT / COMMAND PALETTE MODAL -->
<div class="modal fade spotlight-modal" id="spotlightSearchModal" tabindex="-1" aria-labelledby="spotlightModalLabel" aria-hidden="true" data-bs-backdrop="true" data-bs-focus="false">
    <div class="modal-dialog modal-dialog-top spotlight-dialog">
        <div class="modal-content spotlight-content">
            <!-- Search Header Bar -->
            <div class="spotlight-header">
                <i class="fa-solid fa-magnifying-glass spotlight-search-icon"></i>
                <input type="text" id="spotlightSearchInput" class="spotlight-input" placeholder="Search students by name, reg no, email, dept, batch..." autocomplete="off" spellcheck="false" aria-label="Search students" autofocus>
                
                <div class="spotlight-header-actions d-flex align-items-center gap-2">
                    <div class="spinner-border spinner-border-sm text-primary d-none" id="spotlightSearchSpinner" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <button type="button" class="spotlight-clear-btn d-none" id="spotlightClearBtn" title="Clear search" aria-label="Clear search">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </button>
                    <span class="spotlight-esc-badge d-none d-sm-inline-block" data-bs-dismiss="modal" title="Press Escape to close">ESC</span>
                </div>
            </div>

            <!-- Department Quick Filter Pills -->
            <div class="spotlight-filters d-flex align-items-center flex-wrap gap-1 px-3 py-2 border-bottom">
                <span class="spotlight-filter-label text-muted small fw-semibold me-1"><i class="fa-solid fa-filter small me-1"></i>Quick:</span>
                <button type="button" class="spotlight-filter-pill active" data-filter="">All</button>
                <button type="button" class="spotlight-filter-pill" data-filter="CSE">CSE</button>
                <button type="button" class="spotlight-filter-pill" data-filter="IT">IT</button>
                <button type="button" class="spotlight-filter-pill" data-filter="ECE">ECE</button>
                <button type="button" class="spotlight-filter-pill" data-filter="EEE">EEE</button>
                <button type="button" class="spotlight-filter-pill" data-filter="MECH">MECH</button>
                <button type="button" class="spotlight-filter-pill" data-filter="CIVIL">CIVIL</button>
                <button type="button" class="spotlight-filter-pill" data-filter="AIDS">AIDS</button>
                <button type="button" class="spotlight-filter-pill" data-filter="Placed">Placed</button>
                <button type="button" class="spotlight-filter-pill" data-filter="Unplaced">Unplaced</button>
            </div>

            <!-- Search Results Container -->
            <div class="spotlight-body" id="spotlightResultsContainer">
                <!-- Initial Helper State -->
                <div class="spotlight-empty-state text-center py-4" id="spotlightInitialState">
                    <div class="spotlight-empty-icon mb-2">
                        <i class="fa-solid fa-bolt-lightning text-warning fa-2x"></i>
                    </div>
                    <h6 class="fw-bold text-dark mb-1">Instant Student Search</h6>
                    <p class="text-muted small mb-3">Type at least 1 character to search across students by registration number, name, department, or email.</p>
                    <div class="d-flex justify-content-center gap-3 small text-muted">
                        <span><kbd class="spotlight-kbd">↑</kbd> <kbd class="spotlight-kbd">↓</kbd> Navigate</span>
                        <span><kbd class="spotlight-kbd">↵</kbd> Select & View</span>
                        <span><kbd class="spotlight-kbd">ESC</kbd> Close</span>
                    </div>
                </div>

                <!-- Results List -->
                <ul class="spotlight-results-list list-unstyled mb-0 d-none" id="spotlightResultsList" role="listbox">
                    <!-- Populated dynamically via JS -->
                </ul>

                <!-- No Results State -->
                <div class="spotlight-empty-state text-center py-4 d-none" id="spotlightNoResultsState">
                    <div class="spotlight-empty-icon mb-2">
                        <i class="fa-regular fa-face-frown text-muted fa-2x"></i>
                    </div>
                    <h6 class="fw-bold text-dark mb-1">No matching students found</h6>
                    <p class="text-muted small mb-0">No records found for "<span class="fw-bold text-dark" id="spotlightQueryHighlight"></span>". Try searching by Register Number, Department, or Full Name.</p>
                </div>
            </div>

            <!-- Spotlight Footer -->
            <div class="spotlight-footer d-flex align-items-center justify-content-between px-3 py-2 border-top bg-light">
                <div class="small text-muted d-flex align-items-center gap-3">
                    <span class="d-none d-md-inline"><i class="fa-solid fa-arrows-up-down me-1"></i>Use <kbd class="spotlight-kbd">↑</kbd><kbd class="spotlight-kbd">↓</kbd> keys</span>
                    <span class="d-none d-md-inline"><i class="fa-solid fa-arrow-turn-down-left me-1"></i><kbd class="spotlight-kbd">Enter</kbd> to open details</span>
                    <span class="d-md-none"><i class="fa-solid fa-hand-pointer me-1"></i>Tap student to view details</span>
                </div>
                <span class="small fw-semibold text-primary" id="spotlightCountBadge"></span>
            </div>
        </div>
    </div>
</div>


<!-- 2. STUDENT DETAILS QUICK-VIEW MODAL -->
<div class="modal fade student-quickview-modal" id="studentQuickViewModal" tabindex="-1" aria-labelledby="studentQuickViewLabel" aria-hidden="true" data-bs-backdrop="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg border-0 rounded-4">
            
            <!-- Quick View Header Card -->
            <div class="modal-header quickview-header border-0 pb-0 pt-4 px-4 align-items-start">
                <div class="d-flex align-items-center gap-3 w-100">
                    <div class="quickview-avatar rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm" id="qvAvatar" style="width: 58px; height: 58px; font-size: 1.4rem; background: linear-gradient(135deg, #2563eb, #1d4ed8); flex-shrink: 0;">
                        S
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                            <h5 class="modal-title fw-bold text-dark mb-0 text-truncate" id="qvStudentName">Student Name</h5>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill small fw-semibold" id="qvPlacementBadge">Placed</span>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill small fw-semibold" id="qvWillingnessBadge">Willing</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap small text-muted">
                            <span class="fw-semibold text-dark"><i class="fa-solid fa-id-card me-1 text-primary"></i><span id="qvRegNo">9204XXXXXXXX</span></span>
                            <span>•</span>
                            <span class="badge bg-secondary-subtle text-secondary fw-semibold" id="qvDeptCode">CSE</span>
                            <span class="badge bg-light text-muted border fw-semibold" id="qvBatchName">2021-2025</span>
                            <span class="badge bg-light text-muted border fw-semibold d-none" id="qvSectionName">Sec A</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>

            <!-- Quick View Body -->
            <div class="modal-body px-4 py-3">
                <!-- KPI Highlight Strip -->
                <div class="row g-2 mb-3 mt-1">
                    <div class="col-6 col-md-3">
                        <div class="quickview-kpi-card text-center p-2 rounded-3 border bg-light">
                            <div class="small text-muted fw-semibold mb-1">Current CGPA</div>
                            <div class="fs-5 fw-bold text-primary" id="qvCgpa">8.50</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="quickview-kpi-card text-center p-2 rounded-3 border bg-light">
                            <div class="small text-muted fw-semibold mb-1">Standing Arrears</div>
                            <div class="fs-5 fw-bold" id="qvStandingArrears">0 Clear</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="quickview-kpi-card text-center p-2 rounded-3 border bg-light">
                            <div class="small text-muted fw-semibold mb-1">10th / 12th Score</div>
                            <div class="fs-6 fw-bold text-dark" id="qvSchoolMarks">90% / 88%</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="quickview-kpi-card text-center p-2 rounded-3 border bg-light">
                            <div class="small text-muted fw-semibold mb-1">Offers Count</div>
                            <div class="fs-5 fw-bold text-success" id="qvOffersCount">0</div>
                        </div>
                    </div>
                </div>

                <!-- Navigation Tabs inside Quick View -->
                <ul class="nav nav-tabs nav-tabs-bordered mb-3" id="quickViewTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active py-2 fw-semibold" id="qv-tab-overview" data-bs-toggle="tab" data-bs-target="#qv-pane-overview" type="button" role="tab" aria-controls="qv-pane-overview" aria-selected="true">
                            <i class="fa-solid fa-user me-1 text-primary"></i> Overview & Contact
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link py-2 fw-semibold" id="qv-tab-placements" data-bs-toggle="tab" data-bs-target="#qv-pane-placements" type="button" role="tab" aria-controls="qv-pane-placements" aria-selected="false">
                            <i class="fa-solid fa-briefcase me-1 text-success"></i> Placements (<span id="qvOffersTabCount">0</span>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link py-2 fw-semibold" id="qv-tab-portfolio" data-bs-toggle="tab" data-bs-target="#qv-pane-portfolio" type="button" role="tab" aria-controls="qv-pane-portfolio" aria-selected="false">
                            <i class="fa-solid fa-file-lines me-1 text-info"></i> Resume & Skills
                        </button>
                    </li>
                </ul>

                <!-- Tab Contents -->
                <div class="tab-content" id="quickViewTabContent">
                    
                    <!-- TAB 1: OVERVIEW & CONTACT -->
                    <div class="tab-pane fade show active" id="qv-pane-overview" role="tabpanel" aria-labelledby="qv-tab-overview">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="p-3 rounded-3 border bg-white h-100">
                                    <h6 class="fw-bold text-dark mb-2 small text-uppercase" style="letter-spacing: 0.5px;">
                                        <i class="fa-solid fa-address-book text-primary me-1"></i> Contact Details
                                    </h6>
                                    <div class="d-flex flex-column gap-2 small">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="text-muted"><i class="fa-solid fa-envelope me-1"></i>College Email:</span>
                                            <a href="#" id="qvCollegeEmail" class="text-decoration-none fw-semibold text-primary text-truncate ms-2" style="max-width: 190px;">-</a>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="text-muted"><i class="fa-regular fa-envelope me-1"></i>Personal Email:</span>
                                            <a href="#" id="qvEmail" class="text-decoration-none fw-semibold text-dark text-truncate ms-2" style="max-width: 190px;">-</a>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="text-muted"><i class="fa-solid fa-phone me-1"></i>Student Mobile:</span>
                                            <a href="#" id="qvMobile" class="text-decoration-none fw-semibold text-dark">-</a>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="text-muted"><i class="fa-solid fa-user-group me-1"></i>Parent Phone:</span>
                                            <a href="#" id="qvParentMobile" class="text-decoration-none fw-semibold text-dark">-</a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="p-3 rounded-3 border bg-white h-100">
                                    <h6 class="fw-bold text-dark mb-2 small text-uppercase" style="letter-spacing: 0.5px;">
                                        <i class="fa-solid fa-graduation-cap text-primary me-1"></i> Academic Breakdown
                                    </h6>
                                    <div class="d-flex flex-column gap-2 small">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="text-muted">10th Percentage:</span>
                                            <span class="fw-semibold text-dark" id="qvTenthMarks">-</span>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="text-muted">12th / Diploma %:</span>
                                            <span class="fw-semibold text-dark" id="qvTwelfthMarks">-</span>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="text-muted">History of Arrears:</span>
                                            <span class="fw-semibold text-dark" id="qvHistoryArrears">0</span>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="text-muted">Year / Degree:</span>
                                            <span class="fw-semibold text-dark" id="qvProgramme">-</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Semester GPA Strip -->
                            <div class="col-12">
                                <div class="p-3 rounded-3 border bg-light">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <h6 class="fw-bold text-dark mb-0 small text-uppercase" style="letter-spacing: 0.5px;">
                                            <i class="fa-solid fa-chart-simple text-primary me-1"></i> Semester GPA Progression
                                        </h6>
                                        <span class="small text-muted">Sem 1 to Sem 8</span>
                                    </div>
                                    <div class="row g-1 text-center" id="qvGpaProgressionRow">
                                        <!-- Populated dynamically via JS -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 2: PLACEMENTS & OFFERS -->
                    <div class="tab-pane fade" id="qv-pane-placements" role="tabpanel" aria-labelledby="qv-tab-placements">
                        <div id="qvPlacementsContainer">
                            <!-- Populated dynamically via JS -->
                        </div>
                    </div>

                    <!-- TAB 3: RESUME, SKILLS & PORTFOLIO -->
                    <div class="tab-pane fade" id="qv-pane-portfolio" role="tabpanel" aria-labelledby="qv-tab-portfolio">
                        <div class="d-flex flex-column gap-3">
                            <!-- Resume Download Banner -->
                            <div class="p-3 rounded-3 border bg-light d-flex align-items-center justify-content-between flex-wrap gap-2" id="qvResumeBanner">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="bg-primary-subtle text-primary rounded-3 p-3 d-flex align-items-center justify-content-center">
                                        <i class="fa-solid fa-file-pdf fa-2x"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-bold text-dark mb-0">Student Resume (PDF)</h6>
                                        <span class="small text-muted" id="qvResumeStatus">Uploaded and verified</span>
                                    </div>
                                </div>
                                <a href="#" target="_blank" class="btn btn-sm btn-primary px-3 rounded-pill" id="qvResumeDownloadBtn">
                                    <i class="fa-solid fa-download me-1"></i> Download Resume
                                </a>
                            </div>

                            <!-- Skills Tags -->
                            <div class="p-3 rounded-3 border bg-white">
                                <h6 class="fw-bold text-dark mb-2 small text-uppercase" style="letter-spacing: 0.5px;">
                                    <i class="fa-solid fa-code text-primary me-1"></i> Technical Skills & Competencies
                                </h6>
                                <div class="d-flex flex-wrap gap-1" id="qvSkillsList">
                                    <span class="text-muted small">No specific skills listed.</span>
                                </div>
                            </div>

                            <!-- Social & Portfolio Links -->
                            <div class="p-3 rounded-3 border bg-white">
                                <h6 class="fw-bold text-dark mb-2 small text-uppercase" style="letter-spacing: 0.5px;">
                                    <i class="fa-solid fa-globe text-primary me-1"></i> Professional Links & Profiles
                                </h6>
                                <div class="d-flex flex-wrap gap-2" id="qvLinksContainer">
                                    <!-- Populated dynamically -->
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Quick View Footer -->
            <div class="modal-footer quickview-footer border-top bg-light px-4 py-3 d-flex align-items-center justify-content-between">
                <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" id="qvBtnBackToSearch">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Search
                </button>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-light btn-sm rounded-pill px-3 border" data-bs-dismiss="modal">Close</button>
                    <a href="#" class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold d-inline-flex align-items-center gap-2" id="qvBtnFullProfile">
                        <span>View Full Profile Page</span>
                        <kbd class="spotlight-kbd bg-white text-dark border-0 shadow-sm" style="font-size: 0.68rem; padding: 2px 6px;">Enter ↵</kbd>
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- 3. GLOBAL SPOTLIGHT SEARCH JAVASCRIPT CONTROLLER -->
<script>
(function($) {
    'use strict';

    var baseDir = '<?php echo $baseDir; ?>';
    var searchTimeout = null;
    var currentAbortController = null;
    var selectedIndex = -1;
    var searchResultsData = [];
    var activeFilter = '';

    // Cache DOM elements
    var $spotlightModal = $('#spotlightSearchModal');
    var $spotlightInput = $('#spotlightSearchInput');
    var $spotlightSpinner = $('#spotlightSearchSpinner');
    var $spotlightClearBtn = $('#spotlightClearBtn');
    var $spotlightResultsList = $('#spotlightResultsList');
    var $spotlightInitialState = $('#spotlightInitialState');
    var $spotlightNoResultsState = $('#spotlightNoResultsState');
    var $spotlightQueryHighlight = $('#spotlightQueryHighlight');
    var $spotlightCountBadge = $('#spotlightCountBadge');
    
    var $quickViewModal = $('#studentQuickViewModal');

    // 1. KEYBOARD SHORTCUTS LISTENER
    $(document).on('keydown', function(e) {
        var activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        var isInputFocused = (activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select' || (document.activeElement && document.activeElement.isContentEditable));

        // Open on Ctrl + K or Cmd + K
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            toggleSpotlight();
            return;
        }

        // Open on '/' if no input/textarea is currently focused
        if (e.key === '/' && !isInputFocused) {
            e.preventDefault();
            openSpotlight();
            return;
        }

        // Handle navigation inside open spotlight modal
        if ($spotlightModal.hasClass('show')) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                navigateResults(1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                navigateResults(-1);
            } else if (e.key === 'Enter') {
                if (selectedIndex >= 0 && searchResultsData[selectedIndex]) {
                    e.preventDefault();
                    selectStudent(searchResultsData[selectedIndex].student_id);
                }
            }
        } else if ($quickViewModal.hasClass('show')) {
            // Enter key directly opens the full student profile page
            if (e.key === 'Enter') {
                e.preventDefault();
                var fullProfileUrl = $('#qvBtnFullProfile').attr('href');
                if (fullProfileUrl && fullProfileUrl !== '#' && !fullProfileUrl.startsWith('javascript')) {
                    window.location.href = fullProfileUrl;
                }
            } else if (e.key === 'Backspace' && !isInputFocused) {
                e.preventDefault();
                $('#qvBtnBackToSearch').trigger('click');
            }
        }
    });

    // Toggle / Open / Close Helpers
    function toggleSpotlight() {
        if ($spotlightModal.hasClass('show')) {
            $spotlightModal.modal('hide');
        } else {
            openSpotlight();
        }
    }

    function focusSearchInput() {
        var el = document.getElementById('spotlightSearchInput');
        if (el) {
            el.focus();
            el.select();
        }
        $spotlightInput.trigger('focus').select();
    }

    // Bootstrap Modal Event Listeners to enforce immediate auto-focus
    $spotlightModal.on('shown.bs.modal', function() {
        focusSearchInput();
    });

    $spotlightModal.on('show.bs.modal', function() {
        setTimeout(focusSearchInput, 10);
    });

    // Auto focus full profile button when Quick-View modal is shown
    $quickViewModal.on('shown.bs.modal', function() {
        $('#qvBtnFullProfile').focus();
    });

    function openSpotlight(presetQuery) {
        // Close Quick View if open
        if ($quickViewModal.hasClass('show')) {
            $quickViewModal.modal('hide');
        }

        if (presetQuery !== undefined) {
            $spotlightInput.val(presetQuery);
            performSearch(presetQuery);
        }

        $spotlightModal.modal('show');
        
        // Instantaneous focus execution across microtasks & animation frames
        focusSearchInput();
        if (window.requestAnimationFrame) {
            requestAnimationFrame(focusSearchInput);
        }
        setTimeout(focusSearchInput, 30);
        setTimeout(focusSearchInput, 100);
        setTimeout(focusSearchInput, 200);
    }

    // Topbar Trigger Buttons click handlers
    $(document).on('click', '#openSpotlightSearchBtn, #openSpotlightSearchMobileBtn, .spotlight-trigger-btn', function(e) {
        e.preventDefault();
        openSpotlight();
    });

    // Input Typing Handler with Debounce (250ms)
    $spotlightInput.on('input', function() {
        var query = $(this).val().trim();
        
        if (query.length > 0) {
            $spotlightClearBtn.removeClass('d-none');
        } else {
            $spotlightClearBtn.addClass('d-none');
        }

        if (searchTimeout) clearTimeout(searchTimeout);

        if (query.length === 0 && activeFilter === '') {
            renderInitialState();
            return;
        }

        searchTimeout = setTimeout(function() {
            var fullQuery = query;
            if (activeFilter) {
                fullQuery = query ? (query + ' ' + activeFilter) : activeFilter;
            }
            performSearch(fullQuery);
        }, 250);
    });

    // Clear Button Handler
    $spotlightClearBtn.on('click', function() {
        $spotlightInput.val('').focus();
        $spotlightClearBtn.addClass('d-none');
        if (activeFilter) {
            performSearch(activeFilter);
        } else {
            renderInitialState();
        }
    });

    // Filter Pills Handler
    $(document).on('click', '.spotlight-filter-pill', function(e) {
        e.preventDefault();
        $('.spotlight-filter-pill').removeClass('active');
        $(this).addClass('active');

        activeFilter = $(this).data('filter') || '';
        var query = $spotlightInput.val().trim();
        var fullQuery = query;
        if (activeFilter) {
            fullQuery = query ? (query + ' ' + activeFilter) : activeFilter;
        }

        if (fullQuery.length === 0) {
            renderInitialState();
        } else {
            performSearch(fullQuery);
        }
        $spotlightInput.focus();
    });

    // Perform AJAX Search
    function performSearch(query) {
        if (!query || query.trim().length === 0) {
            renderInitialState();
            return;
        }

        if (currentAbortController) {
            currentAbortController.abort();
        }

        if (window.AbortController) {
            currentAbortController = new AbortController();
        }

        $spotlightSpinner.removeClass('d-none');
        $spotlightCountBadge.text('');

        var searchUrl = baseDir + 'api/student-search.php?action=search&q=' + encodeURIComponent(query) + '&limit=12';

        $.ajax({
            url: searchUrl,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                $spotlightSpinner.addClass('d-none');
                if (response.success) {
                    searchResultsData = response.results || [];
                    renderResults(searchResultsData, query);
                } else {
                    renderNoResults(query);
                }
            },
            error: function(xhr, status) {
                $spotlightSpinner.addClass('d-none');
                if (status !== 'abort') {
                    renderNoResults(query);
                }
            }
        });
    }

    // Render Search Results List
    function renderResults(results, query) {
        selectedIndex = -1;
        $spotlightInitialState.addClass('d-none');

        if (!results || results.length === 0) {
            renderNoResults(query);
            return;
        }

        $spotlightNoResultsState.addClass('d-none');
        $spotlightResultsList.removeClass('d-none').empty();
        $spotlightCountBadge.text(results.length + (results.length === 1 ? ' student' : ' students') + ' found');

        results.forEach(function(student, index) {
            var avatarInitial = student.student_name ? student.student_name.charAt(0).toUpperCase() : 'S';
            var cgpaClass = 'text-primary';
            var cgpaNum = parseFloat(student.current_cgpa);
            if (!isNaN(cgpaNum)) {
                if (cgpaNum >= 8.0) cgpaClass = 'text-success';
                else if (cgpaNum < 6.0) cgpaClass = 'text-warning';
            }

            var placementBadge = '';
            if (student.placement_status === 'Placed') {
                placementBadge = '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill small fw-semibold"><i class="fa-solid fa-check me-1"></i>Placed</span>';
            } else {
                placementBadge = '<span class="badge bg-secondary-subtle text-secondary px-2 py-1 rounded-pill small fw-semibold">Unplaced</span>';
            }

            var arrearsBadge = '';
            if (student.standing_arrears > 0) {
                arrearsBadge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 rounded-pill small fw-semibold"><i class="fa-solid fa-triangle-exclamation me-1"></i>' + student.standing_arrears + ' Arrears</span>';
            } else {
                arrearsBadge = '<span class="badge bg-light text-success border px-2 py-1 rounded-pill small fw-semibold"><i class="fa-solid fa-circle-check me-1"></i>0 Arrears</span>';
            }

            var $item = $(
                '<li class="spotlight-result-item" data-index="' + index + '" data-student-id="' + student.student_id + '" role="option">' +
                    '<div class="d-flex align-items-center justify-content-between w-100 gap-2">' +
                        '<div class="d-flex align-items-center gap-3 min-w-0">' +
                            '<div class="spotlight-item-avatar">' + avatarInitial + '</div>' +
                            '<div class="min-w-0 text-start">' +
                                '<div class="d-flex align-items-center gap-2 flex-wrap mb-1">' +
                                    '<span class="spotlight-item-name fw-bold text-dark text-truncate">' + escapeHtml(student.student_name) + '</span>' +
                                    '<span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold" style="font-size: 0.72rem;">' + escapeHtml(student.registration_number) + '</span>' +
                                    '<span class="badge bg-light text-muted border fw-semibold" style="font-size: 0.7rem;">' + escapeHtml(student.dept_code) + '</span>' +
                                    '<span class="badge bg-light text-muted border fw-semibold" style="font-size: 0.7rem;">' + escapeHtml(student.batch_name) + '</span>' +
                                '</div>' +
                                '<div class="small text-muted text-truncate" style="font-size: 0.75rem;">' +
                                    '<i class="fa-regular fa-envelope me-1"></i>' + escapeHtml(student.email || 'No email') +
                                    (student.mobile_number ? '<span class="ms-2"><i class="fa-solid fa-phone me-1"></i>' + escapeHtml(student.mobile_number) + '</span>' : '') +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="d-flex align-items-center gap-2 flex-shrink-0 text-end">' +
                            '<div class="d-none d-sm-block text-end me-1">' +
                                '<div class="fw-bold ' + cgpaClass + '" style="font-size: 0.9rem;">' + escapeHtml(student.current_cgpa) + ' <span class="small text-muted" style="font-size: 0.65rem;">CGPA</span></div>' +
                            '</div>' +
                            arrearsBadge +
                            placementBadge +
                            '<i class="fa-solid fa-chevron-right text-muted small ms-1 d-none d-sm-inline"></i>' +
                        '</div>' +
                    '</div>' +
                '</li>'
            );

            $spotlightResultsList.append($item);
        });

        // Auto highlight first item
        navigateResults(1);
    }

    // Render Empty / Initial State
    function renderInitialState() {
        searchResultsData = [];
        selectedIndex = -1;
        $spotlightSpinner.addClass('d-none');
        $spotlightResultsList.addClass('d-none').empty();
        $spotlightNoResultsState.addClass('d-none');
        $spotlightInitialState.removeClass('d-none');
        $spotlightCountBadge.text('');
    }

    // Render No Results
    function renderNoResults(query) {
        searchResultsData = [];
        selectedIndex = -1;
        $spotlightSpinner.addClass('d-none');
        $spotlightResultsList.addClass('d-none').empty();
        $spotlightInitialState.addClass('d-none');
        $spotlightQueryHighlight.text(query);
        $spotlightNoResultsState.removeClass('d-none');
        $spotlightCountBadge.text('0 results');
    }

    // Keyboard Arrow Navigation
    function navigateResults(direction) {
        var items = $spotlightResultsList.find('.spotlight-result-item');
        if (items.length === 0) return;

        items.removeClass('active-item');

        if (direction === 1) {
            selectedIndex = (selectedIndex + 1) >= items.length ? 0 : (selectedIndex + 1);
        } else if (direction === -1) {
            selectedIndex = (selectedIndex - 1) < 0 ? (items.length - 1) : (selectedIndex - 1);
        }

        var $activeItem = $(items[selectedIndex]);
        $activeItem.addClass('active-item');

        // Scroll active item into view inside container
        var container = $('#spotlightResultsContainer')[0];
        var itemEl = $activeItem[0];
        if (container && itemEl) {
            var containerTop = container.scrollTop;
            var containerBottom = containerTop + container.clientHeight;
            var itemTop = itemEl.offsetTop;
            var itemBottom = itemTop + itemEl.clientHeight;

            if (itemTop < containerTop) {
                container.scrollTop = itemTop;
            } else if (itemBottom > containerBottom) {
                container.scrollTop = itemBottom - container.clientHeight;
            }
        }
    }

    // Item Click / Selection Handler
    $(document).on('click', '.spotlight-result-item', function() {
        var studentId = $(this).data('student-id');
        if (studentId) {
            selectStudent(studentId);
        }
    });

    // 2. FETCH AND OPEN STUDENT QUICK-VIEW MODAL
    function selectStudent(studentId) {
        if (!studentId) return;

        $spotlightSpinner.removeClass('d-none');

        $.ajax({
            url: baseDir + 'api/student-search.php?action=get_student_detail&student_id=' + encodeURIComponent(studentId),
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                $spotlightSpinner.addClass('d-none');
                if (response.success && response.student) {
                    populateQuickView(response.student);
                    $spotlightModal.modal('hide');
                    $quickViewModal.modal('show');
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Student Not Found',
                        text: response.message || 'Unable to retrieve complete details for this student.',
                        toast: true,
                        position: 'top-end',
                        timer: 3000,
                        showConfirmButton: false
                    });
                }
            },
            error: function(xhr) {
                $spotlightSpinner.addClass('d-none');
                Swal.fire({
                    icon: 'error',
                    title: 'Server Error',
                    text: 'An error occurred while fetching student records. Please try again.',
                    toast: true,
                    position: 'top-end',
                    timer: 3000,
                    showConfirmButton: false
                });
            }
        });
    }

    // Populate Quick-View Modal Data
    function populateQuickView(s) {
        // Reset tabs to first tab
        $('#qv-tab-overview').tab('show');

        // Header Info
        var initial = s.student_name ? s.student_name.charAt(0).toUpperCase() : 'S';
        $('#qvAvatar').text(initial);
        $('#qvStudentName').text(s.student_name || 'Student');
        $('#qvRegNo').text(s.registration_number || 'N/A');
        $('#qvDeptCode').text(s.dept_code || 'N/A').attr('title', s.dept_name || '');
        $('#qvBatchName').text(s.batch_name || 'N/A');
        
        if (s.section_name) {
            $('#qvSectionName').text('Sec ' + s.section_name).removeClass('d-none');
        } else {
            $('#qvSectionName').addClass('d-none');
        }

        // Placement & Willingness Badges
        if (s.placement_status === 'Placed') {
            $('#qvPlacementBadge').removeClass('bg-secondary-subtle text-secondary').addClass('bg-success-subtle text-success border-success-subtle').html('<i class="fa-solid fa-check me-1"></i>Placed');
        } else {
            $('#qvPlacementBadge').removeClass('bg-success-subtle text-success border-success-subtle').addClass('bg-secondary-subtle text-secondary').text(s.placement_status || 'Unplaced');
        }

        if (s.placement_willingness === 'Yes') {
            $('#qvWillingnessBadge').removeClass('bg-danger-subtle text-danger').addClass('bg-primary-subtle text-primary border-primary-subtle').text('Willing');
        } else {
            $('#qvWillingnessBadge').removeClass('bg-primary-subtle text-primary border-primary-subtle').addClass('bg-danger-subtle text-danger border-danger-subtle').text('Not Willing');
        }

        // KPIs
        $('#qvCgpa').text(s.current_cgpa || 'N/A');
        
        if (s.standing_arrears > 0) {
            $('#qvStandingArrears').removeClass('text-success').addClass('text-danger').html('<i class="fa-solid fa-triangle-exclamation me-1"></i>' + s.standing_arrears + ' Arrears');
        } else {
            $('#qvStandingArrears').removeClass('text-danger').addClass('text-success').html('<i class="fa-solid fa-circle-check me-1"></i>0 Clear');
        }

        var schoolStr = (s.tenth_percentage ? s.tenth_percentage + '%' : '-') + ' / ' + (s.twelfth_percentage ? s.twelfth_percentage + '%' : (s.diploma_percentage ? s.diploma_percentage + '% (Dip)' : '-'));
        $('#qvSchoolMarks').text(schoolStr);

        var offersCount = (s.placements && s.placements.length) ? s.placements.length : 0;
        $('#qvOffersCount').text(offersCount);
        $('#qvOffersTabCount').text(offersCount);

        // Contact info
        if (s.college_email) {
            $('#qvCollegeEmail').text(s.college_email).attr('href', 'mailto:' + s.college_email);
        } else {
            $('#qvCollegeEmail').text('Not provided').attr('href', '#');
        }

        if (s.email) {
            $('#qvEmail').text(s.email).attr('href', 'mailto:' + s.email);
        } else {
            $('#qvEmail').text('Not provided').attr('href', '#');
        }

        if (s.mobile_number) {
            $('#qvMobile').text(s.mobile_number).attr('href', 'tel:' + s.mobile_number);
        } else {
            $('#qvMobile').text('Not provided').attr('href', '#');
        }

        if (s.parent_mobile) {
            $('#qvParentMobile').text(s.parent_mobile).attr('href', 'tel:' + s.parent_mobile);
        } else {
            $('#qvParentMobile').text('Not provided').attr('href', '#');
        }

        // Academic details
        $('#qvTenthMarks').text(s.tenth_percentage ? s.tenth_percentage + '%' : 'Not Recorded');
        $('#qvTwelfthMarks').text(s.twelfth_percentage ? s.twelfth_percentage + '%' : (s.diploma_percentage ? s.diploma_percentage + '% (Diploma)' : 'Not Recorded'));
        $('#qvHistoryArrears').text(s.history_of_arrears || 0);
        $('#qvProgramme').text((s.programme_name || 'B.Tech') + ' (' + (s.year_of_study || 'Final Year') + ')');

        // Semester GPA Progression Grid
        var $gpaRow = $('#qvGpaProgressionRow').empty();
        var gpas = s.gpa_records || {};
        for (var sem = 1; sem <= 8; sem++) {
            var semKey = 'sem' + sem;
            var semVal = gpas[semKey];
            var displayVal = (semVal !== null && semVal !== undefined && semVal !== '') ? parseFloat(semVal).toFixed(2) : '-';
            var cardBg = (displayVal !== '-') ? 'bg-white border-primary-subtle' : 'bg-light';
            var textClass = (displayVal !== '-') ? 'text-primary fw-bold' : 'text-muted';

            $gpaRow.append(
                '<div class="col-3 col-md-1-5 col-lg-auto flex-fill">' +
                    '<div class="p-1 rounded border ' + cardBg + '" style="font-size:0.75rem;">' +
                        '<div class="text-muted" style="font-size:0.65rem;">S' + sem + '</div>' +
                        '<div class="' + textClass + '">' + displayVal + '</div>' +
                    '</div>' +
                '</div>'
            );
        }

        // Tab 2: Placements Container
        var $placementsContainer = $('#qvPlacementsContainer').empty();
        if (s.placements && s.placements.length > 0) {
            var tableHtml = 
                '<div class="table-responsive">' +
                    '<table class="table table-sm table-hover align-middle mb-0">' +
                        '<thead class="table-light small">' +
                            '<tr>' +
                                '<th>Company Name</th>' +
                                '<th>Package (LPA)</th>' +
                                '<th>Placed Date</th>' +
                                '<th>Offer Letter</th>' +
                            '</tr>' +
                        '</thead>' +
                        '<tbody>';

            s.placements.forEach(function(p) {
                var offerLink = p.offer_letter_path ? 
                    '<a href="' + baseDir + p.offer_letter_path + '" target="_blank" class="btn btn-xs btn-outline-primary py-0 px-2 rounded-pill small"><i class="fa-solid fa-download me-1"></i>View Letter</a>' : 
                    '<span class="text-muted small">Not attached</span>';

                tableHtml += 
                    '<tr>' +
                        '<td class="fw-bold text-dark"><i class="fa-solid fa-building me-1 text-secondary"></i>' + escapeHtml(p.company_name) + '</td>' +
                        '<td><span class="badge bg-success-subtle text-success fw-bold">' + escapeHtml(p.package_lpa) + ' LPA</span></td>' +
                        '<td class="small text-muted">' + escapeHtml(p.placed_date) + '</td>' +
                        '<td>' + offerLink + '</td>' +
                    '</tr>';
            });

            tableHtml += '</tbody></table></div>';
            $placementsContainer.append(tableHtml);
        } else {
            $placementsContainer.append(
                '<div class="text-center py-4 text-muted">' +
                    '<i class="fa-solid fa-briefcase fa-2x mb-2 text-secondary opacity-50"></i>' +
                    '<p class="mb-0 small">No job offers recorded for this student yet.</p>' +
                '</div>'
            );
        }

        // Tab 3: Resume & Portfolio
        if (s.has_resume && s.resume_path) {
            $('#qvResumeStatus').text('Resume file is available for download').removeClass('text-danger').addClass('text-success');
            $('#qvResumeDownloadBtn').removeClass('disabled').attr('href', baseDir + s.resume_path).attr('target', '_blank');
        } else {
            $('#qvResumeStatus').text('No resume uploaded by student').removeClass('text-success').addClass('text-muted');
            $('#qvResumeDownloadBtn').addClass('disabled').attr('href', '#');
        }

        // Skills Badges
        var $skillsList = $('#qvSkillsList').empty();
        if (s.skills && s.skills.trim().length > 0) {
            var skillsArr = s.skills.split(',');
            skillsArr.forEach(function(skill) {
                var cleanSkill = skill.trim();
                if (cleanSkill) {
                    $skillsList.append('<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill small fw-semibold">' + escapeHtml(cleanSkill) + '</span>');
                }
            });
        } else {
            $skillsList.append('<span class="text-muted small">No technical skills recorded yet.</span>');
        }

        // Links
        var $linksContainer = $('#qvLinksContainer').empty();
        var hasLinks = false;
        if (s.linkedin_url) {
            hasLinks = true;
            $linksContainer.append('<a href="' + escapeHtml(s.linkedin_url) + '" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3"><i class="fa-brands fa-linkedin me-1 text-primary"></i> LinkedIn Profile</a>');
        }
        if (s.github_url) {
            hasLinks = true;
            $linksContainer.append('<a href="' + escapeHtml(s.github_url) + '" target="_blank" class="btn btn-sm btn-outline-dark rounded-pill px-3"><i class="fa-brands fa-github me-1"></i> GitHub Profile</a>');
        }
        if (s.portfolio_url) {
            hasLinks = true;
            $linksContainer.append('<a href="' + escapeHtml(s.portfolio_url) + '" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="fa-solid fa-globe me-1"></i> Portfolio Website</a>');
        }
        if (!hasLinks) {
            $linksContainer.append('<span class="text-muted small">No external portfolio links provided.</span>');
        }

        // Footer Action Link
        $('#qvBtnFullProfile').attr('href', baseDir + 'admin/student-view.php?student_id=' + s.student_id);
    }

    // Back to Search button inside Quick-View
    $('#qvBtnBackToSearch').on('click', function(e) {
        e.preventDefault();
        $quickViewModal.modal('hide');
        setTimeout(function() {
            $spotlightModal.modal('show');
            $spotlightInput.focus();
        }, 300);
    });

    // Helper: HTML Escaping
    function escapeHtml(text) {
        if (!text) return '';
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

})(jQuery);
</script>
