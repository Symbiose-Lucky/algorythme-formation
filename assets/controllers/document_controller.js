/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';

/*
 * Affiche un PDF page par page, avec les zones de signature de chaque
 * signataire par-dessus. Trois usages :
 *
 * - lecture : les zones montrent la signature apposée, ou le nom de la
 *   personne attendue ;
 * - placement (admin, `placement` à true) : on choisit un signataire puis
 *   on trace sa zone sur la page, à la souris ; un clic simple pose une
 *   zone de taille standard. Les zones sont écrites dans un champ caché,
 *   que le formulaire enregistre ;
 * - téléchargement : assemble dans le navigateur le PDF signé (signatures
 *   incrustées dans leurs zones, certificat en dernière page).
 *
 * Les coordonnées d'une zone sont des fractions de la page (0 à 1) depuis
 * son coin supérieur gauche : elles ne dépendent pas de la taille d'écran.
 *
 * <div data-controller="document"
 *      data-document-fichier-value="/…/fichier"
 *      data-document-worker-value="{{ asset('vendor/pdfjs-dist/legacy/build/pdf.worker.mjs.js') }}"
 *      data-document-donnees-value="{…}"
 *      data-document-placement-value="true">
 *     <p data-document-target="etat">Chargement…</p>
 *     <div data-document-target="pages"></div>
 *     <input type="hidden" name="zones" data-document-target="champ">
 * </div>
 */
export default class extends Controller {
    static targets = ['pages', 'champ', 'etat', 'modifie'];
    static values = {
        fichier: String,
        worker: String,
        donnees: Object,
        placement: Boolean,
    };

    async connect() {
        this.signataires = this.donneesValue.signataires ?? [];
        this.actif = null;
        this.ebauche = null;

        this.synchroniser(false);

        try {
            await this.afficher();
        } catch (erreur) {
            console.error(erreur);
            this.dire("Le document n'a pas pu être affiché. Vous pouvez le télécharger pour le lire.");
        }
    }

    // ------------------------------------------------------------ Affichage

    async afficher() {
        pdfjs.GlobalWorkerOptions.workerSrc = this.workerValue;

        const pdf = await pdfjs.getDocument({ url: this.fichierValue }).promise;
        const largeur = Math.min(this.pagesTarget.clientWidth || 800, 900);
        const densite = Math.min(window.devicePixelRatio || 1, 2);

        this.pagesTarget.replaceChildren();

        for (let numero = 1; numero <= pdf.numPages; numero++) {
            const page = await pdf.getPage(numero);
            const base = page.getViewport({ scale: 1 });
            const vue = page.getViewport({ scale: (largeur / base.width) * densite });

            const cadre = document.createElement('div');
            cadre.className = 'adm-page';
            cadre.dataset.page = numero;
            cadre.style.aspectRatio = `${base.width} / ${base.height}`;

            const toile = document.createElement('canvas');
            toile.width = Math.round(vue.width);
            toile.height = Math.round(vue.height);
            cadre.append(toile);

            this.pagesTarget.append(cadre);

            await page.render({ canvasContext: toile.getContext('2d'), viewport: vue }).promise;
        }

        if (this.hasEtatTarget) {
            this.etatTarget.hidden = true;
        }

        this.dessiner();
    }

    // Redessine toutes les zones par-dessus les pages.
    dessiner() {
        this.pagesTarget.querySelectorAll('.adm-zone').forEach((zone) => zone.remove());

        this.signataires.forEach((signataire, rang) => {
            signataire.zones.forEach((zone, index) => {
                const cadre = this.pagesTarget.querySelector(`.adm-page[data-page="${zone.page}"]`);

                if (!cadre) {
                    return;
                }

                const element = document.createElement('div');
                element.className = `adm-zone adm-zone--${rang % 5}`;
                element.style.left = `${zone.x * 100}%`;
                element.style.top = `${zone.y * 100}%`;
                element.style.width = `${zone.l * 100}%`;
                element.style.height = `${zone.h * 100}%`;

                if (signataire.signature) {
                    const image = document.createElement('img');
                    image.src = signataire.signature;
                    image.alt = `Signature de ${signataire.nom}`;
                    element.classList.add('adm-zone--signee');
                    element.append(image);
                } else {
                    const libelle = document.createElement('span');
                    libelle.textContent = signataire.moi ? 'Votre signature' : signataire.nom;
                    element.classList.toggle('adm-zone--moi', Boolean(signataire.moi));
                    element.append(libelle);
                }

                if (this.placementValue && !signataire.verrouille) {
                    const retirer = document.createElement('button');
                    retirer.type = 'button';
                    retirer.textContent = '×';
                    retirer.title = 'Retirer cette zone';
                    retirer.setAttribute('aria-label', `Retirer cette zone de ${signataire.nom}`);
                    retirer.addEventListener('click', () => {
                        signataire.zones.splice(index, 1);
                        this.synchroniser(true);
                        this.dessiner();
                    });
                    element.append(retirer);
                }

                cadre.append(element);
            });
        });
    }

    // ------------------------------------------------------------ Placement

    // Bouton radio d'un signataire : c'est pour lui que l'on trace.
    choisir(event) {
        const id = Number(event.currentTarget.value);
        this.actif = this.signataires.find((s) => s.id === id && !s.verrouille) ?? null;
        this.pagesTarget.classList.toggle('adm-pages--placement', this.actif !== null);
    }

    commencer(event) {
        const cadre = event.target.closest('.adm-page');

        if (!this.placementValue || !this.actif || !cadre || event.target.closest('.adm-zone')) {
            return;
        }

        event.preventDefault();
        cadre.setPointerCapture?.(event.pointerId);

        const depart = this.position(event, cadre);
        const element = document.createElement('div');
        element.className = 'adm-zone adm-zone--ebauche';
        cadre.append(element);

        this.ebauche = { cadre, depart, element, zone: null };
    }

