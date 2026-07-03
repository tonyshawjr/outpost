
document.querySelectorAll('[data-count]').forEach((btn) => {
  let n = 0;
  btn.addEventListener('click', () => {
    n += 1;
    btn.textContent = `Clicked ${n} time${n === 1 ? '' : 's'}`;
  });
});

