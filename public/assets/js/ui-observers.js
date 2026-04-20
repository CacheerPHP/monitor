// UI observers and enhancements for Cacheer Monitor

// ── Inspector backdrop observer ──
(function () {
  var panel = document.getElementById("inspectorPanel");
  var backdrop = document.getElementById("inspectorBackdrop");
  if (panel && backdrop) {
    var obs = new MutationObserver(function () {
      if (panel.classList.contains("translate-x-0")) {
        backdrop.classList.remove("hidden");
      } else {
        backdrop.classList.add("hidden");
      }
    });
    obs.observe(panel, { attributes: true, attributeFilter: ["class"] });
  }
})();

// ── Hit-rate SVG gauge ring observer ──
(function () {
  var gaugeRing = document.getElementById("gaugeRing");
  var hitRateEl = document.getElementById("hit_rate");
  if (!gaugeRing || !hitRateEl) {
    return;
  }

  var circumference = 2 * Math.PI * 58; // ~364.42

  function updateGauge() {
    var text = hitRateEl.textContent || "0%";
    var pct = parseFloat(text) || 0;
    gaugeRing.setAttribute("stroke-dashoffset", String(circumference * (1 - pct / 100)));
  }

  var obs = new MutationObserver(updateGauge);
  obs.observe(hitRateEl, {
    childList: true,
    characterData: true,
    subtree: true,
  });
  updateGauge();
})();

// ── Activity pulse on fetch ──
(function () {
  var bar = document.getElementById("activityBar");
  if (!bar) {
    return;
  }

  var origFetch = window.fetch;
  var active = 0;

  window.fetch = function () {
    active++;
    bar.classList.add("active");
    return origFetch.apply(this, arguments).finally(function () {
      active--;
      if (active <= 0) {
        active = 0;
        setTimeout(function () {
          if (active === 0) {
            bar.classList.remove("active");
          }
        }, 300);
      }
    });
  };
})();

// ── Sidebar active section highlight ──
(function () {
  var sections = ["metricsSection", "chartsSection", "eventsSection", "deepDiveSection"];
  var links = document.querySelectorAll(".side-nav a");
  if (!links.length) {
    return;
  }

  var observer = new IntersectionObserver(
    function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          links.forEach(function (link) {
            var href = link.getAttribute("href");
            if (href === "#" + entry.target.id) {
              link.classList.add("active");
            } else {
              link.classList.remove("active");
            }
          });
        }
      });
    },
    { rootMargin: "-20% 0px -60% 0px" },
  );

  sections.forEach(function (id) {
    var el = document.getElementById(id);
    if (el) {
      observer.observe(el);
    }
  });
})();

// ── Time range pill active class ──
(function () {
  document.querySelectorAll("[data-time-range]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      document.querySelectorAll("[data-time-range]").forEach(function (b) {
        b.classList.remove("active", "bg-blue-600", "text-white", "border-blue-600");
        b.classList.remove("bg-white", "dark:bg-slate-800", "text-slate-600", "dark:text-slate-300");
      });
      btn.classList.add("active");
    });
  });
})();
