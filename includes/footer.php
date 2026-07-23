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

<!-- Global Common Script -->
<script>
$(document).ready(function() {
    // 1. Mobile Sidebar Toggle
    $('.sidebar-toggle').on('click', function() {
        $('#sidebar').toggleClass('show');
    });

    // Close sidebar when clicking outside on mobile
    $(document).on('click', function(event) {
        if (!$(event.target).closest('#sidebar, .sidebar-toggle').length) {
            $('#sidebar').removeClass('show');
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
    $('#globalSessionSelector').on('change', function() {
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
