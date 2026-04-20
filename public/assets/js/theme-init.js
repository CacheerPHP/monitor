// Synchronous theme init — runs before any rendering to prevent FOUC
(function () {
  var saved = null;
  try {
    saved = localStorage.getItem("cacheer-theme");
  } catch (_) {}
  if (saved === "light") {
    document.documentElement.classList.remove("dark");
  } else {
    document.documentElement.classList.add("dark");
  }
})();
