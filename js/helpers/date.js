

export function getEffectiveDate(selectedDate) {
  if (selectedDate && selectedDate !== '') {
    return selectedDate;
  }
  return new Date().toISOString().slice(0, 10);
}

export function getPreviousWeekDates() {
  const today = new Date();

  // 0 = Sunday, 6 = Saturday
  const todayDay = today.getDay();

  // Go back to LAST Saturday
  const lastSaturday = new Date(today);
  lastSaturday.setDate(today.getDate() - todayDay - 1);

  // Sunday before that Saturday
  const lastSunday = new Date(lastSaturday);
  lastSunday.setDate(lastSaturday.getDate() - 6);

  const week = [];
  for (let i = 0; i < 7; i++) {
    const d = new Date(lastSunday);
    d.setDate(lastSunday.getDate() + i);
    week.push(d.toISOString().slice(0, 10));
  }

  return week;
}

export function normalizeSalesToPreviousWeek(savedSales = []) {
  const weekDates = getPreviousWeekDates();

  return weekDates.map((date, idx) => {
    const src = savedSales[idx] || {};

    return {
      day_label: date,                       // 🔑 last full week
      sale_price: Number(src.sale_price) || 0,
      market_price: Number(src.market_price) || 0,
      volume: Number(src.volume) || 0
    };
  });
}

export function formatSaveTime(date = new Date()) {
  return date.toLocaleTimeString("en-US", {
    timeZone: "America/New_York",
    hour: "2-digit",
    minute: "2-digit",
    hour12: true
  });
}


export function normalizeDate(val) {
  if (!val) return null;
  if (/^\d{4}-\d{2}-\d{2}$/.test(val)) return val;

  const d = new Date(val);
  return isNaN(d) ? null : d.toISOString().slice(0, 10);
}





