// Appel de l'API de rédaction des fiches de formation du site (/api/formations).
//
//   node .claude/skills/creer-formation/formations.mjs [--prod] liste
//   node .claude/skills/creer-formation/formations.mjs [--prod] voir <CODE>
//   node .claude/skills/creer-formation/formations.mjs [--prod] enregistrer <fiche.json> [--simuler]
//
// Par défaut, le site local (https://127.0.0.1:8000) avec APP_API_JETON de .env.local.
// Avec --prod : CREER_FORMATION_PROD_URL et CREER_FORMATION_PROD_JETON de .env.local.
// Sortie : le code HTTP sur la première ligne, puis le JSON de la réponse.

import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const racine = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');

const env = Object.fromEntries(
    readFileSync(resolve(racine, '.env.local'), 'utf8')
        .split(/\r?\n/)
        .filter((ligne) => /^(APP_API_JETON|CREER_FORMATION_PROD_URL|CREER_FORMATION_PROD_JETON)=/.test(ligne))
        .map((ligne) => {
            const i = ligne.indexOf('=');
            return [ligne.slice(0, i), ligne.slice(i + 1).replace(/^["']|["']$/g, '')];
        }),
);

const args = process.argv.slice(2);
const prod = args.includes('--prod');
const simuler = args.includes('--simuler');
const [action, cible] = args.filter((arg) => !arg.startsWith('--'));

const base = prod ? env.CREER_FORMATION_PROD_URL : 'https://127.0.0.1:8000';
const jeton = prod ? env.CREER_FORMATION_PROD_JETON : env.APP_API_JETON;

if (!base || !jeton) {
    console.error(prod
        ? 'Renseigner CREER_FORMATION_PROD_URL et CREER_FORMATION_PROD_JETON dans .env.local.'
        : 'Renseigner APP_API_JETON dans .env.local.');
    process.exit(1);
}

// Le serveur local porte un certificat que Node ne connaît pas ; jamais en production.
if (!prod) {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
    process.removeAllListeners('warning');
}

let chemin = '/api/formations';
let options = { headers: { Authorization: 'Bearer ' + jeton, Accept: 'application/json' } };

if (action === 'voir' && cible) {
    chemin += '/' + encodeURIComponent(cible);
} else if (action === 'enregistrer' && cible) {
    const fiche = JSON.parse(readFileSync(cible, 'utf8'));

    if (simuler) {
        fiche.simuler = true;
    }

    options = {
        method: 'POST',
        headers: { ...options.headers, 'Content-Type': 'application/json' },
        body: JSON.stringify(fiche),
    };
} else if (action !== 'liste') {
    console.error('Usage : formations.mjs [--prod] liste | voir <CODE> | enregistrer <fiche.json> [--simuler]');
    process.exit(1);
}

try {
    const reponse = await fetch(base.replace(/\/$/, '') + chemin, options);

    console.log(reponse.status);
    console.log(await reponse.text());
} catch (erreur) {
    console.error('Site injoignable (' + base + ') : ' + (erreur.cause?.message ?? erreur.message));
    process.exit(1);
}
