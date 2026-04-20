// Chart factory functions for Cacheer Monitor

// [ theme helpers ]

const isDark = () => document.documentElement.classList.contains("dark");
const gridColor = () => (isDark() ? "rgba(148,163,184,0.1)" : "rgba(148,163,184,0.15)");
const tickColor = () => (isDark() ? "#64748b" : "#94a3b8");

function tooltipStyle() {
  return {
    backgroundColor: isDark() ? "#1e293b" : "#ffffff",
    titleColor: isDark() ? "#e2e8f0" : "#1e293b",
    bodyColor: isDark() ? "#94a3b8" : "#64748b",
    borderColor: isDark() ? "#334155" : "#e2e8f0",
    borderWidth: 1,
    cornerRadius: 8,
    padding: 10,
  };
}

// [ factories ]

export function createDriversDoughnutChart(canvasContext, labels, values) {
  const palette = ["#0ea5e9", "#22c55e", "#f59e0b", "#ef4444", "#8b5cf6", "#14b8a6", "#94a3b8"];
  const bgColors = labels.map((_, i) => palette[i % palette.length]);

  return new Chart(canvasContext, {
    type: "doughnut",
    data: {
      labels,
      datasets: [
        {
          data: values,
          backgroundColor: bgColors,
          borderWidth: 0,
          hoverBorderWidth: 2,
          hoverBorderColor: isDark() ? "#1e293b" : "#ffffff",
        },
      ],
    },
    options: {
      responsive: true,
      cutout: "65%",
      animation: { animateRotate: true, duration: 600 },
      plugins: {
        legend: { display: false },
        tooltip: tooltipStyle(),
      },
    },
  });
}

export function createBarChart(canvasContext, labels, values, color = "#8b5cf6") {
  return new Chart(canvasContext, {
    type: "bar",
    data: {
      labels,
      datasets: [
        {
          data: values,
          backgroundColor: color,
          borderRadius: 4,
          borderSkipped: false,
        },
      ],
    },
    options: {
      responsive: true,
      animation: { duration: 400 },
      plugins: {
        legend: { display: false },
        tooltip: tooltipStyle(),
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { color: tickColor(), font: { size: 10 } },
        },
        y: {
          beginAtZero: true,
          grid: { color: gridColor(), drawBorder: false },
          ticks: { color: tickColor(), font: { size: 10 }, precision: 0 },
        },
      },
    },
  });
}

export function createLineChart(canvasContext, labels, datasets, options = {}) {
  return new Chart(canvasContext, {
    type: "line",
    data: {
      labels,
      datasets: datasets.map((ds) => ({ ...ds, borderWidth: 2 })),
    },
    options: Object.assign(
      {
        responsive: true,
        spanGaps: true,
        interaction: { mode: "index", intersect: false },
        animation: { duration: 400 },
        plugins: {
          legend: {
            display: true,
            labels: {
              color: tickColor(),
              usePointStyle: true,
              pointStyle: "circle",
              padding: 16,
              font: { size: 11 },
            },
          },
          tooltip: tooltipStyle(),
        },
        scales: {
          x: {
            grid: { color: gridColor(), drawBorder: false },
            ticks: { maxRotation: 0, autoSkip: true, color: tickColor(), font: { size: 10 } },
          },
          y: {
            beginAtZero: true,
            grid: { color: gridColor(), drawBorder: false },
            ticks: { color: tickColor(), font: { size: 10 } },
          },
        },
      },
      options,
    ),
  });
}
