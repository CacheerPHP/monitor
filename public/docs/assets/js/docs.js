document.addEventListener('DOMContentLoaded', () => {
  const sectionElements = Array.from(document.querySelectorAll('.doc-section'));
  const navButtons = Array.from(document.querySelectorAll('.doc-nav-item'));
  const sidebar = document.getElementById('sidebar');
  const navToggle = document.getElementById('navToggle');
  const tocList = document.getElementById('tocList');

  const openIcon = navToggle ? navToggle.querySelector('[data-icon="open"]') : null;
  const closeIcon = navToggle ? navToggle.querySelector('[data-icon="close"]') : null;

  const sections = new Map(sectionElements.map((el) => [el.dataset.section, el]));

  function slugify(value) {
    return value
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9\s-]/g, '')
      .replace(/\s+/g, '-')
      .replace(/-+/g, '-');
  }

  function buildToc(section) {
    if (!tocList) {
      return;
    }
    tocList.innerHTML = '';
    if (!section) {
      return;
    }
    const headings = Array.from(section.querySelectorAll('h2, h3, h4'));
    headings.forEach((heading, index) => {
      const tag = heading.tagName.toLowerCase();
      if (!heading.id) {
        heading.id = `${section.dataset.section}-${slugify(heading.textContent || '')}-${index}`;
      }
      const item = document.createElement('li');
      item.className = 'flex';
      const link = document.createElement('a');
      link.href = `#${heading.id}`;
      link.textContent = heading.textContent || '';
      link.className = 'block w-full rounded px-2 py-1 text-slate-300 hover:text-brand-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400';
      if (tag === 'h3') {
        link.classList.add('pl-4', 'text-slate-400');
      } else if (tag === 'h4') {
        link.classList.add('pl-6', 'text-slate-500');
      }
      item.appendChild(link);
      tocList.appendChild(item);
    });
  }

  function setActiveNav(targetSection) {
    navButtons.forEach((button) => {
      const isActive = button.dataset.sectionTarget === targetSection;
      button.classList.toggle('bg-slate-800/60', isActive);
      button.classList.toggle('text-brand-200', isActive);
      button.classList.toggle('ring-2', isActive);
      button.classList.toggle('ring-brand-400/60', isActive);
      button.setAttribute('aria-current', isActive ? 'page' : 'false');
    });
  }

  function showSection(targetSection, { updateHash = true } = {}) {
    const section = sections.get(targetSection) || sections.values().next().value;
    if (!section) {
      return;
    }
    sectionElements.forEach((element) => {
      element.classList.toggle('hidden', element !== section);
    });
    setActiveNav(section.dataset.section);
    buildToc(section);
    if (updateHash) {
      history.replaceState(null, '', `#${section.dataset.section}`);
    }
    if (window.innerWidth < 768 && sidebar && !sidebar.classList.contains('hidden')) {
      toggleSidebar(false);
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function toggleSidebar(forceOpen) {
    if (!sidebar || !navToggle) {
      return;
    }
    const shouldOpen = typeof forceOpen === 'boolean' ? forceOpen : sidebar.classList.contains('hidden');
    sidebar.classList.toggle('hidden', !shouldOpen);
    navToggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    if (openIcon && closeIcon) {
      openIcon.classList.toggle('hidden', shouldOpen);
      closeIcon.classList.toggle('hidden', !shouldOpen);
    }
  }

  navButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const target = button.dataset.sectionTarget;
      showSection(target);
    });
  });

  if (navToggle) {
    navToggle.addEventListener('click', () => {
      toggleSidebar();
    });
  }

  window.addEventListener('resize', () => {
    if (!sidebar || !navToggle) {
      return;
    }
    if (window.innerWidth >= 768) {
      sidebar.classList.remove('hidden');
      navToggle.setAttribute('aria-expanded', 'false');
      if (openIcon && closeIcon) {
        openIcon.classList.remove('hidden');
        closeIcon.classList.add('hidden');
      }
    } else if (navToggle.getAttribute('aria-expanded') !== 'true') {
      sidebar.classList.add('hidden');
    }
  });

  window.addEventListener('hashchange', () => {
    const sectionFromHash = window.location.hash.replace('#', '');
    if (sections.has(sectionFromHash)) {
      showSection(sectionFromHash, { updateHash: false });
    }
  });

  // Initial render
  const initialSection = (() => {
    const fromHash = window.location.hash.replace('#', '');
    if (sections.has(fromHash)) {
      return fromHash;
    }
    return 'overview';
  })();

  showSection(initialSection, { updateHash: false });

  if (sidebar) {
    if (window.innerWidth >= 768) {
      sidebar.classList.remove('hidden');
    } else {
      sidebar.classList.add('hidden');
    }
  }
});
