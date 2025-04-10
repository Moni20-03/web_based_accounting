<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
}

// Function to redirect after successful submission
function redirectAfterSubmission($successUrl = 'dashboard.php') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
        $_SESSION['form_submitted'] = true;
        header("Location: $successUrl");
        exit();
    }
}

// Function to clear browser cache and prevent back-button access
function preventCacheAndBackButton() {
    echo <<<HTML
    <script>
    // Clear form cache
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
    
    // Prevent back button
    window.onpageshow = function(event) {
        if (event.persisted) {
            window.location.reload();
        }
    };
    
    // Clear all form fields on page load
    window.onload = function() {
        document.querySelectorAll('form').forEach(form => form.reset());
        document.querySelectorAll('input, select, textarea').forEach(field => {
            if (field.type !== 'submit' && field.type !== 'button') {
                field.value = '';
            }
        });
    };
    </script>
    HTML;
}

// Function to show confirmation dialog
function confirmSubmission($formId) {
    echo <<<HTML
    <script>
    document.getElementById('$formId').addEventListener('submit', function(e) {
        if (!confirm('Are you sure you want to submit this form? Please verify all details before proceeding.')) {
            e.preventDefault();
        }
    });
    </script>
    HTML;
}
?>