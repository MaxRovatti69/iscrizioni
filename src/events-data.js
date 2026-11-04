export const PISA_URL = 'https://egs-eventi.s3.eu-west-1.amazonaws.com/00000000/00100000/00102700/00102708/data/entrants.json';
export function validateEvent(name,url) {
  name=name.trim();url=url.trim();
  if (!name || name.length>150) throw new Error('Inserisci un nome evento (massimo 150 caratteri).');
  if (url && !/^https:\/\/egs-eventi\.s3\.eu-west-1\.amazonaws\.com\/(?:[0-9]{8}\/){4}data\/entrants\.json$/.test(url)) throw new Error('Usa un link Endu entrants.json valido, oppure lascia il campo vuoto.');
  return {name,url};
}
