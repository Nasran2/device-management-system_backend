const sidebar = document.getElementById('app-sidebar');
const sidebarToggle = document.getElementById('sidebar-toggle');
const sidebarOverlay = document.getElementById('sidebar-overlay');

const closeSidebar = () => {
    sidebar?.classList.remove('open');
    sidebarOverlay?.classList.add('hidden');
};

sidebarToggle?.addEventListener('click', () => {
    sidebar?.classList.toggle('open');
    sidebarOverlay?.classList.toggle('hidden');
});
sidebarOverlay?.addEventListener('click', closeSidebar);
sidebar?.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeSidebar));
