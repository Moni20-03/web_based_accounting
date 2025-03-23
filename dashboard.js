document.addEventListener("DOMContentLoaded", () => {
    const sidebar = document.getElementById("sidebar");
    const openSidebar = document.getElementById("openSidebar");
    const closeSidebar = document.getElementById("closeSidebar");
    const themeToggle = document.getElementById("themeToggle");
    const subMenus = document.querySelectorAll(".dropdown");

    // Sidebar toggle
    openSidebar.addEventListener("click", () => {
        sidebar.classList.add("active");
    });

    closeSidebar.addEventListener("click", () => {
        sidebar.classList.remove("active");
    });

    // Submenu toggle
    subMenus.forEach(menu => {
        menu.addEventListener("click", () => {
            menu.classList.toggle("active");
        });
    });

    // Theme toggle (Light/Dark Mode)
    themeToggle.addEventListener("click", () => {
        document.body.classList.toggle("dark-mode");
        themeToggle.textContent = document.body.classList.contains("dark-mode") ? "☀️" : "🌙";
    });
});
