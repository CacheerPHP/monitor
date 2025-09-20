document.addEventListener('DOMContentLoaded', () => {
  const toggleButton = document.getElementById('docsNavToggle');
  const nav = document.getElementById('docsNav');
  if (!toggleButton || !nav) {
    return;
  }

  const links = Array.from(nav.querySelectorAll('a'));
  const openIcon = toggleButton.querySelector('[data-icon="open"]');
  const closeIcon = toggleButton.querySelector('[data-icon="close"]');

  function closeNav() {
    nav.classList.add('hidden');
    toggleButton.setAttribute('aria-expanded', 'false');
    if (openIcon && closeIcon) {
      openIcon.classList.remove('hidden');
      closeIcon.classList.add('hidden');
    }
  }

  toggleButton.addEventListener('click', () => {
    const expanded = toggleButton.getAttribute('aria-expanded') === 'true';
    toggleButton.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    nav.classList.toggle('hidden', expanded);
    if (openIcon && closeIcon) {
      openIcon.classList.toggle('hidden', !expanded);
      closeIcon.classList.toggle('hidden', expanded);
    }
  });

  links.forEach((link) => {
    link.addEventListener('click', () => {
      if (window.matchMedia('(max-width: 767px)').matches) {
        closeNav();
      }
    });
  });

  window.addEventListener('resize', () => {
    if (!window.matchMedia('(max-width: 767px)').matches) {
      nav.classList.remove('hidden');
      toggleButton.setAttribute('aria-expanded', 'false');
      if (openIcon && closeIcon) {
        openIcon.classList.remove('hidden');
        closeIcon.classList.add('hidden');
      }
    } else {
      if (toggleButton.getAttribute('aria-expanded') !== 'true') {
        nav.classList.add('hidden');
      }
    }
  });

  if (!window.matchMedia('(max-width: 767px)').matches) {
    nav.classList.remove('hidden');
  }
});
