// Chart styling follows the dashboard's local design tokens.
const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
export const chartPalette = () => [
  token("--teal"),
  token("--accent"),
  token("--warning"),
  token("--danger"),
  token("--muted"),
];
const reducedMotion = () => window.matchMedia("(prefers-reduced-motion: reduce)").matches;
const font = { family: "DM Sans", size: 9 };

function tooltipStyle() {
  return {
    backgroundColor: token("--surface"),
    titleColor: token("--text"),
    bodyColor: token("--muted"),
    borderColor: token("--border"),
    borderWidth: 1,
    cornerRadius: 5,
    padding: 10,
    titleFont: font,
    bodyFont: font,
  };
}
function baseOptions() {
  return {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: reducedMotion() ? 0 : 200 },
    plugins: { legend: { display: false }, tooltip: tooltipStyle() },
  };
}
function scales(integer = false) {
  return {
    x: {
      border: { display: false },
      grid: { display: false },
      ticks: { color: token("--faint"), font, maxRotation: 0, autoSkip: true, maxTicksLimit: 6 },
    },
    y: {
      beginAtZero: true,
      border: { display: false },
      grid: { color: token("--border"), drawTicks: false },
      ticks: { color: token("--faint"), font, padding: 10, maxTicksLimit: 4, ...(integer ? { precision: 0 } : {}) },
    },
  };
}
export function createDriversDoughnutChart(context, labels, values) {
  // An empty ring represents absence of data without inventing a driver.
  const empty = values.every((value) => !value);
  return new Chart(context, {
    type: "doughnut",
    data: {
      labels: empty ? ["No activity"] : labels,
      datasets: [
        {
          data: empty ? [1] : values,
          backgroundColor: empty
            ? [token("--border")]
            : labels.map((_, i) => chartPalette()[i % chartPalette().length]),
          borderWidth: 0,
          hoverOffset: empty ? 0 : 3,
        },
      ],
    },
    options: {
      ...baseOptions(),
      cutout: "78%",
      plugins: { legend: { display: false }, tooltip: { ...tooltipStyle(), enabled: !empty } },
    },
  });
}
export function createBarChart(context, labels, values, color = token("--accent")) {
  return new Chart(context, {
    type: "bar",
    data: {
      labels,
      datasets: [{ data: values, backgroundColor: color, borderRadius: 3, borderSkipped: false, maxBarThickness: 28 }],
    },
    options: { ...baseOptions(), scales: scales(true) },
  });
}
export function createLineChart(context, labels, datasets, options = {}) {
  const palette = chartPalette();
  const latency = datasets.length === 1;
  const series = datasets.map((dataset, index) => ({
    ...dataset,
    borderColor: palette[latency ? 1 : index === 0 ? 0 : 2],
    backgroundColor: "transparent",
    borderWidth: 2,
    borderDash: !latency && index === 1 ? [4, 3] : [],
    fill: false,
    pointRadius: 0,
    pointHoverRadius: 3,
    tension: 0.25,
  }));
  return new Chart(context, {
    type: "line",
    data: { labels, datasets: series },
    options: {
      ...baseOptions(),
      ...options,
      interaction: { mode: "index", intersect: false },
      scales: scales(!latency),
      plugins: {
        legend: {
          display: true,
          position: "bottom",
          align: "start",
          labels: {
            color: token("--muted"),
            usePointStyle: true,
            pointStyle: "circle",
            boxHeight: 5,
            boxWidth: 5,
            padding: 15,
            font: { ...font, size: 10 },
          },
        },
        tooltip: tooltipStyle(),
      },
    },
  });
}
