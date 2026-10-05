// Apply the saved preference before the first paint.
(function () {
  let preference = null;
  try {
    preference = localStorage.getItem("cacheer-theme");
  } catch (_) {}
  const dark =
    preference === "dark" || (preference !== "light" && window.matchMedia("(prefers-color-scheme: dark)").matches);
  document.documentElement.classList.toggle("dark", dark);
})();
