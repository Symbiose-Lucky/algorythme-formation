import { Controller } from '@hotwired/stimulus';

/*
 * Cadre de signature manuscrite : on signe au doigt, au stylet ou à la
 * souris. À l'envoi du formulaire, le tracé est placé dans un champ caché
 * sous forme d'image PNG (data URL). Un cadre resté vide bloque l'envoi.
 *
 * <form data-controller="signature" data-action="submit->signature#envoyer">
 *     <canvas data-signature-target="cadre"></canvas>
 *     <input type="hidden" name="signature" data-signature-target="champ">
 *     <p data-signature-target="erreur" hidden>Signez dans le cadre.</p>
 *     <button type="button" data-action="signature#effacer">Effacer</button>
 * </form>
 */
export default class extends Controller {
    static targets = ['cadre', 'champ', 'erreur'];

    connect() {
        this.vide = true;
        this.trace = null;

        this.debuter = (event) => this.commencer(event);
        this.continuer = (event) => this.tracer(event);
        this.finir = () => (this.trace = null);

        const cadre = this.cadreTarget;
        cadre.addEventListener('pointerdown', this.debuter);
        cadre.addEventListener('pointermove', this.continuer);
        cadre.addEventListener('pointerup', this.finir);
        cadre.addEventListener('pointerleave', this.finir);
        cadre.addEventListener('pointercancel', this.finir);

        this.ajuster();
    }

    disconnect() {
        const cadre = this.cadreTarget;
        cadre.removeEventListener('pointerdown', this.debuter);
        cadre.removeEventListener('pointermove', this.continuer);
        cadre.removeEventListener('pointerup', this.finir);
        cadre.removeEventListener('pointerleave', this.finir);
        cadre.removeEventListener('pointercancel', this.finir);
    }

    // La surface de dessin suit la taille affichée et la densité de
    // l'écran : le trait reste net sur un téléphone.
    ajuster() {
        const cadre = this.cadreTarget;
        const zone = cadre.getBoundingClientRect();
        const densite = window.devicePixelRatio || 1;

        cadre.width = Math.round(zone.width * densite);
        cadre.height = Math.round(zone.height * densite);

        this.pinceau = cadre.getContext('2d');
        this.pinceau.scale(densite, densite);
        this.pinceau.lineWidth = 2.2;
        this.pinceau.lineCap = 'round';
        this.pinceau.lineJoin = 'round';
        this.pinceau.strokeStyle = '#0E1B20';

        this.vide = true;
    }

    commencer(event) {
        event.preventDefault();
        this.cadreTarget.setPointerCapture?.(event.pointerId);
        this.trace = this.position(event);

        // Un simple appui laisse un point.
        this.pinceau.beginPath();
        this.pinceau.arc(this.trace.x, this.trace.y, 1.1, 0, Math.PI * 2);
        this.pinceau.fillStyle = '#0E1B20';
        this.pinceau.fill();

        this.vide = false;
        this.masquerErreur();
    }

    tracer(event) {
        if (!this.trace) {
            return;
        }

        event.preventDefault();
        const point = this.position(event);

        this.pinceau.beginPath();
        this.pinceau.moveTo(this.trace.x, this.trace.y);
        this.pinceau.lineTo(point.x, point.y);
        this.pinceau.stroke();

        this.trace = point;
    }

    position(event) {
        const zone = this.cadreTarget.getBoundingClientRect();

        return { x: event.clientX - zone.left, y: event.clientY - zone.top };
    }

    effacer() {
        this.ajuster();
        this.champTarget.value = '';
    }

    envoyer(event) {
        if (this.vide) {
            event.preventDefault();
            event.stopImmediatePropagation();

            if (this.hasErreurTarget) {
                this.erreurTarget.hidden = false;
            }

            return;
        }

        this.champTarget.value = this.image();
    }

    // Le tracé seul, rogné à ses contours (avec une petite marge) : placé
    // dans une zone de signature, il la remplit au lieu d'y flotter au
    // milieu du vide laissé par le cadre.
    image() {
        const cadre = this.cadreTarget;
        const { width, height } = cadre;
        const pixels = this.pinceau.getImageData(0, 0, width, height).data;

        let gauche = width;
        let haut = height;
        let droite = -1;
        let bas = -1;

        for (let y = 0; y < height; y++) {
            for (let x = 0; x < width; x++) {
                // Quatrième octet de chaque pixel : son opacité.
                if (pixels[(y * width + x) * 4 + 3] > 0) {
                    gauche = Math.min(gauche, x);
                    droite = Math.max(droite, x);
                    haut = Math.min(haut, y);
                    bas = Math.max(bas, y);
                }
            }
        }

        if (droite < 0) {
            return cadre.toDataURL('image/png');
        }

        const marge = Math.round(6 * (window.devicePixelRatio || 1));
        gauche = Math.max(gauche - marge, 0);
        haut = Math.max(haut - marge, 0);
        droite = Math.min(droite + marge, width - 1);
        bas = Math.min(bas + marge, height - 1);

        const rogne = document.createElement('canvas');
        rogne.width = droite - gauche + 1;
        rogne.height = bas - haut + 1;
        rogne.getContext('2d').drawImage(cadre, gauche, haut, rogne.width, rogne.height, 0, 0, rogne.width, rogne.height);

        return rogne.toDataURL('image/png');
    }

    masquerErreur() {
        if (this.hasErreurTarget) {
            this.erreurTarget.hidden = true;
        }
    }
}
