import { Controller } from '@hotwired/stimulus';

/*
 * Liste de lignes d'un formulaire Symfony (CollectionType) : ajout et
 * retrait de lignes, ici les séances d'une session.
 *
 * Une ligne ajoutée est préremplie à la suite de la précédente : après un
 * matin, l'après-midi du même jour ; après un après-midi, le matin du jour
 * ouvré suivant. Saisir trois jours revient à choisir la première date
 * puis à cliquer.
 *
 * <div data-controller="collection"
 *      data-collection-index-value="2"
 *      data-collection-prototype-value="…__name__…">
 *     <div data-collection-target="liste">
 *         <div data-collection-target="ligne">… <button data-action="collection#retirer"> …</div>
 *     </div>
 *     <button type="button" data-action="collection#ajouter">Ajouter</button>
 * </div>
 */
export default class extends Controller {
    static targets = ['liste', 'ligne', 'vide'];
    static values = { index: Number, prototype: String };

    connect() {
        this.afficherVide();
    }

    ajouter() {
        const precedente = this.ligneTargets.at(-1);

        const gabarit = document.createElement('template');
        gabarit.innerHTML = this.prototypeValue.replaceAll('__name__', this.indexValue).trim();
        this.indexValue++;

        const ligne = gabarit.content.firstElementChild;
        this.listeTarget.append(ligne);

        this.preremplir(ligne, precedente);
        this.afficherVide();

        ligne.querySelector('input')?.focus();
    }

    retirer(event) {
        event.target.closest('[data-collection-target="ligne"]')?.remove();
        this.afficherVide();
    }

    preremplir(ligne, precedente) {
        const [jour, debut, fin] = this.champs(ligne);

        if (!precedente) {
            debut.value = '09:00';
            fin.value = '12:30';

            return;
        }

        const [jourPrecedent, , finPrecedente] = this.champs(precedente);
        const matin = finPrecedente.value !== '' && finPrecedente.value <= '13:00';

        if (matin) {
            jour.value = jourPrecedent.value;
            debut.value = '13:30';
            fin.value = '17:00';

            return;
        }

        jour.value = this.jourOuvreSuivant(jourPrecedent.value);
        debut.value = '09:00';
        fin.value = '12:30';
    }

    // Les trois champs d'une ligne, dans l'ordre : date, début, fin.
    champs(ligne) {
        return [
            ligne.querySelector('input[type="date"]'),
            ...ligne.querySelectorAll('input[type="time"]'),
        ];
    }

    jourOuvreSuivant(iso) {
        if (!iso) {
            return '';
        }

        // Midi UTC : aucun changement d'heure ne fait glisser la date.
        const date = new Date(`${iso}T12:00:00Z`);

        do {
            date.setUTCDate(date.getUTCDate() + 1);
        } while (date.getUTCDay() === 0 || date.getUTCDay() === 6);

        return date.toISOString().slice(0, 10);
    }

    afficherVide() {
        if (this.hasVideTarget) {
            this.videTarget.hidden = this.ligneTargets.length > 0;
        }
    }
}