    tracer(event) {
        if (!this.ebauche) {
            return;
        }

        const { cadre, depart, element } = this.ebauche;
        const point = this.position(event, cadre);

        const zone = {
            x: Math.min(depart.x, point.x),
            y: Math.min(depart.y, point.y),
            l: Math.abs(point.x - depart.x),
            h: Math.abs(point.y - depart.y),
        };

        element.style.left = `${zone.x * 100}%`;
        element.style.top = `${zone.y * 100}%`;
        element.style.width = `${zone.l * 100}%`;
        element.style.height = `${zone.h * 100}%`;

        this.ebauche.zone = zone;
    }

    finir() {
        if (!this.ebauche) {
            return;
        }

        const { cadre, depart, element } = this.ebauche;
        let zone = this.ebauche.zone;

        element.remove();
        this.ebauche = null;

        // Un simple clic (ou un tracé minuscule) pose une zone standard,
        // centrée sur le point cliqué et ramenée dans la page.
        if (!zone || zone.l < 0.04 || zone.h < 0.015) {
            const l = 0.3;
            const h = 0.07;

            zone = {
                x: Math.min(Math.max(depart.x - l / 2, 0), 1 - l),
                y: Math.min(Math.max(depart.y - h / 2, 0), 1 - h),
                l,
                h,
            };
        }

        this.actif.zones.push({ page: Number(cadre.dataset.page), ...zone });
        this.synchroniser(true);
        this.dessiner();
    }

    // Position du pointeur dans la page, en fractions, bornée à la page.
    position(event, cadre) {
        const zone = cadre.getBoundingClientRect();
        const borner = (valeur) => Math.min(Math.max(valeur, 0), 1);

        return {
            x: borner((event.clientX - zone.left) / zone.width),
            y: borner((event.clientY - zone.top) / zone.height),
        };
    }

    // Écrit les zones (des signataires encore modifiables) dans le champ caché.
    synchroniser(modifie) {
        if (this.hasChampTarget) {
            this.champTarget.value = JSON.stringify(Object.fromEntries(
                this.signataires.filter((s) => !s.verrouille).map((s) => [s.id, s.zones]),
            ));
        }

        if (modifie && this.hasModifieTarget) {
            this.modifieTarget.hidden = false;
        }
    }

    // ------------------------------------------------------- Téléchargement

    // Assemble le PDF signé : signatures dans leurs zones, certificat en dernière page.
    async telecharger(event) {
        event.preventDefault();

        const bouton = event.currentTarget;
        bouton.disabled = true;

        try {
            const { PDFDocument, StandardFonts, rgb } = await import('pdf-lib');

            const origine = await (await fetch(this.fichierValue)).arrayBuffer();
            const pdf = await PDFDocument.load(origine);
            const pages = pdf.getPages();

            for (const signataire of this.signataires) {
                if (!signataire.signature) {
                    continue;
                }

                const image = await pdf.embedPng(signataire.signature);

                for (const zone of signataire.zones) {
                    const page = pages[zone.page - 1];

                    if (!page) {
                        continue;
                    }

                    // La signature tient dans la zone sans être déformée, centrée.
                    // Dans un PDF, l'origine est en bas à gauche.
                    const { width, height } = page.getSize();
                    const largeur = zone.l * width;
                    const hauteur = zone.h * height;
                    const echelle = Math.min(largeur / image.width, hauteur / image.height);
                    const l = image.width * echelle;
                    const h = image.height * echelle;

                    page.drawImage(image, {
                        x: zone.x * width + (largeur - l) / 2,
                        y: height - zone.y * height - hauteur + (hauteur - h) / 2,
                        width: l,
                        height: h,
                    });
                }
            }

            const normale = await pdf.embedFont(StandardFonts.Helvetica);
            const grasse = await pdf.embedFont(StandardFonts.HelveticaBold);
            const certificat = pdf.addPage([595.28, 841.89]);
            let y = 780;

            (this.donneesValue.certificat ?? []).forEach((ligne, index) => {
                certificat.drawText(this.imprimable(ligne), {
                    x: 56,
                    y,
                    size: index === 0 ? 16 : 10,
                    font: index === 0 ? grasse : normale,
                    color: rgb(0.06, 0.1, 0.13),
                });

                y -= index === 0 ? 26 : 16;
            });

            const octets = await pdf.save();
            const lien = document.createElement('a');
            lien.href = URL.createObjectURL(new Blob([octets], { type: 'application/pdf' }));
            lien.download = `${(this.donneesValue.nom || 'document').replace(/[^\p{L}\p{N} _-]+/gu, '').trim() || 'document'} - signé.pdf`;
            lien.click();
            setTimeout(() => URL.revokeObjectURL(lien.href), 10000);
        } catch (erreur) {
            console.error(erreur);
            this.dire("Le PDF signé n'a pas pu être assemblé (fichier protégé ou illisible). Les signatures restent consultables sur cette page.");
        } finally {
            bouton.disabled = false;
        }
    }

    // Les polices standard d'un PDF ne connaissent que le latin : le reste est remplacé.
    imprimable(texte) {
        return String(texte)
            .replace(/[‘’]/g, "'")
            .replace(/[“”]/g, '"')
            .replace(/[–—]/g, '-')
            .replace(/[^\x20-\x7E\xA0-\xFF]/g, '?');
    }

    dire(message) {
        if (this.hasEtatTarget) {
            this.etatTarget.textContent = message;
            this.etatTarget.hidden = false;
        }
    }
}
