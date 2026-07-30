// Point the docs header logo back to the marketing site (nativeblade.dev)
// instead of the docs root. docmd renders it as <a class="logo-link" href="./">.
(function () {
  function relink() {
    document.querySelectorAll('a.logo-link').forEach(function (a) {
      a.setAttribute('href', 'https://nativeblade.dev');
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', relink);
  } else {
    relink();
  }
}());
