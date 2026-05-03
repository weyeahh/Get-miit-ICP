export function epochSeconds() {
  return Math.floor(Date.now() / 1000);
}

export function sleep(milliseconds) {
  return new Promise((resolve) => {
    setTimeout(resolve, milliseconds);
  });
}

export function formatLocalDate(date = new Date()) {
  const year = date.getFullYear();
  const month = pad2(date.getMonth() + 1);
  const day = pad2(date.getDate());
  return `${year}-${month}-${day}`;
}

export function formatLocalTimestampCompact(date = new Date()) {
  const year = date.getFullYear();
  const month = pad2(date.getMonth() + 1);
  const day = pad2(date.getDate());
  const hours = pad2(date.getHours());
  const minutes = pad2(date.getMinutes());
  const seconds = pad2(date.getSeconds());
  return `${year}${month}${day}-${hours}${minutes}${seconds}`;
}

function pad2(value) {
  return String(value).padStart(2, '0');
}

export function localISOString(date = new Date()) {
  const y = date.getFullYear();
  const M = pad2(date.getMonth() + 1);
  const d = pad2(date.getDate());
  const H = pad2(date.getHours());
  const m = pad2(date.getMinutes());
  const s = pad2(date.getSeconds());
  const ms = String(date.getMilliseconds()).padStart(3, '0');
  const offset = -date.getTimezoneOffset();
  const sign = offset >= 0 ? '+' : '-';
  const abs = Math.abs(offset);
  const oh = pad2(Math.floor(abs / 60));
  const om = pad2(abs % 60);
  return `${y}-${M}-${d}T${H}:${m}:${s}.${ms}${sign}${oh}:${om}`;
}
