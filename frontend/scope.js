export function parseScopeCsv(csv) {
  if (typeof csv !== 'string') {
    throw new TypeError('Scope CSV must be a string.');
  }

  const lines = csv
    .split(/\r?\n/u)
    .map((line) => line.trim())
    .filter((line) => line !== '');

  if (lines.shift() !== 'ku_code;name') {
    throw new Error('The cadastral scope header is invalid.');
  }

  const territories = lines.map((line) => {
    const separator = line.indexOf(';');
    if (separator === -1) {
      throw new Error('The cadastral scope row is invalid.');
    }

    const code = line.slice(0, separator);
    const name = line.slice(separator + 1).trim();
    if (!/^[0-9]{6}$/u.test(code) || name === '') {
      throw new Error('The cadastral scope row is invalid.');
    }

    return Object.freeze({ code, name });
  });

  const codes = territories.map(({ code }) => code);
  if (territories.length !== 240 || new Set(codes).size !== territories.length) {
    throw new Error('The Jičín scope must contain 240 unique cadastral territories.');
  }

  const sortedCodes = [...codes].sort((left, right) => left.localeCompare(right, 'en'));
  if (!codes.every((code, index) => code === sortedCodes[index])) {
    throw new Error('The Jičín scope must be sorted by cadastral code.');
  }

  return Object.freeze(territories);
}
