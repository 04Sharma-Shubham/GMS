(() => {
  const node = document.getElementById("chart-data");
  if (!node || !window.Chart) return;
  const data = JSON.parse(node.textContent);
  const lime = "#d6ff4a";
  const grid = "rgba(255,255,255,.06)";
  const tick = "#aeb6c2";
  Chart.defaults.color = tick;
  Chart.defaults.font.family = "Manrope, sans-serif";
  const line = (id, labels, values, color = lime) => {
    const canvas = document.getElementById(id);
    if (!canvas) return;
    new Chart(canvas, {
      type: "line",
      data: { labels, datasets: [{ data: values, borderColor: color, backgroundColor: "rgba(214,255,74,.14)", fill: true, tension: .35, pointRadius: 0, borderWidth: 2 }] },
      options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { color: grid } }, y: { grid: { color: grid }, beginAtZero: true } } },
    });
  };
  line("revenueChart", data.revenue.labels, data.revenue.values);
  line("growthChart", data.growth.labels, data.growth.values, "#8eb6ff");
  line("attendanceChart", data.attendance.labels, data.attendance.values, "#ffc857");
  const plans = document.getElementById("planChart");
  if (plans) {
    new Chart(plans, {
      type: "doughnut",
      data: { labels: data.plans.labels, datasets: [{ data: data.plans.values, backgroundColor: [lime, "#8eb6ff", "#ffc857", "#ff6b81", "#d5dbe3"], borderWidth: 0 }] },
      options: { maintainAspectRatio: false, plugins: { legend: { position: "bottom" } } },
    });
  }
  const status = document.getElementById("statusChart");
  if (status) {
    new Chart(status, {
      type: "bar",
      data: { labels: ["Paid", "Partially Paid", "Unpaid"], datasets: [{ data: [data.payment_status.paid, data.payment_status.partial, data.payment_status.unpaid], backgroundColor: [lime, "#ffc857", "#ff6b81"], borderRadius: 8 }] },
      options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { grid: { color: grid }, beginAtZero: true, ticks: { precision: 0 } } } },
    });
  }
})();
