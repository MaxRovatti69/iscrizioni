export function entrantCount(payload) {
  if (!Array.isArray(payload?.data) || payload.data.length !== 1) throw new Error('Formato del totale iscritti non valido.');
  const value = payload.data[0]?.count;
  if (!['string', 'number'].includes(typeof value) || !/^\d+$/.test(String(value)) || !Number.isSafeInteger(Number(value))) throw new Error('Numero di iscritti non valido.');
  return Number(value);
}
