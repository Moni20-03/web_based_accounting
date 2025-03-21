document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');
    const openSidebarBtn = document.getElementById('openSidebar');
    const closeSidebarBtn = document.getElementById('closeSidebar');
    const themeToggleBtn = document.getElementById('themeToggle');

    // Toggle sidebar collapse
    openSidebarBtn.addEventListener('click', () => {
        sidebar.classList.toggle('active');
    });

    closeSidebarBtn.addEventListener('click', () => {
        sidebar.classList.toggle('active');
    });

    // Toggle light/dark theme
    themeToggleBtn.addEventListener('click', () => {
        document.body.dataset.theme = document.body.dataset.theme === 'dark' ? 'light' : 'dark';
    });
});