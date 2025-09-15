// Chart helpers for Cacheer Monitor

export function createDriversDoughnutChart(canvasContext, labels, values) {
  const colorPalette = ['#0ea5e9', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#94a3b8'];
  const backgroundColors = labels.map((_, index) => colorPalette[index % colorPalette.length]);
  // eslint-disable-next-line no-undef
  return new Chart(canvasContext, {
    type: 'doughnut',
    data: {
      labels: labels,
      datasets: [
        {
          data: values,
          backgroundColor: backgroundColors,
        },
      ],
    },
    options: {
      plugins: { legend: { display: false } },
      cutout: '60%',
    },
  });
}

export function createLineChart(canvasContext, labels, datasets, options = {}) {
  // eslint-disable-next-line no-undef
  return new Chart(canvasContext, {
    type: 'line',
    data: {
      labels,
      datasets,
    },
    options: Object.assign({
      spanGaps: true,
      interaction: { mode: 'index', intersect: false },
      scales: {
        x: { ticks: { maxRotation: 0, autoSkip: true } },
        y: { beginAtZero: true },
      },
      plugins: { legend: { display: true } },
    }, options),
  });
}
