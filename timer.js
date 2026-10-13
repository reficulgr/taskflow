// Countdown timer — auto-submits when time runs out
function startTimer(endTime, duration) {
  const footer = document.getElementById('timer-footer');
  const bar = footer.querySelector('.timer-bar');
  const display = footer.querySelector('.timer-display');
  const totalSeconds = duration * 60;

  function tick() {
    const remaining = Math.max(0, endTime - Math.floor(Date.now() / 1000));
    if (remaining <= 0) {
      // Auto-complete: submit the force_complete form
      const form = footer.querySelector('form');
      if (form) form.submit();
      return;
    }
    const mins = String(Math.floor(remaining / 60)).padStart(2, '0');
    const secs = String(remaining % 60).padStart(2, '0');
    display.textContent = mins + ':' + secs;
    const pct = Math.min(((totalSeconds - remaining) / totalSeconds) * 100, 100);
    bar.style.width = pct + '%';
    // Gold → red as time runs out
    const hue = 47 * (pct / 100);
    const sat = 75 + 20 * (pct / 100);
    bar.style.backgroundColor = `hsl(${hue}, ${sat}%, 55%)`;
  }

  tick();
  setInterval(tick, 1000);
}