$(function () {
    const currentHost = window.location.hostname;
    $('#ezstudio-active-host').text(currentHost);

    // Mise en évidence automatique du lien actif dans la barre de navigation
    $('#ezstudioNavbar .nav-link').each(function () {
        if (this.hostname === currentHost) {
            $(this).addClass('active fw-semibold');
        }
    });
});
