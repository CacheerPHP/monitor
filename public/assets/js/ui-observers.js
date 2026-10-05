// A quiet activity indicator and section navigation, using local assets only.
(function () {
  const bar = document.getElementById("activityBar");
  const originalFetch = window.fetch;
  let active = 0;
  window.fetch = function (...args) {
    active++;
    bar?.classList.add("active");
    return originalFetch.apply(this, args).finally(() => {
      active--;
      setTimeout(() => {
        if (!active) {
          bar?.classList.remove("active");
        }
      }, 200);
    });
  };
  const links = document.querySelectorAll(".side-nav a");
  const observer = new IntersectionObserver(
    (entries) => {
      const entry = entries.find((item) => item.isIntersecting);
      if (!entry) {
        return;
      }
      links.forEach((link) => {
        const selected = link.getAttribute("href") === "#" + entry.target.id;
        link.classList.toggle("active", selected);
        if (selected) {
          link.setAttribute("aria-current", "location");
        } else {
          link.removeAttribute("aria-current");
        }
      });
    },
    { rootMargin: "-15% 0px -65% 0px" },
  );
  for (const id of ["metricsSection", "chartsSection", "eventsSection", "deepDiveSection"]) {
    const section = document.getElementById(id);
    if (section) {
      observer.observe(section);
    }
  }
})();
