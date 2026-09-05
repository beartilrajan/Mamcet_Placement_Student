<?php
// MAMCET Placement & Learning Portal - Global Footer Template

$isLoggedIn = isLoggedIn();
$baseDir = '';
if (strpos($_SERVER['REQUEST_URI'], '/admin/') !== false || strpos($_SERVER['REQUEST_URI'], '/student/') !== false) {
    $baseDir = '../';
}
?>

<?php if ($isLoggedIn): ?>
    </div> <!-- Close app-wrapper -->
<?php endif; ?>

<!-- Core JavaScript Files -->
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Bootstrap 5 Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.all.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php 
// Global Spotlight Search & Quick View Modal for Super Admin & Placement Officer
if ($isLoggedIn && in_array((int)($_SESSION['role_id'] ?? 0), [ROLE_SUPER_ADMIN, ROLE_PLACEMENT_OFFICER])) {
    require_once(__DIR__ . '/spotlight-search.php');
}
?>

<!-- Global Common Script -->
<script>
$(document).ready(function() {
    // Clean up any legacy dark mode settings
    localStorage.removeItem('mamcet_theme');
    localStorage.removeItem('lc_theme');
    $('html, body').removeAttr('data-theme');

    // 1. Mobile Sidebar Toggle & Overlay System
    function openSidebar() {
        $('#sidebar').addClass('show');
        $('#sidebarOverlay').addClass('active');
        $('body').addClass('sidebar-open');
        $('.sidebar-toggle, .mobile-more-trigger').attr('aria-expanded', 'true');
    }

    function closeSidebar() {
        $('#sidebar').removeClass('show');
        $('#sidebarOverlay').removeClass('active');
        $('body').removeClass('sidebar-open');
        $('.sidebar-toggle, .mobile-more-trigger').attr('aria-expanded', 'false');
    }

    $('.sidebar-toggle').on('click', function(e) {
        e.stopPropagation();
        if ($('#sidebar').hasClass('show')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    $('.mobile-more-trigger').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if ($('#sidebar').hasClass('show')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    $('#sidebarCloseBtn, #sidebarOverlay').on('click', function(e) {
        e.preventDefault();
        closeSidebar();
    });

    // Close on Escape key press
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#sidebar').hasClass('show')) {
            closeSidebar();
        }
    });

    // Auto close sidebar on mobile when navigating links
    $('.sidebar-menu a').on('click', function() {
        if ($(window).width() < 992) {
            closeSidebar();
        }
    });

    // Handle screen resize transition
    $(window).on('resize', function() {
        if ($(window).width() >= 992 && $('#sidebar').hasClass('show')) {
            closeSidebar();
        }
    });

    // 2. SweetAlert2 Flash Notifications
    <?php
    $flash = getFlash();
    if ($flash):
    ?>
    Swal.fire({
        icon: '<?php echo esc($flash['type']); ?>',
        title: '<?php echo esc($flash['type'] === 'success' ? 'Success!' : ($flash['type'] === 'error' ? 'Error!' : 'Notice')); ?>',
        text: '<?php echo esc($flash['message']); ?>',
        timer: 3000,
        showConfirmButton: false,
        toast: true,
        position: 'top-end'
    });
    <?php endif; ?>

    // 3. Global Academic Session Selector Handler
    $('#globalSessionSelector, #mobileSessionSelector').on('change', function() {
        const sessionId = $(this).val();
        
        $.ajax({
            url: '<?php echo $baseDir; ?>api/change-session.php',
            type: 'POST',
            data: { 
                session_id: sessionId,
                csrf_token: '<?php echo isset($_SESSION["csrf_token"]) ? $_SESSION["csrf_token"] : ""; ?>'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Session Switched',
                        text: 'Redirecting to updated view...',
                        timer: 1000,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', response.message || 'Failed to switch session', 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Communication failure with the server', 'error');
            }
        });
    });

    // Prevent profile dropdown from closing when interacting with mobile session switcher
    $(document).on('click', '.profile-dropdown-session', function(e) {
        e.stopPropagation();
    });

    // 4. Mark all notifications read
    $('#markAllNotificationsRead').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var btn = $(this);
        var originalText = btn.text();
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i>');
        
        $.ajax({
            url: '<?php echo $baseDir; ?>api/notifications.php',
            type: 'POST',
            data: { 
                action: 'read_all',
                csrf_token: '<?php echo isset($_SESSION["csrf_token"]) ? $_SESSION["csrf_token"] : ""; ?>'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('.notification-badge').remove();
                    $('.notification-list').html('<div class="p-4 text-center text-muted" style="font-size: 0.85rem;"><i class="fa-regular fa-bell-slash d-block fa-2x mb-2 text-secondary opacity-50"></i>No unread notifications</div>');
                    btn.remove();
                    if (window.location.href.includes('notifications.php')) {
                        window.location.reload();
                    }
                } else {
                    Swal.fire('Error', response.message || 'Failed to mark notifications as read', 'error');
                    btn.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                Swal.fire('Error', 'Communication failure with the server', 'error');
                btn.prop('disabled', false).text(originalText);
            }
        });
    });

    // 5. Periodic student notification polling
    <?php if ($isLoggedIn && ((int)($_SESSION['role_id'] ?? 0) === ROLE_STUDENT)): ?>
    setInterval(function() {
        $.ajax({
            url: '<?php echo $baseDir; ?>api/notifications.php',
            type: 'GET',
            data: { action: 'fetch_unread' },
            dataType: 'json',
            success: function(res) {
                if (res.success && res.unread_count !== undefined) {
                    var count = parseInt(res.unread_count, 10);
                    if (count > 0) {
                        if ($('.notification-badge').length) {
                            $('.notification-badge').text(count);
                        } else {
                            $('#notificationDropdown').append('<span class="notification-badge">' + count + '</span>');
                        }
                    } else {
                        $('.notification-badge').remove();
                    }
                }
            }
        });
    }, 45000);
    <?php endif; ?>
});
</script>

<?php
// Lazy execution of queued emails in the background
if ($isLoggedIn) {
    try {
        require_once(__DIR__ . '/../services/EmailService.php');
        require_once(__DIR__ . '/../config/database.php');
        $lazyDb = Database::getInstance()->getConnection();
        EmailService::processQueue($lazyDb, 3); // process up to 3 emails per page load
    } catch (Exception $e) {
        // Fail silently to avoid interrupting the page layout
    }
}
?>
</body>
</html>
