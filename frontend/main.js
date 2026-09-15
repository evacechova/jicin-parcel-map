import './style.css';

const status = document.querySelector('#connection-status');

try {
  const response = await fetch('/api');
  if (!response.ok) throw new Error(`HTTP ${response.status}`);

  const data = await response.json();
  if (typeof data.name !== 'string') throw new Error('Invalid foundation response');

  status.textContent = `Server je dostupný: ${data.name}.`;
} catch {
  status.textContent = 'Spojení se serverem se nezdařilo. Spusťte PHP server a obnovte stránku.';
}
